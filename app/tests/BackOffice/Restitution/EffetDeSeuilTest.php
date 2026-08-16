<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\EffetDeSeuil;
use PHPUnit\Framework\TestCase;

/**
 * Effet de seuil C (#27, §6) : le marché rémunère surtout le **franchissement de
 * la classe C**, pas la progression continue. Depuis la classe actuelle du bien,
 * on décompose le gain vers chaque classe publiée meilleure, pour rendre visible
 * que s'arrêter à D laisse la moitié du gain sur la table, et qu'aller au-delà de
 * C ne rapporte presque plus.
 */
final class EffetDeSeuilTest extends TestCase
{
    /**
     * @var array<string, float>
     */
    private const COEF_DPE = ['E' => -0.20, 'D' => 0.0, 'C' => 0.10, 'B' => 0.15];

    public function testLesPaliersVontDeLaClasseActuelleVersChaqueClasseMeilleure(): void
    {
        $effet = EffetDeSeuil::calculer(Dpe::E, Dpe::C, self::COEF_DPE, 100000);

        self::assertNotNull($effet);
        $classes = array_map(static fn ($p): string => $p->classeCible()->value, $effet->paliers());
        // Ordonnés du moins bon au meilleur : D, C, B (A absente du barème).
        self::assertSame(['D', 'C', 'B'], $classes);
    }

    public function testChaquePalierPorteLeGainEnEurosDepuisLaClasseActuelle(): void
    {
        $effet = EffetDeSeuil::calculer(Dpe::E, Dpe::C, self::COEF_DPE, 100000);

        self::assertNotNull($effet);
        // gain = prixBase × (exp(coef_cible − coef_actuel) − 1).
        // E→D : 100000×(e^0,20−1) ; E→C : ×(e^0,30−1) ; E→B : ×(e^0,35−1).
        $gains = array_map(static fn ($p): int => $p->gainEuros(), $effet->paliers());
        self::assertSame([22140, 34986, 41907], $gains);
    }

    public function testLaClasseCEstMarqueeCommeLeSeuil(): void
    {
        $effet = EffetDeSeuil::calculer(Dpe::E, Dpe::C, self::COEF_DPE, 100000);

        self::assertNotNull($effet);
        $seuils = array_values(array_filter($effet->paliers(), static fn ($p): bool => $p->estSeuil()));
        self::assertCount(1, $seuils);
        self::assertSame(Dpe::C, $seuils[0]->classeCible());
    }

    public function testLaClasseViseeParLeProEstMarquee(): void
    {
        // Le pro vise B : la ligne B est la cible, la ligne C reste le seuil.
        $effet = EffetDeSeuil::calculer(Dpe::E, Dpe::B, self::COEF_DPE, 100000);

        self::assertNotNull($effet);
        $cibles = array_values(array_filter($effet->paliers(), static fn ($p): bool => $p->estCible()));
        self::assertCount(1, $cibles);
        self::assertSame(Dpe::B, $cibles[0]->classeCible());
    }

    public function testSansClasseCPublieeIlNyAPasDEffetDeSeuil(): void
    {
        // C absente du barème : aucun seuil à franchir, on n'invente rien.
        $effet = EffetDeSeuil::calculer(Dpe::F, Dpe::D, ['F' => -0.25, 'E' => -0.20, 'D' => 0.0], 100000);

        self::assertNull($effet);
    }

    public function testUnSeulPalierAtteignableNeProduitPasDeTable(): void
    {
        // Depuis D, seule C est meilleure et publiée : un palier unique répète le
        // montant déjà affiché, sans le contraste qui fait le message.
        $effet = EffetDeSeuil::calculer(Dpe::D, Dpe::C, ['D' => 0.0, 'C' => 0.10], 100000);

        self::assertNull($effet);
    }

    public function testUneClasseActuelleNonPublieeNeProduitPasDeTable(): void
    {
        // Sans coefficient pour la classe de départ, aucun écart n'est calculable.
        $effet = EffetDeSeuil::calculer(Dpe::G, Dpe::C, self::COEF_DPE, 100000);

        self::assertNull($effet);
    }

    public function testLEffetDeSeuilSeFigeEtSeReluAlIdentique(): void
    {
        $effet = EffetDeSeuil::calculer(Dpe::E, Dpe::C, self::COEF_DPE, 100000);
        self::assertNotNull($effet);

        $relu = EffetDeSeuil::fromArray($effet->toArray());

        self::assertSame(
            array_map(static fn ($p): array => [$p->classeCible()->value, $p->gainEuros(), $p->estSeuil(), $p->estCible()], $effet->paliers()),
            array_map(static fn ($p): array => [$p->classeCible()->value, $p->gainEuros(), $p->estSeuil(), $p->estCible()], $relu->paliers()),
        );
    }
}

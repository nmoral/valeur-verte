<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Branding;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bornes de longueur des champs texte du branding (#267).
 *
 * Le branding est affiché sur une page publique hébergée **sur notre origine** :
 * ses champs texte sont bornés à la frontière, pas seulement par la colonne.
 *
 * Deux raisons distinctes :
 *  - `mentionsLegales` est une colonne **TEXT**, donc sans borne du tout : rien
 *    n'empêche aujourd'hui d'y stocker un mégaoctet, servi à chaque affichage ;
 *  - `nom`, `telephone`, `numeroRge` sont bornés par leur colonne, mais un
 *    dépassement part en **erreur SQL (500)** au lieu d'un refus lisible — le pro
 *    doit apprendre que sa saisie est trop longue, pas tomber sur une panne.
 */
final class BornesBrandingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function champsBornes(): iterable
    {
        yield 'nom' => ['nom', Branding::LONGUEUR_MAX_NOM];
        yield 'téléphone' => ['telephone', Branding::LONGUEUR_MAX_TELEPHONE];
        yield 'numéro RGE' => ['numeroRge', Branding::LONGUEUR_MAX_NUMERO_RGE];
        yield 'mentions légales' => ['mentionsLegales', Branding::LONGUEUR_MAX_MENTIONS_LEGALES];
    }

    #[DataProvider('champsBornes')]
    public function testUnChampALaBorneExacteEstAccepte(string $champ, int $borne): void
    {
        $branding = $this->creerAvec($champ, str_repeat('a', $borne));

        self::assertSame($borne, mb_strlen((string) $branding->{$champ}()));
    }

    #[DataProvider('champsBornes')]
    public function testUnChampQuiDepasseSaBorneEstRefuse(string $champ, int $borne): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->creerAvec($champ, str_repeat('a', $borne + 1));
    }

    public function testLeRefusNommeLeChampFautif(): void
    {
        // Le pro doit savoir *quel* champ reprendre : le message remonte tel quel
        // dans l'écran /marque.
        try {
            $this->creerAvec('telephone', str_repeat('0', Branding::LONGUEUR_MAX_TELEPHONE + 1));
            self::fail('Un téléphone trop long doit être refusé.');
        } catch (\InvalidArgumentException $erreur) {
            self::assertStringContainsString('téléphone', mb_strtolower($erreur->getMessage()));
        }
    }

    public function testLaBornePorteSurLesCaracteresPasSurLesOctets(): void
    {
        // « é » pèse deux octets : borner en octets amputerait une saisie légitime.
        $nom = str_repeat('é', Branding::LONGUEUR_MAX_NOM);

        self::assertSame($nom, $this->creerAvec('nom', $nom)->nom());
    }

    public function testUnChampBlancNestPasConcerneParLaBorne(): void
    {
        // Les champs vides sont ramenés à null en amont : rien à borner.
        $branding = Branding::creer(Couleur::depuisHex('#0E4A5C'), '', '', '', null, '');

        self::assertNull($branding->nom());
        self::assertNull($branding->mentionsLegales());
    }

    private function creerAvec(string $champ, string $valeur): Branding
    {
        return Branding::creer(
            Couleur::depuisHex('#0E4A5C'),
            'numeroRge' === $champ ? $valeur : null,
            'telephone' === $champ ? $valeur : null,
            'mentionsLegales' === $champ ? $valeur : null,
            null,
            'nom' === $champ ? $valeur : null,
        );
    }
}

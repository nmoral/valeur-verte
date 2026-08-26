<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\CommunesIndexables;
use App\Publication\LectureModelePublieDbal;
use App\Publication\RepertoireDepartements;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : l'énumération nationale des communes **indexables** (#365,
 * pour le sitemap) balaie ``coefficient_maille`` ⋈ ``commune`` en une passe, ne
 * retient que les communes ``Indexable`` au sens du gating #361 (les ``Repli``
 * restent crawlables mais hors sitemap), et rend les slugs département + commune
 * qui reconstruisent l'URL ``/valeur-verte/{dept}/{commune}``. Vide tant qu'aucun
 * millésime n'est publié.
 */
final class CommunesIndexablesTest extends KernelTestCase
{
    use ProvisionneModelePublie;

    private const COEF = '{"D":0.0,"C":0.11,"E":-0.19}';

    public function testNEnumereQueLesCommunesIndexables(): void
    {
        self::bootKernel();
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->semerMaille('33063', coefDpe: self::COEF); // locale, poids null → indexable
        $this->semerCommune('33065', 'Bruges', '33');
        $this->semerMaille('33065', coefDpe: self::COEF, poidsPooling: 0.3); // repli → hors sitemap
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        $slugs = $this->communes()->slugs();

        self::assertSame([['departement' => 'gironde', 'commune' => 'bordeaux']], $slugs);
    }

    public function testSansMillesimePublieAucuneCommune(): void
    {
        self::bootKernel();
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->semerMaille('33063', coefDpe: self::COEF);

        self::assertSame([], $this->communes()->slugs());
    }

    public function testUneCommuneDunDepartementSansSlugEstIgnoree(): void
    {
        // Un code département hors référentiel figé (DOM 971…) n'a pas de slug →
        // pas d'URL constructible, on l'écarte plutôt que de publier un 404.
        self::bootKernel();
        $this->semerCommune('97101', 'Les Abymes', '971');
        $this->semerMaille('97101', coefDpe: self::COEF);
        $this->deciderMillesime(2025, 'publie', '2026-02-10 09:30:00');

        self::assertSame([], $this->communes()->slugs());
    }

    private function communes(): CommunesIndexables
    {
        return new CommunesIndexables(
            new LectureModelePublieDbal($this->app()),
            $this->app(),
            new RepertoireDepartements(),
        );
    }
}

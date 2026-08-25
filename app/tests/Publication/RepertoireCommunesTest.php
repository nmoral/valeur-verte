<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\RepertoireCommunes;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : le répertoire des communes lit le schéma « data » en
 * lecture seule pour résoudre une URL SEO ``/valeur-verte/{departement}/{commune}``
 * (#359, SEO Vague 2 #338) vers sa maille (``code_insee``) et son rattachement EPCI.
 */
final class RepertoireCommunesTest extends KernelTestCase
{
    use ProvisionneReferentielCommunes;

    public function testResoutUneCommuneParSlugDepartementEtSlugCommune(): void
    {
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->semerRattachementEpci('33063', '243300316');

        $commune = $this->repertoire()->resoudre('gironde', 'bordeaux');

        self::assertNotNull($commune);
        self::assertSame('33063', $commune->codeInsee);
        self::assertSame('Bordeaux', $commune->nom);
        self::assertSame('33', $commune->codeDepartement);
        self::assertSame('243300316', $commune->codeEpci);
    }

    public function testLeSlugAccentueResoutVersLaMaille(): void
    {
        $this->semerCommune('33032', 'Bègles', '33');

        $commune = $this->repertoire()->resoudre('gironde', 'begles');

        self::assertNotNull($commune);
        self::assertSame('33032', $commune->codeInsee);
        // Rattachement EPCI absent en base => null, jamais une erreur.
        self::assertNull($commune->codeEpci);
    }

    public function testUnSlugDeDepartementInconnuNeResoutPas(): void
    {
        $this->semerCommune('33063', 'Bordeaux', '33');

        self::assertNull($this->repertoire()->resoudre('atlantide', 'bordeaux'));
    }

    public function testUnSlugDeCommuneInconnuDansLeBonDepartementNeResoutPas(): void
    {
        $this->semerCommune('33063', 'Bordeaux', '33');

        self::assertNull($this->repertoire()->resoudre('gironde', 'narnia'));
    }

    public function testLaResolutionEstBorneeAuDepartementDeLURL(): void
    {
        // Même slug de commune dans un autre département : l'URL du département de
        // la Gironde ne doit jamais servir la commune de la Manche.
        $this->semerCommune('50129', 'Créances', '50');

        self::assertNull($this->repertoire()->resoudre('gironde', 'creances'));
        self::assertNotNull($this->repertoire()->resoudre('manche', 'creances'));
    }

    public function testPourRetrouveUneCommuneParSonCodeInsee(): void
    {
        // Sens inverse de la résolution : d'un code_insee (sitemap #365, maillage
        // #364) vers la commune et son slug, pour reconstruire son URL.
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->semerRattachementEpci('33063', '243300316');

        $commune = $this->repertoire()->pour('33063');

        self::assertNotNull($commune);
        self::assertSame('Bordeaux', $commune->nom);
        self::assertSame('33', $commune->codeDepartement);
        self::assertSame('243300316', $commune->codeEpci);
        self::assertSame('bordeaux', $commune->slug());
    }

    public function testPourRenvoieNullSurUnCodeInconnu(): void
    {
        self::assertNull($this->repertoire()->pour('99999'));
    }

    public function testEnumereLesCommunesDUnDepartementEnOrdreDeterministe(): void
    {
        // Maillage descendant du hub départemental (#364) et énumération sitemap
        // (#365) : ordre par code_insee, stable et sans dépendance à la collation.
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->semerCommune('33032', 'Bègles', '33');
        $this->semerCommune('50129', 'Créances', '50');

        $communes = $this->repertoire()->communesDe('33');

        self::assertSame(
            ['33032', '33063'],
            array_map(static fn (\App\Publication\Commune $c): string => $c->codeInsee, $communes),
            'Seules les communes du département, ordonnées par code_insee.',
        );
    }

    public function testEnumereLesCommunesDUnEpci(): void
    {
        // Regroupement par EPCI (annuaire de la page hub EPCI #363).
        $this->semerCommune('33063', 'Bordeaux', '33');
        $this->semerRattachementEpci('33063', '243300316');
        $this->semerCommune('33039', 'Blanquefort', '33');
        $this->semerRattachementEpci('33039', '243300316');
        $this->semerCommune('33032', 'Bègles', '33');
        $this->semerRattachementEpci('33032', '200043016');

        $communes = $this->repertoire()->communesDeLEpci('243300316');

        self::assertSame(
            ['33039', '33063'],
            array_map(static fn (\App\Publication\Commune $c): string => $c->codeInsee, $communes),
            'Seules les communes rattachées à cet EPCI.',
        );
    }

    private function repertoire(): RepertoireCommunes
    {
        return new RepertoireCommunes($this->app(), new \App\Publication\RepertoireDepartements());
    }
}

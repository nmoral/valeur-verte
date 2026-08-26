<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\BackOffice\Restitution\PorteeEstimation;
use App\Publication\IndexabiliteCommune;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gating d'indexation SEO d'une page commune (#361, SEO Vague 2 #338) : une
 * commune n'est indexable que si son estimation est réellement locale et non
 * diluée dans le département par le pooling. Tout repli EPCI/départemental — ou
 * une maille locale trop rapprochée — produit un contenu quasi identique aux
 * communes voisines : on le retire de l'index pour éviter le *thin content* de
 * masse, sans couper le crawl du maillage montant (``noindex,follow``).
 */
final class IndexabiliteCommuneTest extends TestCase
{
    /**
     * La règle réutilise deux seuils moteur : le ``n ≥ 30`` (``min_local_sample``)
     * déjà encodé dans la portée LOCAL, et le seuil de pooling ``0.5``
     * (``SEUIL_RAPPROCHEMENT``, #261). Un ``poids_pooling`` absent = aucun pooling
     * = maille pleinement locale, donc indexable.
     */
    #[DataProvider('cas')]
    public function testLIndexabiliteDecouleDeLaPorteeEtDuPooling(PorteeEstimation $portee, ?float $poidsPooling, IndexabiliteCommune $attendue): void
    {
        self::assertSame($attendue, IndexabiliteCommune::depuis($portee, $poidsPooling));
    }

    /**
     * @return iterable<string, array{PorteeEstimation, float|null, IndexabiliteCommune}>
     */
    public static function cas(): iterable
    {
        // Indexable : portée locale et pooling au-dessus du seuil (ou absent).
        yield 'locale sans pooling' => [PorteeEstimation::Local, null, IndexabiliteCommune::Indexable];
        yield 'locale pooling plein' => [PorteeEstimation::Local, 1.0, IndexabiliteCommune::Indexable];
        yield 'locale pooling à la borne 0.5' => [PorteeEstimation::Local, 0.5, IndexabiliteCommune::Indexable];

        // Repli : maille locale mais trop rapprochée du département (pooling < 0.5).
        yield 'locale juste sous le seuil' => [PorteeEstimation::Local, 0.49, IndexabiliteCommune::Repli];
        yield 'locale fortement rapprochée' => [PorteeEstimation::Local, 0.0, IndexabiliteCommune::Repli];

        // Repli : tout cran de repli géographique, quel que soit le pooling.
        yield 'repli EPCI' => [PorteeEstimation::Epci, null, IndexabiliteCommune::Repli];
        yield 'repli EPCI malgré pooling élevé' => [PorteeEstimation::Epci, 0.9, IndexabiliteCommune::Repli];
        yield 'repli départemental' => [PorteeEstimation::Departemental, null, IndexabiliteCommune::Repli];
        yield 'repli départemental malgré pooling élevé' => [PorteeEstimation::Departemental, 0.9, IndexabiliteCommune::Repli];
    }

    public function testLaDirectiveRobotsEstPreteAPoserDansLaMetaTag(): void
    {
        self::assertSame('index,follow', IndexabiliteCommune::Indexable->directiveRobots());
        self::assertSame('noindex,follow', IndexabiliteCommune::Repli->directiveRobots());
    }

    public function testEstIndexableDistingueLesDeuxCas(): void
    {
        self::assertTrue(IndexabiliteCommune::Indexable->estIndexable());
        self::assertFalse(IndexabiliteCommune::Repli->estIndexable());
    }
}

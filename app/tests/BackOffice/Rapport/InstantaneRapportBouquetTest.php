<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Rapport\InstantaneRapport;
use PHPUnit\Framework\TestCase;

/**
 * Le bouquet de travaux choisi en rendez-vous (#29) est figé dans l'instantané
 * (#33) : choisi à la saisie, il fait partie du contexte reproductible du rapport
 * et se relit à l'identique après sérialisation JSONB.
 */
final class InstantaneRapportBouquetTest extends TestCase
{
    public function testLeBouquetChoisiEstFigeEtRelu(): void
    {
        $instantane = InstantaneRapportMother::creer();
        $avecBouquet = InstantaneRapport::creer(
            $instantane->saisie(),
            $instantane->restitution(),
            $instantane->contexte(),
            $instantane->branding(),
            $instantane->reference(),
            $instantane->genereLe(),
            Bouquet::isolationSeule(),
        );

        $relu = InstantaneRapport::fromArray($avecBouquet->toArray());

        self::assertNotNull($relu->bouquet());
        self::assertEquals(Bouquet::isolationSeule(), $relu->bouquet());
    }

    public function testSansBouquetLInstantaneNEnPorteAucun(): void
    {
        $instantane = InstantaneRapportMother::creer();

        self::assertNull($instantane->bouquet());
        self::assertNull(InstantaneRapport::fromArray($instantane->toArray())->bouquet());
    }
}

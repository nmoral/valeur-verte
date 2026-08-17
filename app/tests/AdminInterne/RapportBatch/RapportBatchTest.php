<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\RapportBatch;

use App\AdminInterne\RapportBatch\RapportBatch;
use PHPUnit\Framework\TestCase;

/**
 * Rapport de batch archivé (#131) : objet de lecture pour l'admin. Une exécution
 * d'ingestion ou de calcul ; les métriques de l'autre phase sont absentes. La durée
 * et le taux d'appariement sont dérivés, jamais stockés en double.
 */
final class RapportBatchTest extends TestCase
{
    public function testUnRapportDIngestionExposeSaDureeEtSonTauxDAppariement(): void
    {
        $rapport = RapportBatch::ingestion(
            id: 7,
            departement: '31',
            millesime: 2024,
            debut: new \DateTimeImmutable('2026-08-17 03:00:00'),
            fin: new \DateTimeImmutable('2026-08-17 04:30:00'),
            communesTraitees: 580,
            mutationsInserees: 12000,
            ventesAppariees: 9000,
            communesReferencees: 586,
            contoursCharges: 585,
            epciCharges: 40,
            sansDonnees: 35,
            echecs: ['31999'],
        );

        self::assertTrue($rapport->estIngestion());
        self::assertFalse($rapport->estCalcul());
        self::assertSame(5400, $rapport->dureeSecondes());
        self::assertSame(0.75, $rapport->tauxAppariement());
        self::assertSame(['31999'], $rapport->echecs());
    }

    public function testLeTauxDAppariementEstNulSansAucuneVente(): void
    {
        $rapport = RapportBatch::ingestion(
            id: 1,
            departement: '09',
            millesime: 2024,
            debut: new \DateTimeImmutable('2026-08-17 03:00:00'),
            fin: new \DateTimeImmutable('2026-08-17 03:00:10'),
            communesTraitees: 0,
            mutationsInserees: 0,
            ventesAppariees: 0,
            communesReferencees: 0,
            contoursCharges: 0,
            epciCharges: 0,
            sansDonnees: 0,
            echecs: [],
        );

        self::assertNull($rapport->tauxAppariement());
    }

    public function testUnRapportDeCalculPorteLesEcartsEtNAPasDeTauxDAppariement(): void
    {
        $rapport = RapportBatch::calcul(
            id: 8,
            departement: '31',
            millesime: 2024,
            debut: new \DateTimeImmutable('2026-08-17 05:00:00'),
            fin: new \DateTimeImmutable('2026-08-17 06:00:00'),
            communesCalculees: 500,
            refus: 60,
            rejetsInvariants: 3,
            alertesBord: 2,
            inversionsDpe: 1,
            ecartsBlocages: 4,
            ecartsAlertes: 12,
            ordreCanonique: 'id_mutation',
            echecs: [],
        );

        self::assertTrue($rapport->estCalcul());
        self::assertFalse($rapport->estIngestion());
        self::assertSame(3600, $rapport->dureeSecondes());
        self::assertSame(500, $rapport->communesCalculees());
        self::assertSame(4, $rapport->ecartsBlocages());
        self::assertSame(12, $rapport->ecartsAlertes());
        self::assertNull($rapport->tauxAppariement());
    }
}

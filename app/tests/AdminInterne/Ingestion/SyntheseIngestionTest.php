<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Ingestion;

use App\AdminInterne\Ingestion\EtatDernierImport;
use App\AdminInterne\Ingestion\SyntheseIngestion;
use App\AdminInterne\RapportBatch\RapportBatch;
use PHPUnit\Framework\TestCase;

/**
 * Synthèse de l'état des imports (#46) : ce que l'exploitant lit d'un coup d'œil
 * au-dessus du tableau par département — depuis quand la base n'a pas bougé,
 * combien de départements sont couverts, lesquels demandent un regard.
 */
final class SyntheseIngestionTest extends TestCase
{
    public function testElleDateLaBaseParLImportLePlusRecent(): void
    {
        $synthese = SyntheseIngestion::depuis([
            self::etat('09', importeLe: '2026-08-10 12:00:00'),
            self::etat('31', importeLe: '2026-08-17 04:30:00'),
            self::etat('12', importeLe: '2026-07-01 09:00:00'),
        ]);

        self::assertEquals(new \DateTimeImmutable('2026-08-17 04:30:00'), $synthese->dernierImport());
        self::assertSame(3, $synthese->departementsCouverts());
    }

    public function testUneBaseVideNAPasDeDernierImport(): void
    {
        $synthese = SyntheseIngestion::depuis([]);

        self::assertNull($synthese->dernierImport());
        self::assertSame(0, $synthese->departementsCouverts());
        self::assertNull($synthese->tauxAppariement());
    }

    public function testLeTauxDAppariementGlobalPondereParLeVolume(): void
    {
        // Un petit département bien apparié (900/1000) et un gros mal apparié
        // (300/2000) : la moyenne des taux dirait 52,5 %, le taux global dit
        // 1200/3000 = 40 %. C'est le second qui décrit la base.
        $synthese = SyntheseIngestion::depuis([
            self::etat('31', mutationsInserees: 1000, ventesAppariees: 900),
            self::etat('09', mutationsInserees: 2000, ventesAppariees: 300),
        ]);

        self::assertSame(0.4, $synthese->tauxAppariement());
        self::assertSame(3000, $synthese->mutationsInserees());
    }

    public function testElleCompteLesCommunesEnEchecDeTousLesDepartements(): void
    {
        $synthese = SyntheseIngestion::depuis([
            self::etat('31', echecs: ['31999', '31555']),
            self::etat('09', echecs: ['09111']),
        ]);

        self::assertSame(3, $synthese->communesEnEchec());
    }

    public function testElleIsoleLesDepartementsQuiDemandentUnRegard(): void
    {
        $maintenant = new \DateTimeImmutable('2026-08-23');
        $synthese = SyntheseIngestion::depuis([
            self::etat('31', mutationsInserees: 1000, ventesAppariees: 900, mutationLaPlusRecente: '2026-06-30'),
            self::etat('09', mutationsInserees: 1000, ventesAppariees: 100, mutationLaPlusRecente: '2026-06-30'),
            self::etat('12', mutationsInserees: 1000, ventesAppariees: 900, mutationLaPlusRecente: '2024-01-01'),
        ]);

        $alertes = $synthese->departementsEnAlerte($maintenant);

        self::assertCount(2, $alertes);
        self::assertSame(['09', '12'], array_map(static fn (EtatDernierImport $e): string => $e->departement(), $alertes));
    }

    public function testLesDepartementsSontRangesDansLOrdreDeLeurCode(): void
    {
        $synthese = SyntheseIngestion::depuis([
            self::etat('31'),
            self::etat('09'),
            self::etat('2A'),
        ]);

        self::assertSame(['09', '2A', '31'], array_map(
            static fn (EtatDernierImport $e): string => $e->departement(),
            $synthese->departements(),
        ));
    }

    /**
     * @param list<string> $echecs
     */
    private static function etat(
        string $departement,
        string $importeLe = '2026-08-17 04:30:00',
        int $mutationsInserees = 1000,
        int $ventesAppariees = 800,
        array $echecs = [],
        ?string $mutationLaPlusRecente = null,
    ): EtatDernierImport {
        return new EtatDernierImport(RapportBatch::ingestion(
            id: 7,
            departement: $departement,
            millesime: 2024,
            debut: new \DateTimeImmutable($importeLe),
            fin: new \DateTimeImmutable($importeLe),
            communesTraitees: 580,
            mutationsInserees: $mutationsInserees,
            ventesAppariees: $ventesAppariees,
            communesReferencees: 586,
            contoursCharges: 585,
            epciCharges: 40,
            sansDonnees: 35,
            echecs: $echecs,
            mutationLaPlusRecente: null === $mutationLaPlusRecente ? null : new \DateTimeImmutable($mutationLaPlusRecente),
        ));
    }
}

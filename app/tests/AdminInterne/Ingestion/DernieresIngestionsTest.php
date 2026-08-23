<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Ingestion;

use App\AdminInterne\RapportBatch\LectureRapportsBatch;
use App\AdminInterne\RapportBatch\RapportBatch;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : l'état du dernier import (#46) ne retient qu'**une ligne par
 * département** — la dernière ingestion archivée. Il lit la même table que le
 * rapport de batch (#131, ``data.rapport_batch``, lecture seule), pas une donnée
 * recalculée de son côté.
 */
final class DernieresIngestionsTest extends KernelTestCase
{
    private Connection $app;
    private Connection $prive;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->app = $manager->getConnection();

        $params = $this->app->getParams();
        $hote = \is_string($params['host'] ?? null) ? $params['host'] : '127.0.0.1';
        $port = is_numeric($params['port'] ?? null) ? (int) $params['port'] : 5432;
        $base = $this->app->getDatabase() ?? 'valeur_verte_test';
        $dsn = \sprintf('postgresql://valeur_verte:valeur_verte@%s:%d/%s?serverVersion=16&charset=utf8', $hote, $port, $base);
        $this->prive = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($dsn));
        $this->provisionner();
    }

    protected function tearDown(): void
    {
        $this->prive->executeStatement('DELETE FROM data.rapport_batch');
        $this->prive->close();
        parent::tearDown();
    }

    public function testIlNeRetientQueLaDerniereIngestionDeChaqueDepartement(): void
    {
        $lecture = new LectureRapportsBatch($this->app);

        $imports = $lecture->dernieresIngestions();

        self::assertCount(2, $imports);
        self::assertSame(['09', '31'], array_map(static fn (RapportBatch $r): string => $r->departement(), $imports));
        // 31 a été ingéré deux fois : c'est le millésime 2024 (le plus récent) qui parle.
        self::assertSame(2024, $imports[1]->millesime());
        self::assertSame(12000, $imports[1]->mutationsInserees());
        self::assertEquals(new \DateTimeImmutable('2026-06-30'), $imports[1]->mutationLaPlusRecente());
    }

    public function testUnRapportDeCalculNEstPasUnImport(): void
    {
        $lecture = new LectureRapportsBatch($this->app);

        foreach ($lecture->dernieresIngestions() as $import) {
            self::assertTrue($import->estIngestion());
        }
    }

    private function provisionner(): void
    {
        $this->prive->executeStatement('CREATE SCHEMA IF NOT EXISTS data');

        $racine = getenv('VV_DATA_DIR') ?: \dirname(__DIR__, 4).'/data';
        $migration = file_get_contents($racine.'/src/valeur_verte_data/db/migrations/014_create_rapport_batch.sql');
        self::assertIsString($migration, 'La migration 014 doit être lisible depuis les tests.');
        $this->prive->executeStatement(str_replace('valeur_verte_app', 'app', $migration));
        $dat5 = file_get_contents($racine.'/src/valeur_verte_data/db/migrations/016_rapport_batch_fraicheur.sql');
        self::assertIsString($dat5, 'La migration 016 doit être lisible depuis les tests.');
        $this->prive->executeStatement($dat5);
        $this->prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $this->prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $this->prive->executeStatement('DELETE FROM data.rapport_batch');

        // 31 ingéré deux fois (2023 puis 2024), 09 une fois, plus un calcul qui ne
        // doit pas passer pour un import.
        $this->prive->executeStatement(
            "INSERT INTO data.rapport_batch
                (phase, departement, millesime, debut, fin,
                 communes_traitees, communes_referencees, mutations_inserees, ventes_appariees,
                 contours_charges, epci_charges, sans_donnees, echecs, mutation_la_plus_recente)
             VALUES
                ('ingestion', '31', 2023, '2025-08-17 03:00:00', '2025-08-17 04:30:00',
                 580, 586, 9000, 6000, 585, 40, 35, '[]', '2025-06-30'),
                ('ingestion', '31', 2024, '2026-08-17 03:00:00', '2026-08-17 04:30:00',
                 580, 586, 12000, 9000, 585, 40, 35, '[\"31999\"]', '2026-06-30'),
                ('ingestion', '09', 2024, '2026-08-16 03:00:00', '2026-08-16 03:40:00',
                 320, 332, 4000, 1200, 330, 20, 12, '[]', '2026-05-31')",
        );
        $this->prive->executeStatement(
            "INSERT INTO data.rapport_batch
                (phase, departement, millesime, debut, fin,
                 communes_calculees, refus, rejets_invariants, alertes_bord, inversions_dpe,
                 ecarts_blocages, ecarts_alertes, ordre_canonique, echecs)
             VALUES
                ('calcul', '31', 2024, '2026-08-18 05:00:00', '2026-08-18 06:00:00',
                 500, 60, 3, 2, 1, 4, 12, 'id_mutation', '[]')",
        );
    }
}

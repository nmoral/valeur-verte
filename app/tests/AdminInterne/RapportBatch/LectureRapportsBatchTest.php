<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\RapportBatch;

use App\AdminInterne\RapportBatch\LectureRapportsBatch;
use App\AdminInterne\RapportBatch\RapportBatch;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : le lecteur lit ``data.rapport_batch`` (schéma « data »,
 * lecture seule pour « app ») et reconstitue les rapports archivés. Le schéma data
 * est provisionné/semé via une connexion privilégiée en rejouant la **vraie
 * migration 014**, comme en production.
 */
final class LectureRapportsBatchTest extends KernelTestCase
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

    public function testIlLitLesRapportsLesPlusRecentsDAbord(): void
    {
        $lecture = new LectureRapportsBatch($this->app);

        $rapports = $lecture->recents(50);

        self::assertCount(2, $rapports);
        // Le calcul (05:00) est plus récent que l'ingestion (03:00) → en tête.
        self::assertTrue($rapports[0]->estCalcul());
        self::assertSame(500, $rapports[0]->communesCalculees());
        self::assertSame(4, $rapports[0]->ecartsBlocages());
        self::assertTrue($rapports[1]->estIngestion());
        self::assertSame('31', $rapports[1]->departement());
        self::assertSame(0.75, $rapports[1]->tauxAppariement());
        self::assertSame(['31999'], $rapports[1]->echecs());
    }

    public function testIlRetrouveUnRapportParSonIdentifiant(): void
    {
        $lecture = new LectureRapportsBatch($this->app);
        $ingestion = $lecture->recents(50)[1];

        $retrouve = $lecture->parId($ingestion->id());

        self::assertInstanceOf(RapportBatch::class, $retrouve);
        self::assertSame($ingestion->id(), $retrouve->id());
        self::assertTrue($retrouve->estIngestion());
        self::assertSame(12000, $retrouve->mutationsInserees());
    }

    public function testUnIdentifiantInconnuNeRendRien(): void
    {
        $lecture = new LectureRapportsBatch($this->app);

        self::assertNull($lecture->parId(999999));
    }

    private function provisionner(): void
    {
        $this->prive->executeStatement('CREATE SCHEMA IF NOT EXISTS data');

        $racine = getenv('VV_DATA_DIR') ?: \dirname(__DIR__, 4).'/data';
        $migration = file_get_contents($racine.'/src/valeur_verte_data/db/migrations/014_create_rapport_batch.sql');
        self::assertIsString($migration, 'La migration 014 doit être lisible depuis les tests.');
        $this->prive->executeStatement(str_replace('valeur_verte_app', 'app', $migration));
        $this->prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $this->prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $this->prive->executeStatement('DELETE FROM data.rapport_batch');

        $this->prive->executeStatement(
            "INSERT INTO data.rapport_batch
                (phase, departement, millesime, debut, fin,
                 communes_traitees, communes_referencees, mutations_inserees, ventes_appariees,
                 contours_charges, epci_charges, sans_donnees, echecs)
             VALUES
                ('ingestion', '31', 2024, '2026-08-17 03:00:00', '2026-08-17 04:30:00',
                 580, 586, 12000, 9000, 585, 40, 35, '[\"31999\"]')",
        );
        $this->prive->executeStatement(
            "INSERT INTO data.rapport_batch
                (phase, departement, millesime, debut, fin,
                 communes_calculees, refus, rejets_invariants, alertes_bord, inversions_dpe,
                 ecarts_blocages, ecarts_alertes, ordre_canonique, echecs)
             VALUES
                ('calcul', '31', 2024, '2026-08-17 05:00:00', '2026-08-17 06:00:00',
                 500, 60, 3, 2, 1, 4, 12, 'id_mutation', '[]')",
        );
    }
}

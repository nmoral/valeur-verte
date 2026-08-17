<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\RapportBatch;

use App\Security\User;
use App\Security\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'écran admin des rapports de batch (#131) : liste chronologique et fiche de
 * détail, lues dans ``data.rapport_batch`` (lecture seule). Zone « admin. »,
 * ROLE_ADMIN. Le schéma data est semé via une connexion privilégiée.
 */
final class RapportsBatchControllerTest extends WebTestCase
{
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    private ?Connection $prive = null;

    protected function tearDown(): void
    {
        if (null !== $this->prive) {
            $this->prive->executeStatement('DELETE FROM data.rapport_batch');
            $this->prive->close();
            $this->prive = null;
        }
        parent::tearDown();
    }

    public function testLaPageExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rapports-batch');

        self::assertResponseRedirects('/login');
    }

    public function testLaListeAfficheLesRapportsArchives(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semer($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rapports-batch');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rapports de batch');
        // Le calcul (05:00) est plus récent que l'ingestion (03:00) → en tête.
        self::assertSelectorTextContains('tbody tr:first-child', 'Calcul');
        self::assertSelectorTextContains('tbody', '31');
        self::assertSelectorTextContains('.pager', '2 élément(s)');
    }

    public function testLaFicheDetailleUnRapportDIngestion(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semer($client);
        $idBrut = $this->prive?->fetchOne("SELECT id FROM data.rapport_batch WHERE phase = 'ingestion'");
        $id = is_numeric($idBrut) ? (int) $idBrut : 0;

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rapports-batch/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rapport de batch');
        // Volumétrie et taux d'appariement (9000/12000 = 75 %) restitués.
        self::assertSelectorTextContains('body', '12 000');
        self::assertSelectorTextContains('body', '75');
    }

    public function testUneFicheInconnueRend404(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semer($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/rapports-batch/999999');

        self::assertResponseStatusCodeSame(404);
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
    }

    private function semer(KernelBrowser $client): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $app = $manager->getConnection();
        $params = $app->getParams();
        $hote = \is_string($params['host'] ?? null) ? $params['host'] : '127.0.0.1';
        $port = is_numeric($params['port'] ?? null) ? (int) $params['port'] : 5432;
        $base = $app->getDatabase() ?? 'valeur_verte_test';
        $dsn = \sprintf('postgresql://valeur_verte:valeur_verte@%s:%d/%s?serverVersion=16&charset=utf8', $hote, $port, $base);
        $this->prive = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($dsn));

        $racine = getenv('VV_DATA_DIR') ?: \dirname(__DIR__, 4).'/data';
        $migration = file_get_contents($racine.'/src/valeur_verte_data/db/migrations/014_create_rapport_batch.sql');
        self::assertIsString($migration);
        $this->prive->executeStatement(str_replace('valeur_verte_app', 'app', $migration));
        $this->prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $this->prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $this->prive->executeStatement('DELETE FROM data.rapport_batch');
        $this->prive->executeStatement(
            "INSERT INTO data.rapport_batch
                (phase, departement, millesime, debut, fin,
                 communes_traitees, communes_referencees, mutations_inserees, ventes_appariees,
                 contours_charges, epci_charges, sans_donnees, echecs)
             VALUES ('ingestion', '31', 2024, '2026-08-17 03:00:00', '2026-08-17 04:30:00',
                 580, 586, 12000, 9000, 585, 40, 35, '[\"31999\"]')",
        );
        $this->prive->executeStatement(
            "INSERT INTO data.rapport_batch
                (phase, departement, millesime, debut, fin,
                 communes_calculees, refus, rejets_invariants, alertes_bord, inversions_dpe,
                 ecarts_blocages, ecarts_alertes, ordre_canonique, echecs)
             VALUES ('calcul', '31', 2024, '2026-08-17 05:00:00', '2026-08-17 06:00:00',
                 500, 60, 3, 2, 1, 4, 12, 'id_mutation', '[]')",
        );
    }
}

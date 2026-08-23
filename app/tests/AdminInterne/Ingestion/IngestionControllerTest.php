<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Ingestion;

use App\Security\User;
use App\Security\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'écran « état des imports » (#46) : une ligne par département, la dernière
 * ingestion archivée (#131), avec les deux signaux qui décident d'un regard —
 * appariement DVF ↔ DPE (DAT-3) et fraîcheur des sources (DAT-5). Zone « admin. »,
 * ROLE_ADMIN, lecture seule sur ``data.rapport_batch``.
 *
 * Les dates sont semées **relativement à maintenant** : un écran de fraîcheur qui
 * se testerait sur des dates figées deviendrait faux avec le temps.
 */
final class IngestionControllerTest extends WebTestCase
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

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/ingestion');

        self::assertResponseRedirects('/login');
    }

    public function testElleMontreUneLigneParDepartementAvecSaVolumetrieEtSonAppariement(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semer();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/ingestion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'État des imports');
        // 09 d'abord (rangés par code), puis 31 — une seule ligne pour 31, ingéré deux fois.
        self::assertSelectorTextContains('tbody tr:first-child', '09');
        self::assertSelectorTextContains('tbody tr:last-child', '31');
        self::assertCount(2, $client->getCrawler()->filter('tbody tr'));
        // Volumétrie et taux d'appariement du 31 : 9 000 / 12 000 = 75 %.
        self::assertSelectorTextContains('tbody tr:last-child', '12 000');
        self::assertSelectorTextContains('tbody tr:last-child', '75');
    }

    public function testUnAppariementEffondreEstSignale(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semer();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/ingestion');

        // 09 : 1 200 / 4 000 = 30 %, sous le seuil de 60 % de DAT-3.
        self::assertSelectorTextContains('tbody tr:first-child', '30');
        self::assertSelectorExists('tbody tr.est-alerte:first-child');
        // Le 31, bien apparié et frais, reste au calme.
        self::assertCount(1, $client->getCrawler()->filter('tbody tr.est-alerte'));
    }

    public function testUneSourcePerimeeEstSignalee(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semer();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/ingestion');

        // 09 a été semé avec une mutation vieille de trois ans (DAT-5 : 12 mois).
        self::assertSelectorTextContains('tbody tr:first-child', 'périmée');
    }

    public function testElleSitueLaVolumetrieFaceAuMillesimePrecedent(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semer();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/ingestion');

        // 31 : 12 000 ventes en 2024 contre 10 000 en 2023, soit +20 % — dans la
        // fourchette de DAT-2, donc affiché sans alerte.
        self::assertSelectorTextContains('tbody tr:last-child', '+20');
        self::assertCount(1, $client->getCrawler()->filter('tbody tr.est-alerte'));
        // 09 n'a pas de millésime antérieur : rien à comparer.
        self::assertSelectorTextContains('tbody tr:first-child', 'premier import');
    }

    public function testElleListeLesCommunesEnEchec(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semer();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/ingestion');

        self::assertSelectorTextContains('tbody tr:last-child', '31999');
    }

    public function testElleRenvoieVersLeRapportDeBatchArchive(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semer();
        $idBrut = $this->prive?->fetchOne("SELECT id FROM data.rapport_batch WHERE departement = '31' AND millesime = 2024");
        $id = is_numeric($idBrut) ? (int) $idBrut : 0;

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/ingestion');

        self::assertSelectorExists('tbody tr:last-child a[href="/rapports-batch/'.$id.'"]');
    }

    public function testUneBaseSansAucuneIngestionLeDitPlutotQueDAfficherUnTableauVide(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->vider();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/ingestion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune ingestion');
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
    }

    private function prive(): Connection
    {
        if (null !== $this->prive) {
            return $this->prive;
        }

        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $app = $manager->getConnection();
        $params = $app->getParams();
        $hote = \is_string($params['host'] ?? null) ? $params['host'] : '127.0.0.1';
        $port = is_numeric($params['port'] ?? null) ? (int) $params['port'] : 5432;
        $base = $app->getDatabase() ?? 'valeur_verte_test';
        $dsn = \sprintf('postgresql://valeur_verte:valeur_verte@%s:%d/%s?serverVersion=16&charset=utf8', $hote, $port, $base);

        return $this->prive = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($dsn));
    }

    private function vider(): void
    {
        $prive = $this->prive();
        $prive->executeStatement('CREATE SCHEMA IF NOT EXISTS data');

        $racine = getenv('VV_DATA_DIR') ?: \dirname(__DIR__, 4).'/data';
        $migration = file_get_contents($racine.'/src/valeur_verte_data/db/migrations/014_create_rapport_batch.sql');
        self::assertIsString($migration, 'La migration 014 doit être lisible depuis les tests.');
        $prive->executeStatement(str_replace('valeur_verte_app', 'app', $migration));
        $dat5 = file_get_contents($racine.'/src/valeur_verte_data/db/migrations/016_rapport_batch_fraicheur.sql');
        self::assertIsString($dat5, 'La migration 016 doit être lisible depuis les tests.');
        $prive->executeStatement($dat5);
        $prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $prive->executeStatement('DELETE FROM data.rapport_batch');
    }

    private function semer(): void
    {
        $this->vider();
        $recent = (new \DateTimeImmutable('-2 months'))->format('Y-m-d');
        $perime = (new \DateTimeImmutable('-3 years'))->format('Y-m-d');

        $this->prive()->executeStatement(
            "INSERT INTO data.rapport_batch
                (phase, departement, millesime, debut, fin,
                 communes_traitees, communes_referencees, mutations_inserees, ventes_appariees,
                 contours_charges, epci_charges, sans_donnees, echecs, mutation_la_plus_recente)
             VALUES
                ('ingestion', '31', 2023, '2025-08-17 03:00:00', '2025-08-17 04:30:00',
                 580, 586, 10000, 6000, 585, 40, 35, '[]', '2025-06-30'),
                ('ingestion', '31', 2024, '2026-08-17 03:00:00', '2026-08-17 04:30:00',
                 580, 586, 12000, 9000, 585, 40, 35, '[\"31999\"]', '".$recent."'),
                ('ingestion', '09', 2024, '2026-08-16 03:00:00', '2026-08-16 03:40:00',
                 320, 332, 4000, 1200, 330, 20, 12, '[]', '".$perime."')",
        );
    }
}

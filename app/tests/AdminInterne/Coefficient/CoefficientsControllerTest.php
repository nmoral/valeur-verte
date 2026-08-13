<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Coefficient;

use App\Security\User;
use App\Security\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CoefficientsControllerTest extends WebTestCase
{
    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    private ?Connection $prive = null;

    protected function tearDown(): void
    {
        if (null !== $this->prive) {
            $this->prive->executeStatement('DELETE FROM data.coefficient_maille');
            $this->prive->close();
            $this->prive = null;
        }
        parent::tearDown();
    }

    public function testLaPageCoefficientsExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/coefficients');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageRemonteLesMaillesSignaleesEnTete(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $this->semerCoefficients($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/coefficients');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Supervision des coefficients');
        // La maille signalée (erreur médiane 42 %) remonte en première ligne.
        self::assertSelectorTextContains('tbody tr:first-child', '32002');
        self::assertSelectorTextContains('tbody tr:first-child', 'erreur_mediane_elevee');
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
    }

    private function semerCoefficients(KernelBrowser $client): void
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

        $this->prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.coefficient_maille (
                code_insee      CHAR(5) PRIMARY KEY,
                millesime       SMALLINT NOT NULL,
                rayon_retenu_km SMALLINT NOT NULL,
                n_transactions  INTEGER NOT NULL,
                erreur_mediane  NUMERIC(5,2),
                coef_dpe        JSONB NOT NULL,
                alerte          TEXT
            )'
        );
        $this->prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $this->prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $this->prive->executeStatement('DELETE FROM data.coefficient_maille');
        $this->prive->executeStatement(
            "INSERT INTO data.coefficient_maille (code_insee, millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe, alerte) VALUES
             ('32001', 2024, 10, 134, 17.00, '{\"D\":0.0,\"C\":0.10,\"E\":-0.20}', NULL),
             ('32002', 2024, 20, 90, 42.00, '{\"D\":0.0,\"C\":0.10,\"E\":-0.20}', NULL)"
        );
    }
}

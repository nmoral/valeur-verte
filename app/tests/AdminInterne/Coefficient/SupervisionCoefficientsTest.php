<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Coefficient;

use App\AdminInterne\Coefficient\SeuilsSupervision;
use App\AdminInterne\Coefficient\SeuilsSupervisionRepository;
use App\AdminInterne\Coefficient\SupervisionCoefficients;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : la supervision lit ``data.coefficient_maille`` (schéma
 * « data », lecture seule) et diagnostique chaque maille. Le schéma data est
 * provisionné/semé via une connexion privilégiée, comme en production.
 */
final class SupervisionCoefficientsTest extends KernelTestCase
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
        $this->prive->executeStatement('DELETE FROM data.coefficient_maille');
        $this->prive->close();
        parent::tearDown();
    }

    public function testListeLesMaillesAvecLeurDiagnostic(): void
    {
        $supervision = new SupervisionCoefficients($this->app, $this->seuils());

        $mailles = $supervision->mailles();

        self::assertCount(3, $mailles);
        $parCode = [];
        foreach ($mailles as $maille) {
            $parCode[$maille->codeInsee()] = $maille;
        }

        // Maille saine : gain E→C = 0,10 − (−0,20) = 0,30, erreur 17 %.
        self::assertFalse($parCode['32001']->estAberrante());
        self::assertSame(134, $parCode['32001']->n());
        self::assertSame(10, $parCode['32001']->rayonKm());

        // Erreur médiane au-dessus du défaut (30 %).
        self::assertTrue($parCode['32002']->estAberrante());
        self::assertContains('erreur_mediane_elevee', $parCode['32002']->raisons());

        // Gain E→C négatif : 0,05 → −0,10 = −0,15.
        self::assertTrue($parCode['32003']->estAberrante());
        self::assertContains('gain_ec_incoherent', $parCode['32003']->raisons());
    }

    private function seuils(): SeuilsSupervisionRepository
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $repository = $manager->getRepository(SeuilsSupervision::class);
        self::assertInstanceOf(SeuilsSupervisionRepository::class, $repository);

        return $repository;
    }

    private function provisionner(): void
    {
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
             ('32002', 2024, 20, 90, 42.00, '{\"D\":0.0,\"C\":0.10,\"E\":-0.20}', NULL),
             ('32003', 2024, 10, 120, 17.00, '{\"D\":0.0,\"C\":-0.10,\"E\":0.05}', NULL)"
        );
    }
}

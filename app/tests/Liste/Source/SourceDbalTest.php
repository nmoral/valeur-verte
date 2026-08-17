<?php

declare(strict_types=1);

namespace App\Tests\Liste\Source;

use App\Liste\ColonnesTriables;
use App\Liste\ParametresListe;
use App\Liste\Source\SourceDbal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Intégration : l'adaptateur DBAL pousse recherche (ILIKE bindé), tri (expression
 * SQL de la liste blanche) et LIMIT/OFFSET dans la requête, avec un COUNT séparé.
 * Le schéma « data » est provisionné via une connexion privilégiée, comme en prod.
 */
final class SourceDbalTest extends KernelTestCase
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

    /**
     * @return SourceDbal<string>
     */
    private function source(): SourceDbal
    {
        return new SourceDbal(
            $this->app,
            'm.code_insee, m.n_transactions, c.nom AS nom_commune',
            'data.coefficient_maille m LEFT JOIN data.commune c ON c.code_insee = m.code_insee',
            ColonnesTriables::de('code', 'n', 'nom'),
            ['code' => 'm.code_insee', 'n' => 'm.n_transactions', 'nom' => 'c.nom'],
            ['m.code_insee', 'c.nom'],
            static fn (array $ligne): string => \is_string($ligne['code_insee']) ? trim($ligne['code_insee']) : '',
            'm.code_insee',
        );
    }

    private function triables(): ColonnesTriables
    {
        return ColonnesTriables::de('code', 'n', 'nom');
    }

    public function testTriParEffectifDescendant(): void
    {
        $page = $this->source()->interroger(ParametresListe::depuis(1, 10, 'n', 'desc', '', $this->triables()));

        self::assertSame(['31555', '32001', '09122'], $page->elements());
        self::assertSame(3, $page->total());
    }

    public function testRechercheIlikeSurLeNomDeCommune(): void
    {
        $page = $this->source()->interroger(ParametresListe::depuis(1, 10, 'code', 'asc', 'toulouse', $this->triables()));

        self::assertSame(['31555'], $page->elements());
        self::assertSame(1, $page->total());
    }

    public function testPaginationPousseLimitOffset(): void
    {
        $page = $this->source()->interroger(ParametresListe::depuis(2, 2, 'code', 'asc', '', $this->triables()));

        // Tri code asc : [09122, 31555, 32001] ; page 2 taille 2 → [32001].
        self::assertSame(['32001'], $page->elements());
        self::assertSame(3, $page->total());
        self::assertSame(2, $page->pages());
    }

    public function testUnTriHorsListeBlancheNAtteintPasLeSqlEtRetombeSurLeDefaut(): void
    {
        // La colonne de tri est normalisée en amont : une valeur forgée devient le
        // défaut (« code »), la requête reste saine et triée par code_insee.
        $page = $this->source()->interroger(
            ParametresListe::depuis(1, 10, 'm.n_transactions; DROP TABLE data.coefficient_maille', 'asc', '', $this->triables()),
        );

        self::assertSame(['09122', '31555', '32001'], $page->elements());
        self::assertSame(3, $page->total());
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
        $this->prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.commune (
                code_insee       CHAR(5) PRIMARY KEY,
                nom              TEXT NOT NULL,
                code_departement TEXT NOT NULL
            )'
        );
        $this->prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $this->prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $this->prive->executeStatement('DELETE FROM data.coefficient_maille');
        $this->prive->executeStatement('DELETE FROM data.commune');
        $this->prive->executeStatement(
            "INSERT INTO data.commune (code_insee, nom, code_departement) VALUES
             ('31555', 'Toulouse', '31'), ('32001', 'Auch', '32'), ('09122', 'Foix', '09')"
        );
        $this->prive->executeStatement(
            "INSERT INTO data.coefficient_maille (code_insee, millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe, alerte) VALUES
             ('31555', 2024, 10, 200, 17.00, '{\"D\":0.0}', NULL),
             ('32001', 2024, 10, 134, 17.00, '{\"D\":0.0}', NULL),
             ('09122', 2024, 20, 90, 18.00, '{\"D\":0.0}', NULL)"
        );
    }
}

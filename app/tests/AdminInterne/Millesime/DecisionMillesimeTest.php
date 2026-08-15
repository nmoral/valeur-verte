<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Millesime;

use App\AdminInterne\JournalAudit\JournalAudit;
use App\AdminInterne\Millesime\DecisionMillesime;
use App\AdminInterne\Millesime\LectureMillesimes;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * REG-2 (#130) côté admin : lire l'état des millésimes et décider de leur sort.
 *
 * L'app est en **lecture seule** sur le schéma « data » (décision D6) : elle lit les
 * tables et n'invoque que les fonctions SQL exposées par la migration 012. Ce test
 * exécute cette migration **telle quelle** plutôt que de recréer les fonctions — si
 * elle dérive de ce que l'app attend, le test casse ici et non en production.
 */
final class DecisionMillesimeTest extends KernelTestCase
{
    private Connection $app;
    private Connection $prive;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->app = $manager->getConnection();
        $app = $this->app;

        $params = $app->getParams();
        $hote = \is_string($params['host'] ?? null) ? $params['host'] : '127.0.0.1';
        $port = is_numeric($params['port'] ?? null) ? (int) $params['port'] : 5432;
        $base = $app->getDatabase() ?? 'valeur_verte_test';
        $dsn = \sprintf(
            'postgresql://valeur_verte:valeur_verte@%s:%d/%s?serverVersion=16&charset=utf8',
            $hote,
            $port,
            $base,
        );
        $this->prive = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($dsn));
        $this->provisionner();
    }

    protected function tearDown(): void
    {
        $this->prive->executeStatement('DELETE FROM data.publication_millesime');
        $this->prive->executeStatement('DELETE FROM data.coefficient_maille_historique');
        $this->prive->executeStatement('DELETE FROM data.coefficient_maille');
        $this->prive->close();
        parent::tearDown();
    }

    public function testUnMillesimeCalculeNaPasEncoreDEtat(): void
    {
        $millesimes = $this->lecture()->tous();

        self::assertCount(1, $millesimes);
        self::assertSame(2024, $millesimes[0]->millesime());
        self::assertNull($millesimes[0]->etat(), 'Calculé n\'est pas décidé.');
        self::assertTrue($millesimes[0]->estEnAttenteDeDecision());
    }

    public function testLesEffectifsDistinguentLesMaillesBloqueesEtEnAlerte(): void
    {
        $millesime = $this->lecture()->tous()[0];

        // Trois mailles semées : une normale, une en alerte, une bloquée.
        self::assertSame(3, $millesime->maillesCalculees());
        self::assertSame(1, $millesime->maillesBloquees());
        self::assertSame(1, $millesime->maillesEnAlerte());
    }

    public function testPublierProjetteLesMaillesSaufLesBloquees(): void
    {
        $this->decision()->publier(2024, 'admin@vv.test');

        $servies = $this->app->fetchFirstColumn(
            'SELECT code_insee FROM data.coefficient_maille ORDER BY code_insee',
        );
        self::assertSame(['31001', '31002'], self::codes($servies));
    }

    public function testPublierEstTraceAuJournalAvecLeNombreDeMaillesServies(): void
    {
        $this->decision()->publier(2024, 'admin@vv.test');

        $evenement = $this->dernierEvenement('millesime.publie');
        self::assertNotNull($evenement);
        self::assertSame('admin@vv.test', $evenement->acteur());
        self::assertSame(2024, $evenement->contexte()['millesime'] ?? null);
        self::assertSame(2, $evenement->contexte()['mailles_publiees'] ?? null);
    }

    public function testAbandonnerNeTouchePasLaTableVive(): void
    {
        $this->decision()->abandonner(2024, 'admin@vv.test');

        self::assertSame(
            0,
            self::compte($this->app->fetchOne('SELECT count(*) FROM data.coefficient_maille')),
            'Abandonner doit rester sans effet de bord : rien ne doit être servi.',
        );
        self::assertSame('abandonne', $this->lecture()->tous()[0]->etat());
    }

    public function testAbandonnerEstTraceAuJournal(): void
    {
        $this->decision()->abandonner(2024, 'admin@vv.test');

        self::assertNotNull($this->dernierEvenement('millesime.abandonne'));
    }

    public function testDebloquerUneMailleLaSertApresPublication(): void
    {
        $this->decision()->publier(2024, 'admin@vv.test');

        $this->decision()->debloquer('31003', 2024, 'admin@vv.test');

        $servies = $this->app->fetchFirstColumn('SELECT code_insee FROM data.coefficient_maille');
        self::assertContains('31003', self::codes($servies));
        self::assertNotNull($this->dernierEvenement('millesime.maille_debloquee'));
    }

    public function testDebloquerAvantPublicationEstRefuse(): void
    {
        // Garde portée par la fonction SQL : une commune seule en N au milieu d'un
        // parc en N-1 serait l'état mixte que REG-2 cherche à éviter.
        $this->expectException(\RuntimeException::class);

        $this->decision()->debloquer('31003', 2024, 'admin@vv.test');
    }

    public function testLesMaillesBloqueesSontListeesAvecLeurEcart(): void
    {
        $bloquees = $this->lecture()->maillesBloquees(2024);

        self::assertCount(1, $bloquees);
        self::assertSame('31003', $bloquees[0]->codeInsee());
        self::assertSame(1.8, $bloquees[0]->ecart());
        self::assertSame('élasticité bâti', $bloquees[0]->coefficient());
    }

    /**
     * Codes INSEE nettoyés du remplissage CHAR(5).
     *
     * @param list<mixed> $lignes
     *
     * @return list<string>
     */
    private static function codes(array $lignes): array
    {
        return array_map(static fn (mixed $code): string => \is_string($code) ? trim($code) : '', $lignes);
    }

    private static function compte(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : -1;
    }

    private function lecture(): LectureMillesimes
    {
        $lecture = self::getContainer()->get(LectureMillesimes::class);
        self::assertInstanceOf(LectureMillesimes::class, $lecture);

        return $lecture;
    }

    private function decision(): DecisionMillesime
    {
        $decision = self::getContainer()->get(DecisionMillesime::class);
        self::assertInstanceOf(DecisionMillesime::class, $decision);

        return $decision;
    }

    private function dernierEvenement(string $action): ?\App\AdminInterne\JournalAudit\EvenementAudit
    {
        $journal = self::getContainer()->get(JournalAudit::class);
        self::assertInstanceOf(JournalAudit::class, $journal);

        foreach ($journal->evenementsRecents(20) as $evenement) {
            if ($action === $evenement->action()) {
                return $evenement;
            }
        }

        return null;
    }

    private function provisionner(): void
    {
        $this->prive->executeStatement('CREATE SCHEMA IF NOT EXISTS data');
        $this->prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.coefficient_maille (
                code_insee      CHAR(5) PRIMARY KEY,
                millesime       SMALLINT NOT NULL,
                rayon_retenu_km SMALLINT NOT NULL,
                n_transactions  INTEGER NOT NULL,
                r2              NUMERIC(4,3),
                erreur_mediane  NUMERIC(5,2),
                coef_m2_bati    NUMERIC(8,2),
                coef_m2_terrain NUMERIC(8,2),
                coef_dpe        JSONB NOT NULL,
                ic_dpe          JSONB,
                alerte          TEXT,
                mode            TEXT,
                palier          TEXT,
                bandes          JSONB,
                effectifs_dpe   JSONB,
                calcule_le      TIMESTAMP NOT NULL DEFAULT now()
            )',
        );
        // Un autre test (#45) crée la même table dans une forme minimale : on la
        // complète au lieu de la remplacer, pour que l'ordre d'exécution n'importe pas.
        foreach ([
            'r2 NUMERIC(4,3)',
            'coef_m2_bati NUMERIC(8,2)',
            'coef_m2_terrain NUMERIC(8,2)',
            'ic_dpe JSONB',
            'mode TEXT',
            'palier TEXT',
            'bandes JSONB',
            'effectifs_dpe JSONB',
            'calcule_le TIMESTAMP NOT NULL DEFAULT now()',
        ] as $colonne) {
            $this->prive->executeStatement(
                \sprintf('ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS %s', $colonne),
            );
        }

        $this->prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.coefficient_maille_historique (
                code_insee      CHAR(5) NOT NULL,
                millesime       SMALLINT NOT NULL,
                rayon_retenu_km SMALLINT NOT NULL,
                n_transactions  INTEGER NOT NULL,
                r2              NUMERIC(4,3),
                erreur_mediane  NUMERIC(5,2),
                coef_m2_bati    NUMERIC(8,2),
                coef_m2_terrain NUMERIC(8,2),
                coef_dpe        JSONB NOT NULL,
                ic_dpe          JSONB,
                alerte          TEXT,
                mode            TEXT,
                palier          TEXT,
                bandes          JSONB,
                effectifs_dpe   JSONB,
                calcule_le      TIMESTAMP NOT NULL DEFAULT now(),
                PRIMARY KEY (code_insee, millesime)
            )',
        );

        // La vraie migration : tables de décision, colonnes de verdict et fonctions.
        $migration = file_get_contents(
            \dirname(__DIR__, 4).'/data/src/valeur_verte_data/db/migrations/012_publication_millesime.sql',
        );
        self::assertIsString($migration);
        $this->prive->executeStatement(str_replace('valeur_verte_app', 'app', $migration));

        $this->prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $this->prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $this->prive->executeStatement('DELETE FROM data.publication_millesime');
        $this->prive->executeStatement('DELETE FROM data.coefficient_maille_historique');
        $this->prive->executeStatement('DELETE FROM data.coefficient_maille');
        $this->prive->executeStatement(
            "INSERT INTO data.coefficient_maille_historique
                (code_insee, millesime, rayon_retenu_km, n_transactions, coef_dpe,
                 mode, palier, classe_ecart, ecart, ecart_coefficient) VALUES
             ('31001', 2024, 10, 120, '{\"D\":0.0}', 'local', 'standard', NULL, NULL, NULL),
             ('31002', 2024, 10, 110, '{\"D\":0.0}', 'local', 'standard', 'alerte', 0.15, 'DPE F'),
             ('31003', 2024, 10, 100, '{\"D\":0.0}', 'local', 'standard', 'blocage', 1.80, 'élasticité bâti')",
        );
    }
}

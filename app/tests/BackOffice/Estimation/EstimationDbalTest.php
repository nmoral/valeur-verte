<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Estimation;

use App\BackOffice\Estimation\CoefficientMailleDbal;
use App\BackOffice\Estimation\PrixMailleDbal;
use App\BackOffice\Rapport\Dpe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration des adaptateurs de lecture du schéma « data » (lecture
 * seule pour « app »). Les tables sont provisionnées et semées via une connexion
 * privilégiée, puis relues par la connexion applicative — comme en production.
 */
final class EstimationDbalTest extends KernelTestCase
{
    private Connection $app;
    private Connection $prive;

    protected function setUp(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->app = $manager->getConnection();
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql']))
            ->parse('postgresql://valeur_verte:valeur_verte@db:5432/valeur_verte_test?serverVersion=16&charset=utf8');
        $this->prive = DriverManager::getConnection($params);
        $this->provisionner();
    }

    protected function tearDown(): void
    {
        // DELETE (verrou ROW EXCLUSIVE) et non TRUNCATE (ACCESS EXCLUSIVE) : la
        // transaction DAMA de la connexion « app » tient encore un ACCESS SHARE
        // sur ces tables (elle vient de les lire) et n'est annulée qu'après ce
        // tearDown — un TRUNCATE s'y bloquerait.
        $this->prive->executeStatement('DELETE FROM data.coefficient_maille');
        $this->prive->executeStatement('DELETE FROM data.vente');
        $this->prive->close();
        parent::tearDown();
    }

    public function testLeLecteurDeCoefficientRenvoieLaMailleDeLaCommune(): void
    {
        $lecteur = new CoefficientMailleDbal($this->app);

        $maille = $lecteur->pour('32013');

        self::assertNotNull($maille);
        $restitution = $maille->estimer(Dpe::E, Dpe::C, 100000, false);
        self::assertSame(134, $restitution->n());
        self::assertSame(10, $restitution->rayonKm());
        self::assertSame(2024, $restitution->millesime());
    }

    public function testUnRayonZeroSeLitCommeRepliDepartemental(): void
    {
        $this->prive->executeStatement(
            "INSERT INTO data.coefficient_maille (code_insee, millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe)
             VALUES ('32999', 2024, 0, 300, 20.0, '{\"D\":0.0,\"C\":0.10}')"
        );
        $lecteur = new CoefficientMailleDbal($this->app);

        $maille = $lecteur->pour('32999');

        self::assertNotNull($maille);
        self::assertNull($maille->estimer(Dpe::D, Dpe::C, 100000, false)->rayonKm());
    }

    public function testLeLecteurDeCoefficientRenvoieNullHorsCouverture(): void
    {
        $lecteur = new CoefficientMailleDbal($this->app);

        self::assertNull($lecteur->pour('00000'));
    }

    public function testLeLecteurDePrixRenvoieLaMedianeAuM2(): void
    {
        $lecteur = new PrixMailleDbal($this->app);

        // Ventes semées : prix_m2 = 1000, 1500, 2000 → médiane 1500.
        self::assertSame(1500.0, $lecteur->prixM2Median('32013'));
    }

    public function testLeLecteurDePrixRenvoieNullSansVente(): void
    {
        $lecteur = new PrixMailleDbal($this->app);

        self::assertNull($lecteur->prixM2Median('00000'));
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
            'CREATE TABLE IF NOT EXISTS data.vente (
                id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                code_insee   CHAR(5) NOT NULL,
                millesime    SMALLINT NOT NULL,
                prix_m2      NUMERIC(10,2)
            )'
        );
        $this->prive->executeStatement('GRANT SELECT ON data.coefficient_maille, data.vente TO app');
        $this->prive->executeStatement('TRUNCATE data.coefficient_maille, data.vente');

        $this->prive->executeStatement(
            "INSERT INTO data.coefficient_maille (code_insee, millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe) VALUES
             ('32013', 2024, 10, 134, 17.22, '{\"D\":0.0,\"C\":0.10,\"E\":-0.20}')"
        );
        $this->prive->executeStatement(
            "INSERT INTO data.vente (code_insee, millesime, prix_m2) VALUES
             ('32013', 2024, 1000),
             ('32013', 2024, 1500),
             ('32013', 2024, 2000)"
        );
    }
}

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

        // Connexion privilégiée pour provisionner le schéma « data » (le rôle app
        // n'y a que le SELECT). On reprend l'hôte/port/base de la connexion
        // applicative — « db » dans la stack locale, « 127.0.0.1 » en CI — et on
        // n'y substitue que le rôle superutilisateur, identique dans les deux.
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

    public function testLeCranEpciSeLitAvecSaPorteeSonPalierEtSesBandes(): void
    {
        // Cran EPCI (#169) : rayon absent (0), tier dégradé, classe G fusionnée
        // dans la bande F. La restitution doit servir la G via F, se situer à
        // l'intercommunal et se marquer dégradée.
        $this->prive->executeStatement(
            "INSERT INTO data.coefficient_maille
                (code_insee, millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe, mode, palier, bandes)
             VALUES ('32888', 2024, 0, 60, 27.0, '{\"D\":0.0,\"C\":0.10,\"F\":-0.30}', 'epci', 'degrade', '{\"F\":\"F\",\"G\":\"F\"}')"
        );
        $lecteur = new CoefficientMailleDbal($this->app);

        $maille = $lecteur->pour('32888');

        self::assertNotNull($maille);
        $viaBande = $maille->estimer(Dpe::G, Dpe::C, 100000, false);
        $direct = $maille->estimer(Dpe::F, Dpe::C, 100000, false);
        self::assertSame($direct->montantEuros(), $viaBande->montantEuros());
        self::assertSame('intercommunal (EPCI)', $viaBande->intitulePortee());
        self::assertTrue($viaBande->estDegradee());
        self::assertSame(['G' => 'F'], $viaBande->bandesAppliquees());
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
        // Colonnes de la cascade (#169, migration 009) ajoutées séparément : la
        // table peut préexister dans une base de test au schéma antérieur, que le
        // CREATE ... IF NOT EXISTS n'aurait pas mise à niveau.
        $this->prive->executeStatement('ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS mode   TEXT');
        $this->prive->executeStatement('ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS palier TEXT');
        $this->prive->executeStatement('ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS bandes JSONB');
        // #260 : la fourchette découle de ic_dpe ; NULL (inserts sans cette colonne)
        // fait retomber l'estimation sur l'erreur médiane (repli), assertions inchangées.
        $this->prive->executeStatement('ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS ic_dpe JSONB');
        $this->prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.vente (
                id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                id_mutation  TEXT NOT NULL,
                code_insee   CHAR(5) NOT NULL,
                millesime    SMALLINT NOT NULL,
                prix_m2      NUMERIC(10,2)
            )'
        );
        // Le rôle applicatif ne lit « data » qu'en lecture seule : on garantit
        // l'accès quel que soit le propriétaire des tables (réelles ou minimales).
        $this->prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $this->prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $this->prive->executeStatement('TRUNCATE data.coefficient_maille, data.vente');

        $this->prive->executeStatement(
            "INSERT INTO data.coefficient_maille (code_insee, millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe) VALUES
             ('32013', 2024, 10, 134, 17.22, '{\"D\":0.0,\"C\":0.10,\"E\":-0.20}')"
        );
        $this->prive->executeStatement(
            "INSERT INTO data.vente (id_mutation, code_insee, millesime, prix_m2) VALUES
             ('m1', '32013', 2024, 1000),
             ('m2', '32013', 2024, 1500),
             ('m3', '32013', 2024, 2000)"
        );
    }
}

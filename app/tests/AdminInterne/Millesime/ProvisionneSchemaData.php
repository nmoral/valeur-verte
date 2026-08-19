<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Millesime;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provisionne le schéma « data » de la base de test pour les écrans de publication
 * (#130). L'app y est en lecture seule (décision D6) : la préparation passe donc par
 * une connexion privilégiée, comme en production.
 *
 * Les fonctions SQL sont installées en rejouant la **vraie migration 012** plutôt
 * qu'en la recopiant : une copie dériverait sans que rien ne le signale, alors que
 * l'app dépend de leur signature exacte.
 */
trait ProvisionneSchemaData
{
    private ?Connection $connexionPrivilegiee = null;

    protected function tearDown(): void
    {
        if (null !== $this->connexionPrivilegiee) {
            $this->connexionPrivilegiee->executeStatement('DELETE FROM data.publication_millesime');
            $this->connexionPrivilegiee->executeStatement('DELETE FROM data.coefficient_maille_historique');
            $this->connexionPrivilegiee->executeStatement('DELETE FROM data.coefficient_maille');
            $this->connexionPrivilegiee->close();
            $this->connexionPrivilegiee = null;
        }

        parent::tearDown();
    }

    protected function app(): Connection
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager->getConnection();
    }

    protected function prive(): Connection
    {
        if (null !== $this->connexionPrivilegiee) {
            return $this->connexionPrivilegiee;
        }

        $params = $this->app()->getParams();
        $hote = \is_string($params['host'] ?? null) ? $params['host'] : '127.0.0.1';
        $port = is_numeric($params['port'] ?? null) ? (int) $params['port'] : 5432;
        $base = $this->app()->getDatabase() ?? 'valeur_verte_test';
        $dsn = \sprintf(
            'postgresql://valeur_verte:valeur_verte@%s:%d/%s?serverVersion=16&charset=utf8',
            $hote,
            $port,
            $base,
        );

        return $this->connexionPrivilegiee = DriverManager::getConnection(
            (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($dsn),
        );
    }

    /**
     * Sème trois mailles sous le millésime 2024 : une normale, une en alerte
     * (publiée mais signalée), une en blocage (écartée de la publication).
     */
    protected function provisionner(): void
    {
        $prive = $this->prive();
        $prive->executeStatement('CREATE SCHEMA IF NOT EXISTS data');
        $prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.coefficient_maille (
                code_insee      CHAR(5) PRIMARY KEY,
                millesime       SMALLINT NOT NULL,
                rayon_retenu_km SMALLINT NOT NULL,
                n_transactions  INTEGER NOT NULL,
                erreur_mediane  NUMERIC(5,2),
                coef_dpe        JSONB NOT NULL,
                alerte          TEXT
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
            'poids_pooling DOUBLE PRECISION',
            'calcule_le TIMESTAMP NOT NULL DEFAULT now()',
        ] as $colonne) {
            $prive->executeStatement(
                \sprintf('ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS %s', $colonne),
            );
        }

        $prive->executeStatement(
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
                poids_pooling   DOUBLE PRECISION,
                calcule_le      TIMESTAMP NOT NULL DEFAULT now(),
                PRIMARY KEY (code_insee, millesime)
            )',
        );

        // VV_DATA_DIR quand l'arbre du pipeline est monté ailleurs (conteneur) ;
        // sinon la disposition du dépôt, qui vaut en CI comme en local.
        $racine = getenv('VV_DATA_DIR') ?: \dirname(__DIR__, 4).'/data';
        $migration = file_get_contents(
            $racine.'/src/valeur_verte_data/db/migrations/012_publication_millesime.sql',
        );
        self::assertIsString($migration, 'La migration 012 doit être lisible depuis les tests.');
        $prive->executeStatement(str_replace('valeur_verte_app', 'app', $migration));

        $prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $prive->executeStatement('DELETE FROM data.publication_millesime');
        $prive->executeStatement('DELETE FROM data.coefficient_maille_historique');
        $prive->executeStatement('DELETE FROM data.coefficient_maille');
        $prive->executeStatement(
            "INSERT INTO data.coefficient_maille_historique
                (code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
                 coef_m2_bati, coef_m2_terrain, coef_dpe, effectifs_dpe,
                 mode, palier, classe_ecart, ecart, ecart_coefficient) VALUES
             ('31001', 2024, 10, 120, 0.87, 17.00, 0.85, 0.25, '{\"D\":0.0,\"C\":0.11,\"E\":-0.19}', '{\"D\":30,\"C\":24,\"E\":22}', 'local', 'standard', NULL, NULL, NULL),
             ('31002', 2024, 10, 110, 0.80, 18.00, 0.80, 0.22, '{\"D\":0.0,\"C\":0.11,\"E\":-0.19}', '{\"D\":30,\"C\":24,\"E\":22}', 'local', 'standard', 'alerte', 0.15, 'DPE F'),
             ('31003', 2024, 10, 100, 0.75, 19.00, 0.90, 0.30, '{\"D\":0.0,\"C\":0.11,\"E\":-0.19}', '{\"D\":30,\"C\":24,\"E\":22}', 'local', 'standard', 'blocage', 1.80, 'élasticité bâti')",
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provisionne le schéma « data » de la base de test pour la page de publication
 * (#48, #224). L'app y est en lecture seule (décision D6) : la préparation passe
 * par une connexion privilégiée, comme en production.
 *
 * Les fonctions et la table de décision viennent de la **vraie migration 012**,
 * rejouée plutôt que recopiée : l'app dépend de leur forme exacte.
 */
trait ProvisionneModelePublie
{
    private ?Connection $connexionPrivilegiee = null;

    protected function tearDown(): void
    {
        $prive = $this->connexionPrivilegiee;
        if (null !== $prive) {
            $this->nettoyer();
            $prive->close();
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

    /**
     * Connexion privilégiée, provisionnée au premier usage. Le kernel n'est jamais
     * démarré ici : un WebTestCase doit pouvoir créer son client d'abord.
     */
    protected function prive(): Connection
    {
        if (null !== $this->connexionPrivilegiee) {
            return $this->connexionPrivilegiee;
        }

        $params = $this->app()->getParams();
        $hote = \is_string($params['host'] ?? null) ? $params['host'] : '127.0.0.1';
        $port = is_numeric($params['port'] ?? null) ? (int) $params['port'] : 5432;
        $base = $this->app()->getDatabase() ?? 'valeur_verte_test';

        $prive = DriverManager::getConnection(
            (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse(\sprintf(
                'postgresql://valeur_verte:valeur_verte@%s:%d/%s?serverVersion=16&charset=utf8',
                $hote,
                $port,
                $base,
            )),
        );
        $this->connexionPrivilegiee = $prive;
        $this->provisionner();

        return $prive;
    }

    protected function semerMaille(
        string $codeInsee,
        string $mode = 'local',
        string $coefDpe = '{"D":0.0,"C":0.11,"E":-0.19}',
        string $calculeLe = '2026-02-08 04:12:00',
        int $millesime = 2025,
        ?float $poidsPooling = null,
    ): void {
        $this->prive()->executeStatement(
            'INSERT INTO data.coefficient_maille
                (code_insee, millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe, mode, poids_pooling, calcule_le)
             VALUES (:code, :millesime, 10, 120, 17.00, :coef, :mode, :poids, :calcule)',
            ['code' => $codeInsee, 'millesime' => $millesime, 'coef' => $coefDpe, 'mode' => $mode, 'poids' => $poidsPooling, 'calcule' => $calculeLe],
        );
    }

    protected function semerMailleArchivee(string $codeInsee, int $millesime = 2026): void
    {
        $this->prive()->executeStatement(
            'INSERT INTO data.coefficient_maille_historique
                (code_insee, millesime, rayon_retenu_km, n_transactions, erreur_mediane, coef_dpe, mode)
             VALUES (:code, :millesime, 10, 120, 17.00, \'{"D":0.0,"C":0.11,"E":-0.19}\', \'local\')',
            ['code' => $codeInsee, 'millesime' => $millesime],
        );
    }

    protected function deciderMillesime(int $millesime, string $etat, string $decideLe): void
    {
        $this->prive()->executeStatement(
            'INSERT INTO data.publication_millesime (millesime, etat, decide_le, decide_par, mailles_publiees)
             VALUES (:millesime, :etat, :decide, \'test\', 0)',
            ['millesime' => $millesime, 'etat' => $etat, 'decide' => $decideLe],
        );
    }

    protected function semerCommune(string $codeInsee, string $nom, string $departement): void
    {
        $this->prive()->executeStatement(
            'INSERT INTO data.commune (code_insee, nom, code_departement) VALUES (:code, :nom, :dept)',
            ['code' => $codeInsee, 'nom' => $nom, 'dept' => $departement],
        );
    }

    /**
     * Une vente au prix/m² donné, pour que {@see \App\BackOffice\Estimation\PrixMailleDbal}
     * ait une médiane à servir (la fiche commune #360 en dérive le prix au m²).
     */
    protected function semerVente(string $codeInsee, float $prixM2, int $millesime = 2025): void
    {
        $this->prive()->executeStatement(
            'INSERT INTO data.vente (id_mutation, code_insee, millesime, prix_m2)
             VALUES (:mutation, :code, :millesime, :prix)',
            ['mutation' => uniqid('m', true), 'code' => $codeInsee, 'millesime' => $millesime, 'prix' => $prixM2],
        );
    }

    private function provisionner(): void
    {
        $prive = $this->connexionPrivilegiee;
        self::assertInstanceOf(Connection::class, $prive);
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

        // D'autres tests (#45, #130) créent la même table dans une forme plus
        // pauvre : on la complète au lieu de la remplacer, pour que l'ordre
        // d'exécution n'importe pas.
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
                calcule_le      TIMESTAMP NOT NULL DEFAULT now(),
                PRIMARY KEY (code_insee, millesime)
            )',
        );

        $prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.commune (
                code_insee       CHAR(5) PRIMARY KEY,
                nom              TEXT NOT NULL,
                code_departement VARCHAR(3) NOT NULL,
                maj_le           TIMESTAMP NOT NULL DEFAULT now()
            )',
        );

        // Forme minimale de data.vente (comme EstimationDbalTest) : PrixMailleDbal ne
        // lit que code_insee + prix_m2 pour sa médiane. Le prix de la fiche commune (#360)
        // en dérive ; une table vide ⇒ prix null ⇒ section prix simplement masquée.
        $prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.vente (
                id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                id_mutation TEXT NOT NULL,
                code_insee  CHAR(5) NOT NULL,
                millesime   SMALLINT NOT NULL,
                prix_m2     NUMERIC(10,2)
            )',
        );

        // VV_DATA_DIR quand l'arbre du pipeline est monté ailleurs (conteneur) ;
        // sinon la disposition du dépôt, qui vaut en CI comme en local.
        $racine = getenv('VV_DATA_DIR') ?: \dirname(__DIR__, 3).'/data';
        $migration = file_get_contents(
            $racine.'/src/valeur_verte_data/db/migrations/012_publication_millesime.sql',
        );
        self::assertIsString($migration, 'La migration 012 doit être lisible depuis les tests.');
        $prive->executeStatement(str_replace('valeur_verte_app', 'app', $migration));

        $prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $this->nettoyer();
    }

    private function nettoyer(): void
    {
        $prive = $this->connexionPrivilegiee;
        self::assertInstanceOf(Connection::class, $prive);

        foreach ([
            'data.publication_millesime',
            'data.coefficient_maille_historique',
            'data.coefficient_maille',
            'data.commune',
            'data.vente',
        ] as $table) {
            $prive->executeStatement(\sprintf('DELETE FROM %s', $table));
        }
    }
}

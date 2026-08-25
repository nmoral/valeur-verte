<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provisionne le référentiel géographique commune du schéma « data » pour les
 * tests du répertoire des communes (#359, SEO Vague 2 #338) : ``data.commune``
 * (#220, migration 013) et ``data.commune_epci`` (#169, migration 008). L'app y
 * est en lecture seule (décision D6) : le semis passe par une connexion
 * privilégiée, comme en production, et la lecture par la connexion applicative.
 *
 * Trait autonome (il porte sa propre connexion privilégiée) pour ne pas alourdir
 * {@see ProvisionneModelePublie}, centré sur la publication.
 */
trait ProvisionneReferentielCommunes
{
    private ?Connection $connexionPrivilegieeCommunes = null;

    protected function tearDown(): void
    {
        $prive = $this->connexionPrivilegieeCommunes;
        if (null !== $prive) {
            $this->nettoyerCommunes();
            $prive->close();
            $this->connexionPrivilegieeCommunes = null;
        }

        parent::tearDown();
    }

    protected function app(): Connection
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager->getConnection();
    }

    protected function semerCommune(string $codeInsee, string $nom, string $departement): void
    {
        $this->priveCommunes()->executeStatement(
            'INSERT INTO data.commune (code_insee, nom, code_departement) VALUES (:code, :nom, :dept)',
            ['code' => $codeInsee, 'nom' => $nom, 'dept' => $departement],
        );
    }

    protected function semerRattachementEpci(string $codeInsee, string $codeEpci): void
    {
        $this->priveCommunes()->executeStatement(
            'INSERT INTO data.commune_epci (code_insee, code_epci) VALUES (:code, :epci)',
            ['code' => $codeInsee, 'epci' => $codeEpci],
        );
    }

    private function priveCommunes(): Connection
    {
        if (null !== $this->connexionPrivilegieeCommunes) {
            return $this->connexionPrivilegieeCommunes;
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
        $this->connexionPrivilegieeCommunes = $prive;
        $this->provisionnerCommunes();

        return $prive;
    }

    private function provisionnerCommunes(): void
    {
        $prive = $this->connexionPrivilegieeCommunes;
        self::assertInstanceOf(Connection::class, $prive);

        $prive->executeStatement('CREATE SCHEMA IF NOT EXISTS data');
        $prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.commune (
                code_insee       CHAR(5) PRIMARY KEY,
                nom              TEXT NOT NULL,
                code_departement VARCHAR(3) NOT NULL,
                maj_le           TIMESTAMP NOT NULL DEFAULT now()
            )',
        );
        $prive->executeStatement(
            'CREATE TABLE IF NOT EXISTS data.commune_epci (
                code_insee CHAR(5) PRIMARY KEY,
                code_epci  VARCHAR(9) NOT NULL
            )',
        );

        $prive->executeStatement('GRANT USAGE ON SCHEMA data TO app');
        $prive->executeStatement('GRANT SELECT ON ALL TABLES IN SCHEMA data TO app');
        $this->nettoyerCommunes();
    }

    private function nettoyerCommunes(): void
    {
        $prive = $this->connexionPrivilegieeCommunes;
        self::assertInstanceOf(Connection::class, $prive);

        foreach (['data.commune_epci', 'data.commune'] as $table) {
            $prive->executeStatement(\sprintf('DELETE FROM %s', $table));
        }
    }
}

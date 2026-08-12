#!/bin/bash
# Initialisation applicative de la base partagée (#37), jouée une fois à la
# création du volume, en tant que superutilisateur.
#
# Règle : l'app écrit le schéma « app » et ne lit que « data ». Prépare la base
# de dev ET la base de test (valeur_verte_test), pour que les tests d'intégration
# tournent sur leur propre base — jamais sur celle de développement.
set -euo pipefail

BASE_TEST="${POSTGRES_DB}_test"

# Rôle applicatif (niveau cluster) + base de test. Le search_path est porté par le
# rôle : toute connexion de l'app travaille dans « app ».
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
	CREATE ROLE app WITH LOGIN PASSWORD 'app';
	ALTER ROLE app SET search_path TO app, public;
	CREATE DATABASE "${BASE_TEST}" OWNER "$POSTGRES_USER";
SQL

# Schéma « app » (possédé par l'app) et lecture seule sur « data », dans chaque base.
for base in "$POSTGRES_DB" "$BASE_TEST"; do
	psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$base" <<-SQL
		CREATE SCHEMA IF NOT EXISTS app AUTHORIZATION app;
		CREATE SCHEMA IF NOT EXISTS data;
		GRANT USAGE ON SCHEMA data TO app;
		GRANT SELECT ON ALL TABLES IN SCHEMA data TO app;
		ALTER DEFAULT PRIVILEGES FOR ROLE "$POSTGRES_USER" IN SCHEMA data GRANT SELECT ON TABLES TO app;
	SQL
done

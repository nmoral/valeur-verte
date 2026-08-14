-- Provisionnement du rôle applicatif « app » sur la base partagée (#78, #37).
--
-- Rejoué **à chaque déploiement**, donc strictement idempotent : il crée le rôle
-- s'il manque, resynchronise son mot de passe sur le secret courant, garantit le
-- schéma « app » et les droits de lecture seule sur « data ». À jouer en
-- superutilisateur, APRÈS les migrations « data » (le schéma « data » doit
-- exister pour les GRANT). Paramètres attendus :
--   -v mot_de_passe='...'  mot de passe du rôle « app » (URL-safe)
--   -v superutilisateur='...'  propriétaire de « data » (POSTGRES_USER)
\set ON_ERROR_STOP on

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'app') THEN
        CREATE ROLE app LOGIN;
    END IF;
END
$$;

ALTER ROLE app WITH LOGIN PASSWORD :'mot_de_passe';
ALTER ROLE app SET search_path TO app;

CREATE SCHEMA IF NOT EXISTS app AUTHORIZATION app;

GRANT USAGE ON SCHEMA data TO app;
GRANT SELECT ON ALL TABLES IN SCHEMA data TO app;
ALTER DEFAULT PRIVILEGES FOR ROLE :"superutilisateur" IN SCHEMA data GRANT SELECT ON TABLES TO app;

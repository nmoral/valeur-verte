-- Initialisation de la base partagée pour l'application (#37).
-- Exécuté une seule fois, à la création du volume, par le superutilisateur POSTGRES_USER.
--
-- Règle tranchée : l'app écrit le schéma « app » et ne lit que le schéma « data ».
-- Le search_path est porté par le rôle : toute connexion de l'app travaille dans « app ».

-- Rôle applicatif (mot de passe de développement ; la production le surcharge).
CREATE ROLE app WITH LOGIN PASSWORD 'app';
ALTER ROLE app SET search_path TO app, public;

-- Schéma applicatif, possédé par l'app : Doctrine y crée et y migre ses tables.
CREATE SCHEMA IF NOT EXISTS app AUTHORIZATION app;

-- Schéma « data » : possédé par le pipeline (POSTGRES_USER), l'app n'y a que la lecture.
-- Créé ici s'il n'existe pas encore, pour poser les droits sans attendre le pipeline.
CREATE SCHEMA IF NOT EXISTS data;
GRANT USAGE ON SCHEMA data TO app;
GRANT SELECT ON ALL TABLES IN SCHEMA data TO app;
-- Les tables futures créées par le pipeline dans « data » restent lisibles par l'app,
-- et par elles seules (aucun INSERT/UPDATE/DELETE accordé).
ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA data GRANT SELECT ON TABLES TO app;

-- #366 : référentiel des noms d'EPCI, source du slug d'URL du hub EPCI (#363).
--
-- data.commune_epci (008) ne porte que le SIREN de l'EPCI ; l'app ne peut pas
-- slugifier un nom qu'elle n'a pas. On persiste ici le libellé, alimenté par le
-- champ `nom` de l'EPCI de geo.api.gouv.fr (déjà la source du rattachement
-- codeEpci). Même logique de référentiel que data.commune (#220, migration 013).
--
-- GRANT SELECT à l'app : hérité des privilèges par défaut du schéma data
-- (migration 004, ALTER DEFAULT PRIVILEGES) — aucun GRANT explicite nécessaire.
CREATE TABLE IF NOT EXISTS data.epci (
    code_epci VARCHAR(9) PRIMARY KEY,
    nom       TEXT      NOT NULL,
    maj_le    TIMESTAMP NOT NULL DEFAULT now()
);

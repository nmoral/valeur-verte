-- Appartenance des communes à leur EPCI (#169), pour le cran EPCI de la cascade
-- de couverture : quand le niveau communal ne suffit pas, on regroupe les ventes
-- des communes d'un même EPCI. Source : champ `codeEpci` de geo.api.gouv.fr.
-- La lecture par l'app est couverte par les privilèges par défaut (migration 004).
CREATE TABLE IF NOT EXISTS data.commune_epci (
    code_insee CHAR(5)    PRIMARY KEY,
    code_epci  VARCHAR(9) NOT NULL          -- SIREN de l'EPCI
);

-- Regroupement par EPCI : la cascade interroge « les communes de cet EPCI ».
CREATE INDEX IF NOT EXISTS idx_commune_epci_epci ON data.commune_epci (code_epci);

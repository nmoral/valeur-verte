-- Couverture géographique pilotée en base (§3.2) : état par région/département
-- et journal des requêtes hors zone (signal de priorisation gratuit).
CREATE TABLE IF NOT EXISTS data.couverture_region (
    code_region CHAR(2)   PRIMARY KEY,
    nom         TEXT      NOT NULL,
    statut      TEXT      NOT NULL DEFAULT 'en_attente',
    maj_le      TIMESTAMP NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS data.couverture_departement (
    code_departement VARCHAR(3) PRIMARY KEY,        -- '31', '2A', '974'
    code_region      CHAR(2)    REFERENCES data.couverture_region (code_region),
    statut           TEXT       NOT NULL DEFAULT 'en_attente',
        -- en_attente → charge → en_attente_limitrophes → calcule (§3.2)
    charge_le        TIMESTAMP,
    calcule_le       TIMESTAMP,
    maj_le           TIMESTAMP  NOT NULL DEFAULT now()
);

-- Chaque requête sur une zone non couverte est loguée : priorisation gratuite.
CREATE TABLE IF NOT EXISTS data.requete_hors_zone (
    id          BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code_insee  VARCHAR(5),
    code_postal CHAR(5),
    demande_le  TIMESTAMP NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_hors_zone_insee ON data.requete_hors_zone (code_insee);

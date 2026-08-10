-- Transactions DVF géolocalisées et nettoyées (§7). Une ligne = une mutation.
-- Clé de rattachement commune : `code_insee`, JAMAIS le code postal (§7).
CREATE TABLE IF NOT EXISTS data.vente (
    id                        BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id_mutation               TEXT             NOT NULL,
    date_mutation             DATE,
    millesime                 SMALLINT         NOT NULL,   -- année du fichier DVF ingéré
    code_insee                CHAR(5)          NOT NULL,   -- code commune (jointure)
    code_postal               CHAR(5),                     -- informatif, jamais clé
    nom_commune               TEXT,
    type_local                TEXT,
    nombre_pieces_principales SMALLINT,
    prix                      NUMERIC(12,2),               -- valeur_fonciere
    surface_bati              NUMERIC(8,2),
    surface_terrain           NUMERIC(10,2),               -- somme des parcelles (§7)
    prix_m2                   NUMERIC(10,2),
    adresse_numero            TEXT,
    adresse_suffixe           TEXT,
    adresse_nom_voie          TEXT,
    adresse_key               TEXT,                        -- clé d'appariement DPE (§15)
    etiquette_dpe             CHAR(1),                     -- DPE apparié (§15)
    periode_construction      TEXT,                        -- issu du DPE (bâti ancien, §5.2)
    longitude                 DOUBLE PRECISION,
    latitude                  DOUBLE PRECISION,
    geom                      geography(Point,4326)        -- ST_MakePoint(longitude, latitude)
);

-- Index spatial pour le filtrage par rayon (ST_DWithin, §5.1).
CREATE INDEX IF NOT EXISTS idx_vente_geom ON data.vente USING GIST (geom);

-- Rattachement commune et déduplication d'ingestion.
CREATE INDEX IF NOT EXISTS idx_vente_code_insee ON data.vente (code_insee);
CREATE INDEX IF NOT EXISTS idx_vente_mutation ON data.vente (id_mutation, millesime);

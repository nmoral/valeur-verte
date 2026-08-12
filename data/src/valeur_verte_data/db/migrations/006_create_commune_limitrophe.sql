-- Contiguïté communale pour l'invariant géographique INV-3 (#121).
-- Le contour de chaque commune (geo.api.gouv.fr) est stocké en géométrie ; la
-- relation de limitrophie s'en déduit par ST_Touches (partage de frontière).
-- La relation franchit les départements : une commune de bord est limitrophe
-- d'une commune du département voisin dès que les deux contours sont chargés
-- (règle des limitrophes, §3.2 ; #24/#91).
CREATE TABLE IF NOT EXISTS data.commune_contour (
    code_insee       CHAR(5) PRIMARY KEY,
    code_departement VARCHAR(3) NOT NULL,
    geom             geometry(MultiPolygon, 4326) NOT NULL
);

-- Index spatial : ST_Touches filtre d'abord sur les rectangles englobants (&&).
CREATE INDEX IF NOT EXISTS idx_commune_contour_geom ON data.commune_contour USING GIST (geom);

-- Paires limitrophes, chacune stockée une seule fois (code_insee_a < code_insee_b).
CREATE TABLE IF NOT EXISTS data.commune_limitrophe (
    code_insee_a CHAR(5) NOT NULL,
    code_insee_b CHAR(5) NOT NULL,
    PRIMARY KEY (code_insee_a, code_insee_b),
    CHECK (code_insee_a < code_insee_b)
);

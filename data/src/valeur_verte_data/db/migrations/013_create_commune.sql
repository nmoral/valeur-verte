-- Référentiel des communes (#220) : nom lisible à partir d'un code INSEE.
--
-- Le pipeline récupérait déjà `nom` de l'API Découpage administratif sans le
-- persister, si bien que tous les écrans affichaient des codes bruts. Le stocker
-- évite d'appeler une API publique à chaque ligne de tableau — un nom de commune
-- est une donnée de référence, pas une donnée temps réel.
--
-- Distinct de `data.vente.nom_commune`, qui n'existe que pour les communes ayant
-- des transactions ingérées : les mailles calculées aux crans EPCI ou
-- départemental (#169) portent des communes qui n'en ont aucune.
CREATE TABLE IF NOT EXISTS data.commune (
    code_insee       CHAR(5)              PRIMARY KEY,
    nom              TEXT                 NOT NULL,
    code_departement VARCHAR(3)           NOT NULL,
    maj_le           TIMESTAMP            NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_commune_departement ON data.commune (code_departement);

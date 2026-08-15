-- #211 : date d'établissement et version de méthode du DPE apparié à la vente.
-- Datent le diagnostic (le DPE le plus récent fait foi, cf. dpe.build_dpe_lookup)
-- pour gérer les ruptures de réforme DPE en aval (#208 pondération temporelle,
-- #127 DAT-4). Colonnes nullables : une vente non appariée à un DPE les laisse NULL.
ALTER TABLE data.vente
    ADD COLUMN IF NOT EXISTS date_etablissement_dpe DATE,
    ADD COLUMN IF NOT EXISTS version_dpe            TEXT;

-- Cascade de couverture rurale (#169) : la maille transporte désormais son cran
-- (mode : local/epci/departemental), son tier de fiabilité (palier), le mapping des
-- bandes DPE fusionnées et l'effectif par classe. Nécessaires à la restitution :
-- distinguer EPCI et DÉPARTEMENT (tous deux sans rayon), servir une maison d'une
-- classe fusionnée (via `bandes`), et tolérer INV-1 au rechargement (via `effectifs_dpe`).
-- Nullable : le batch les remplit à chaque calcul, les lignes antérieures se recalculent.
ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS mode          TEXT;
ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS palier        TEXT;
ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS bandes        JSONB;
ALTER TABLE data.coefficient_maille ADD COLUMN IF NOT EXISTS effectifs_dpe JSONB;

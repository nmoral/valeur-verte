-- Distributions observées par ingestion — DAT-4 (#127, docs/QUALITE-DONNEES.md).
--
-- En fin d'ingestion, DAT-4 mesure sur data.vente les distributions du département
-- (prix médian, surface bâtie médiane, part de terrain nul/aberrant, histogramme des
-- étiquettes DPE) et les archive ici, à côté des autres métriques d'ingestion (#131).
-- La porte au calcul les relit : la plausibilité (médianes, terrain) barre le calcul,
-- la répartition DPE vs le millésime précédent alerte sans bloquer (réforme légitime).
--
-- Une seule colonne JSONB (plutôt que quatre colonnes typées) : la structure
-- {prix_median, surface_bati_median, terrain_aberrant, effectif, dpe:{classe:count}}
-- reste extensible et se compare directement d'un millésime à l'autre. NULL en phase
-- calcul, et NULL pour une ingestion antérieure à DAT-4 (rétro-compatibilité).
ALTER TABLE data.rapport_batch
    ADD COLUMN IF NOT EXISTS distribution JSONB;

-- Rattrapage des coordonnées corrompues déjà en base (géocodage source).
--
-- L'audit de validité France entière a détecté 60 ventes (toutes dans le 92)
-- tombées à une latitude aberrante (~78-83°N, hors métropole). Le garde-fou
-- d'ingestion (`cleaning.assainir_coordonnees`) neutralise désormais ces
-- coordonnées à l'entrée, mais les lignes déjà ingérées portent encore une
-- géoloc impossible et un `geom` matérialisé au mauvais endroit.
--
-- On neutralise `longitude`, `latitude` et `geom` (mais on GARDE la ligne : la
-- transaction — prix, surfaces, DPE — reste valide) pour toute vente hors de
-- l'enveloppe métropolitaine (Corse comprise). La vente sort simplement de la
-- sélection spatiale, comme une vente jamais géolocalisée. Idempotent : rejouée,
-- elle ne trouve plus rien à corriger.
UPDATE data.vente
SET longitude = NULL,
    latitude  = NULL,
    geom      = NULL
WHERE latitude IS NOT NULL
  AND (
      latitude  NOT BETWEEN 41.0 AND 51.5
      OR longitude NOT BETWEEN -5.5 AND 9.8
  );

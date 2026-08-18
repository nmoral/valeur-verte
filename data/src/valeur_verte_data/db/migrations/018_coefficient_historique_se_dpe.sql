-- Écarts-types des effets DPE par maille — #249 (pooling partiel hiérarchique).
--
-- Le shrinkage empirical-Bayes pondère chaque effet DPE communal par sa précision
-- (poids w = τ²/(τ²+SE²)). On archive donc, à côté de `coef_dpe`, l'écart-type
-- d'échantillonnage `se_dpe` de chaque classe publiée. C'est la **matière première**
-- des priors τ̂² : une fois `se_dpe` présent avec les β̂ (`coef_dpe`, déjà archivés),
-- la variance inter-communes se **recalcule** en agrégat sur l'historique, sans refaire
-- un seul fit. Régresseur interne au calcul (jamais servi à l'app).
--
-- NULL pour une maille antérieure à #249 (rétro-compat) ou construite hors moteur.
ALTER TABLE data.coefficient_maille_historique
    ADD COLUMN IF NOT EXISTS se_dpe JSONB;

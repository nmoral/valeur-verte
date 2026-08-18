-- Fraîcheur des sources par ingestion — DAT-5 (#128, docs/QUALITE-DONNEES.md).
--
-- DVF est publié semestriellement : une source qui cesse d'être mise à jour ne lève
-- aucune erreur — le pipeline retraite des données périmées et publie des coefficients
-- qui vieillissent en silence. DAT-5 mesure en fin d'ingestion la date de mutation la
-- plus récente du département et l'archive ici : c'est la **source de vérité** de la
-- fraîcheur/millésime, consommée par l'admin (#46/#48) et confrontée à la date
-- d'exécution (alerte au-delà de 12 mois, jamais un blocage).
--
-- NULL en phase calcul, et NULL pour une ingestion antérieure à DAT-5 (rétro-compat).
ALTER TABLE data.rapport_batch
    ADD COLUMN IF NOT EXISTS mutation_la_plus_recente DATE;

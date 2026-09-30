-- #341 (SEO Vague 2b) : marqueurs territoriaux du bloc « Pourquoi ici ? ».
--
-- Un marqueur qualifie une commune OU un département par un attribut lisible
-- (`nom` = 'climat', 'marche', 'densite', 'levier_renovation'… ; `valeur` =
-- 'froid', 'tendu', 'rural', 'fort'…). Deux familles distinguées par
-- `type_marqueur` : 'contextuel' (référentiel externe figé, expliquant le
-- « pourquoi ») et 'mesure' (dérivé de nos données, factuel). Chaque ligne porte
-- sa `source` versionnée — critère d'acceptation #341 : tout référentiel est sourcé.
--
-- Portée exclusive : une ligne cible soit une commune (`code_insee`), soit un
-- département (`code_departement`), jamais les deux ni aucun (CHECK). L'unicité
-- (`nom` par portée) est portée par une contrainte NULLS NOT DISTINCT — la clé
-- nulle de la portée absente compte comme une valeur, si bien qu'un même
-- marqueur ne peut être écrit deux fois pour la même cible (upsert idempotent).
--
-- GRANT SELECT à l'app : hérité des privilèges par défaut du schéma data
-- (migration 004, ALTER DEFAULT PRIVILEGES) — aucun GRANT explicite nécessaire.
CREATE TABLE IF NOT EXISTS data.marqueurs (
    code_insee       CHAR(5),
    code_departement VARCHAR(3),
    nom              TEXT      NOT NULL,
    valeur           TEXT      NOT NULL,
    type_marqueur    TEXT      NOT NULL,
    source           TEXT      NOT NULL,
    maj_le           TIMESTAMP NOT NULL DEFAULT now(),
    CONSTRAINT marqueur_une_seule_portee
        CHECK (num_nonnulls(code_insee, code_departement) = 1),
    CONSTRAINT marqueur_unique_par_cible
        UNIQUE NULLS NOT DISTINCT (code_insee, code_departement, nom)
);

CREATE INDEX IF NOT EXISTS idx_marqueur_commune
    ON data.marqueurs (code_insee) WHERE code_insee IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_marqueur_departement
    ON data.marqueurs (code_departement) WHERE code_departement IS NOT NULL;

-- Rapport de batch archivé — #131 (§6 Journalisation, docs/QUALITE-DONNEES.md).
--
-- « Ce rapport est la première chose à regarder six mois plus tard, quand le
-- contexte est oublié. » Le batch ne tourne que deux fois par an (#19) : chaque
-- exécution archive donc son propre rapport, jamais écrasé (clé technique `id`),
-- pour que la comparaison entre millésimes (DAT-2, REG-1) dispose du précédent.
--
-- Une ligne = une exécution de commande (décision : granularité par exécution).
-- Le batch tourne par département et en deux temps (`ingest` puis `compute`,
-- conteneurs détachés, CLAUDE.md §5) : la `phase` discrimine les deux jeux de
-- métriques, chacun NULL dans l'autre phase. Les « mailles publiées » ne sont pas
-- ici : elles relèvent de la publication manuelle (REG-2, `publication_millesime`).
CREATE TABLE IF NOT EXISTS data.rapport_batch (
    id                   BIGSERIAL   PRIMARY KEY,
    phase                TEXT        NOT NULL CHECK (phase IN ('ingestion', 'calcul')),
    departement          VARCHAR(3)  NOT NULL,
    millesime            SMALLINT    NOT NULL,
    debut                TIMESTAMP   NOT NULL,
    fin                  TIMESTAMP   NOT NULL,

    -- Phase ingestion (volumétrie DAT-2, appariement DAT-3) — NULL en phase calcul.
    communes_traitees    INTEGER,
    communes_referencees INTEGER,
    mutations_inserees   INTEGER,
    ventes_appariees     INTEGER,
    contours_charges     INTEGER,
    epci_charges         INTEGER,
    sans_donnees         INTEGER,

    -- Phase calcul (invariants INV-1..4, écarts REG-1) — NULL en phase ingestion.
    communes_calculees   INTEGER,
    refus                INTEGER,
    rejets_invariants    INTEGER,
    alertes_bord         INTEGER,
    inversions_dpe       INTEGER,
    ecarts_blocages      INTEGER,
    ecarts_alertes       INTEGER,
    ordre_canonique      TEXT,

    -- Communes en échec (codes INSEE) : la liste est actionnable, on la conserve.
    -- Les autres détails (mailles rejetées, écarts) restent dans
    -- coefficient_maille_historique, joignable par millésime.
    echecs               JSONB       NOT NULL DEFAULT '[]'::jsonb
);

-- L'écran admin liste par millésime (le plus récent d'abord) et par date d'exécution.
CREATE INDEX IF NOT EXISTS idx_rapport_batch_millesime
    ON data.rapport_batch (millesime DESC, departement);
CREATE INDEX IF NOT EXISTS idx_rapport_batch_debut
    ON data.rapport_batch (debut DESC);

-- Contrat A/B (décision D6) : l'app lit ce rapport en SELECT seul. Les privilèges
-- par défaut (migration 004) le couvrent déjà ; on l'explicite par lisibilité.
GRANT SELECT ON data.rapport_batch TO valeur_verte_app;

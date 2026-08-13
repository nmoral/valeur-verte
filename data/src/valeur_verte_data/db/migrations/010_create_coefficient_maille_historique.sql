-- Historique versionné des coefficients par maille — REG-1 (#129).
-- coefficient_maille reste la table **vive** (une ligne par commune, lue par l'app) ;
-- cette table conserve **tous les millésimes** (clé code_insee + millesime) pour
-- comparer un millésime au précédent (rapport d'écarts) et auditer les dérives.
-- Lève la dette « pas d'historique des coefficients » (S30). Mêmes colonnes que la
-- table vive, à l'identique du contrat de sortie du moteur.
CREATE TABLE IF NOT EXISTS data.coefficient_maille_historique (
    code_insee      CHAR(5)      NOT NULL,
    millesime       SMALLINT     NOT NULL,
    rayon_retenu_km SMALLINT     NOT NULL,
    n_transactions  INTEGER      NOT NULL,
    r2              NUMERIC(4,3),
    erreur_mediane  NUMERIC(5,2),
    coef_m2_bati    NUMERIC(8,2),
    coef_m2_terrain NUMERIC(8,2),
    coef_dpe        JSONB        NOT NULL,
    ic_dpe          JSONB,
    alerte          TEXT,
    mode            TEXT,
    palier          TEXT,
    bandes          JSONB,
    effectifs_dpe   JSONB,
    calcule_le      TIMESTAMP    NOT NULL DEFAULT now(),
    PRIMARY KEY (code_insee, millesime)
);

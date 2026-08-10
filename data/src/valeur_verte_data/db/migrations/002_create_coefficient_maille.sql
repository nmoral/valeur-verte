-- Coefficients de valeur verte pré-calculés par maille commune (§10.6, décision D7).
-- Une régression par commune, une ligne stockée ; la requête widget devient un
-- SELECT sur index, jamais un calcul à la volée.
CREATE TABLE IF NOT EXISTS data.coefficient_maille (
    code_insee      CHAR(5)      PRIMARY KEY,
    millesime       SMALLINT     NOT NULL,
    rayon_retenu_km SMALLINT     NOT NULL,
    n_transactions  INTEGER      NOT NULL,
    r2              NUMERIC(4,3),
    erreur_mediane  NUMERIC(5,2),            -- %, issu de la validation croisée (§5.4)
    coef_m2_bati    NUMERIC(8,2),
    coef_m2_terrain NUMERIC(8,2),
    coef_dpe        JSONB        NOT NULL,    -- {"A":68298,"B":46275,...} base D
    ic_dpe          JSONB,                    -- bornes basse/haute par classe DPE
    calcule_le      TIMESTAMP    NOT NULL DEFAULT now()
);

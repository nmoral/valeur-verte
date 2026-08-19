-- #261 : indicateur de régularisation du pooling hiérarchique (#249).
--
-- `poids_pooling` = min des poids w = τ²/(τ²+SE²) sur les classes DPE tirées vers le
-- fit départemental (w → 1 = maille quasi locale ; w → 0 = fortement rapprochée du
-- département). Sert le badge « partiellement rapprochée du département » côté
-- restitution. NULL quand la maille n'a été régularisée sur aucune classe.
--
-- Colonne sur l'historique (archive) ET la table vive (servie à l'app) ; les fonctions
-- de publication REG-2 (#130) sont remises à jour pour la projeter historique → vive.

ALTER TABLE data.coefficient_maille_historique
    ADD COLUMN IF NOT EXISTS poids_pooling DOUBLE PRECISION;

ALTER TABLE data.coefficient_maille
    ADD COLUMN IF NOT EXISTS poids_pooling DOUBLE PRECISION;

-- CREATE OR REPLACE conserve propriétaire et droits (GRANT EXECUTE, migration 012) :
-- inutile de re-GRANT. Seule la projection gagne `poids_pooling`.
CREATE OR REPLACE FUNCTION data.publier_millesime(p_millesime SMALLINT, p_auteur TEXT)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = data, pg_temp
AS $$
DECLARE
    v_publiees INTEGER;
BEGIN
    INSERT INTO data.coefficient_maille (
        code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
        coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte,
        mode, palier, bandes, effectifs_dpe, poids_pooling, calcule_le
    )
    SELECT code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
           coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte,
           mode, palier, bandes, effectifs_dpe, poids_pooling, calcule_le
    FROM data.coefficient_maille_historique
    WHERE millesime = p_millesime
      AND classe_ecart IS DISTINCT FROM 'blocage'
    ON CONFLICT (code_insee) DO UPDATE SET
        millesime = EXCLUDED.millesime,
        rayon_retenu_km = EXCLUDED.rayon_retenu_km,
        n_transactions = EXCLUDED.n_transactions,
        r2 = EXCLUDED.r2,
        erreur_mediane = EXCLUDED.erreur_mediane,
        coef_m2_bati = EXCLUDED.coef_m2_bati,
        coef_m2_terrain = EXCLUDED.coef_m2_terrain,
        coef_dpe = EXCLUDED.coef_dpe,
        ic_dpe = EXCLUDED.ic_dpe,
        alerte = EXCLUDED.alerte,
        mode = EXCLUDED.mode,
        palier = EXCLUDED.palier,
        bandes = EXCLUDED.bandes,
        effectifs_dpe = EXCLUDED.effectifs_dpe,
        poids_pooling = EXCLUDED.poids_pooling,
        calcule_le = EXCLUDED.calcule_le;
    GET DIAGNOSTICS v_publiees = ROW_COUNT;

    DELETE FROM data.coefficient_maille c
    USING data.maille_refusee r
    WHERE c.code_insee = r.code_insee
      AND r.millesime = p_millesime;

    INSERT INTO data.publication_millesime (millesime, etat, decide_par, mailles_publiees)
    VALUES (p_millesime, 'publie', p_auteur, v_publiees)
    ON CONFLICT (millesime) DO UPDATE SET
        etat = 'publie',
        decide_le = now(),
        decide_par = EXCLUDED.decide_par,
        mailles_publiees = EXCLUDED.mailles_publiees;

    RETURN v_publiees;
END;
$$;

CREATE OR REPLACE FUNCTION data.publier_maille(
    p_code_insee CHAR(5), p_millesime SMALLINT, p_auteur TEXT
)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = data, pg_temp
AS $$
DECLARE
    v_publiees INTEGER;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM data.publication_millesime
        WHERE millesime = p_millesime AND etat = 'publie'
    ) THEN
        RAISE EXCEPTION
            'Le millesime % n''est pas publie : debloquer une maille isolee creerait un parc mixte.',
            p_millesime;
    END IF;

    INSERT INTO data.coefficient_maille (
        code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
        coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte,
        mode, palier, bandes, effectifs_dpe, poids_pooling, calcule_le
    )
    SELECT code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
           coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte,
           mode, palier, bandes, effectifs_dpe, poids_pooling, calcule_le
    FROM data.coefficient_maille_historique
    WHERE code_insee = p_code_insee AND millesime = p_millesime
    ON CONFLICT (code_insee) DO UPDATE SET
        millesime = EXCLUDED.millesime,
        rayon_retenu_km = EXCLUDED.rayon_retenu_km,
        n_transactions = EXCLUDED.n_transactions,
        r2 = EXCLUDED.r2,
        erreur_mediane = EXCLUDED.erreur_mediane,
        coef_m2_bati = EXCLUDED.coef_m2_bati,
        coef_m2_terrain = EXCLUDED.coef_m2_terrain,
        coef_dpe = EXCLUDED.coef_dpe,
        ic_dpe = EXCLUDED.ic_dpe,
        alerte = EXCLUDED.alerte,
        mode = EXCLUDED.mode,
        palier = EXCLUDED.palier,
        bandes = EXCLUDED.bandes,
        effectifs_dpe = EXCLUDED.effectifs_dpe,
        poids_pooling = EXCLUDED.poids_pooling,
        calcule_le = EXCLUDED.calcule_le;
    GET DIAGNOSTICS v_publiees = ROW_COUNT;

    RETURN v_publiees;
END;
$$;

-- REG-2 (#130) : publication manuelle d'un millésime — jamais automatique.
--
-- Le batch calcule et archive ; il ne touche plus la table vive. Publier un
-- millésime est une **projection** de l'historique vers `coefficient_maille`,
-- déclenchée explicitement et tracée.
--
-- L'app est en LECTURE SEULE sur ce schéma (décision D6, migration 004) : elle ne
-- reçoit donc pas d'écriture sur les tables, seulement le droit d'exécuter les
-- fonctions ci-dessous. La logique reste entièrement côté `data`.

-- Verdict REG-1 porté par l'archive : sans lui, projeter « toutes les lignes du
-- millésime » publierait aussi les mailles jugées aberrantes. L'écart chiffré et le
-- coefficient fautif accompagnent la classe — l'écran de déblocage doit montrer
-- *pourquoi* une maille est écartée, pas seulement qu'elle l'est.
ALTER TABLE data.coefficient_maille_historique
    ADD COLUMN IF NOT EXISTS classe_ecart      TEXT,
    ADD COLUMN IF NOT EXISTS ecart             NUMERIC(8,4),
    ADD COLUMN IF NOT EXISTS ecart_coefficient TEXT;

-- Refus différés : une commune devenue non calculable ne disparaît du service qu'à
-- la publication, sinon un millésime abandonné l'aurait déjà retirée.
CREATE TABLE IF NOT EXISTS data.maille_refusee (
    code_insee CHAR(5)   NOT NULL,
    millesime  SMALLINT  NOT NULL,
    motif      TEXT      NOT NULL,
    refuse_le  TIMESTAMP NOT NULL DEFAULT now(),
    PRIMARY KEY (code_insee, millesime)
);

-- Décision de publication, un état par millésime.
CREATE TABLE IF NOT EXISTS data.publication_millesime (
    millesime        SMALLINT  PRIMARY KEY,
    etat             TEXT      NOT NULL CHECK (etat IN ('publie', 'abandonne')),
    decide_le        TIMESTAMP NOT NULL DEFAULT now(),
    decide_par       TEXT      NOT NULL,
    mailles_publiees INTEGER   NOT NULL DEFAULT 0
);

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
        mode, palier, bandes, effectifs_dpe, calcule_le
    )
    SELECT code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
           coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte,
           mode, palier, bandes, effectifs_dpe, calcule_le
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
        calcule_le = EXCLUDED.calcule_le;
    GET DIAGNOSTICS v_publiees = ROW_COUNT;

    -- Les refus du millésime prennent effet ici, et seulement ici.
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

CREATE OR REPLACE FUNCTION data.abandonner_millesime(p_millesime SMALLINT, p_auteur TEXT)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = data, pg_temp
AS $$
BEGIN
    -- Sans effet de bord : l'archive reste, la table vive n'est pas touchée. Seule
    -- la décision est enregistrée, pour que le millésime cesse d'être proposé.
    INSERT INTO data.publication_millesime (millesime, etat, decide_par, mailles_publiees)
    VALUES (p_millesime, 'abandonne', p_auteur, 0)
    ON CONFLICT (millesime) DO UPDATE SET
        etat = 'abandonne',
        decide_le = now(),
        decide_par = EXCLUDED.decide_par,
        mailles_publiees = 0;

    RETURN 0;
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
    -- Débloquer une maille d'un millésime non publié créerait une commune seule en
    -- N au milieu d'un parc en N-1. Le déblocage est une correction *après*
    -- publication, jamais un contournement de celle-ci.
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
        mode, palier, bandes, effectifs_dpe, calcule_le
    )
    SELECT code_insee, millesime, rayon_retenu_km, n_transactions, r2, erreur_mediane,
           coef_m2_bati, coef_m2_terrain, coef_dpe, ic_dpe, alerte,
           mode, palier, bandes, effectifs_dpe, calcule_le
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
        calcule_le = EXCLUDED.calcule_le;
    GET DIAGNOSTICS v_publiees = ROW_COUNT;

    RETURN v_publiees;
END;
$$;

-- Contrat A/B : l'app n'obtient que le droit d'invoquer ces trois actions.
GRANT EXECUTE ON FUNCTION data.publier_millesime(SMALLINT, TEXT) TO valeur_verte_app;
GRANT EXECUTE ON FUNCTION data.abandonner_millesime(SMALLINT, TEXT) TO valeur_verte_app;
GRANT EXECUTE ON FUNCTION data.publier_maille(CHAR(5), SMALLINT, TEXT) TO valeur_verte_app;

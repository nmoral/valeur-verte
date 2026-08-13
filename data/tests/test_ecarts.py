"""REG-1 (#129) — classification de l'écart entre deux millésimes.

Après un batch, chaque maille est comparée à son millésime précédent. L'écart
relatif classe la maille : sous le seuil d'alerte c'est **normal**, entre alerte et
blocage on **alerte** (revue manuelle), au-delà on **bloque** la publication de la
maille (§REG-1). Fonction pure : deux valeurs → une classe.
"""

from __future__ import annotations

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.ecarts import (
    ClasseEcart,
    EcartMaille,
    LigneEcart,
    RapportEcarts,
    classer_ecart,
    ecart_maille,
)

_SEUILS = {"seuil_alerte": 0.10, "seuil_blocage": 0.25}


def _maille(
    coef_dpe: dict[str, float], *, bati: float = 0.90, terrain: float = 0.30
) -> MailleCoefficient:
    """Maille minimale pour comparer deux millésimes (seuls les coefficients comptent)."""
    return MailleCoefficient(
        code_insee="52001",
        millesime=2024,
        mode=CoverageMode.LOCAL,
        rayon_retenu_km=10,
        n_transactions=60,
        r2=0.5,
        erreur_mediane=12.0,
        coef_m2_bati=bati,
        coef_m2_terrain=terrain,
        coef_dpe=coef_dpe,
        ic_dpe={},
        alerte=None,
    )


def test_ecart_sous_le_seuil_alerte_est_normal() -> None:
    assert classer_ecart(100.0, 105.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.NORMAL


def test_ecart_entre_alerte_et_blocage_alerte() -> None:
    assert classer_ecart(100.0, 115.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.ALERTE


def test_ecart_au_dela_du_blocage_bloque() -> None:
    assert classer_ecart(100.0, 130.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.BLOCAGE


def test_seuil_alerte_inclus_dans_alerte() -> None:
    # « 10-25 % : alerte » — à 10 % pile, on alerte (borne basse incluse).
    assert classer_ecart(100.0, 110.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.ALERTE


def test_seuil_blocage_inclus_dans_alerte() -> None:
    # « 10-25 % alerte ; > 25 % blocage » — à 25 % pile, encore en alerte (borne haute incluse).
    assert classer_ecart(100.0, 125.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.ALERTE


def test_une_baisse_compte_comme_une_hausse() -> None:
    # Écart relatif en valeur absolue : |nouveau - ancien| / |ancien|.
    assert classer_ecart(100.0, 70.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.BLOCAGE


def test_ecart_sur_coefficient_negatif() -> None:
    # Effets DPE en log (négatifs) : l'écart se rapporte à |ancien|.
    # -0,20 → -0,30 = 50 % d'écart → blocage.
    assert classer_ecart(-0.20, -0.30, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.BLOCAGE


def test_apparition_depuis_zero_bloque() -> None:
    # Ancien nul, nouveau non nul : écart indéfini → blocage (changement majeur à revoir).
    assert classer_ecart(0.0, 0.10, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.BLOCAGE


def test_zero_vers_zero_est_normal() -> None:
    # Deux valeurs nulles (ex. référence D) : aucun écart.
    assert classer_ecart(0.0, 0.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.NORMAL


# --- ecart_maille : écart d'une maille au millésime précédent (métrique exp(coef)) ---
# DPE mesuré sur le facteur de prix exp(coef) (décision produit) ; élasticités m²
# sur la valeur brute ; la maille prend le PIRE écart de ses coefficients.


def test_ecart_maille_identique_est_normal() -> None:
    m = _maille({"D": 0.0, "F": -0.20})
    assert ecart_maille(m, m, **_SEUILS).classe is ClasseEcart.NORMAL


def test_ecart_dpe_mesure_sur_l_effet_exp_pas_le_log_brut() -> None:
    """-0,20 → -0,30 = 50 % en log **brut**, mais ~9,5 % sur exp(coef) (le facteur
    de prix que voit le produit) → NORMAL. Preuve que la métrique est bien exp."""
    ancien = _maille({"D": 0.0, "F": -0.20})
    nouveau = _maille({"D": 0.0, "F": -0.30})
    assert ecart_maille(ancien, nouveau, **_SEUILS).classe is ClasseEcart.NORMAL


def test_ecart_dpe_en_alerte_sur_l_effet() -> None:
    # exp(-0,20)=0,819 → exp(-0,362)=0,696 : ~15 % d'écart sur le facteur de prix.
    ancien = _maille({"D": 0.0, "F": -0.20})
    nouveau = _maille({"D": 0.0, "F": -0.362})
    assert ecart_maille(ancien, nouveau, **_SEUILS).classe is ClasseEcart.ALERTE


def test_ecart_elasticite_bati_bloque() -> None:
    # Élasticité brute : 0,90 → 1,20 = 33 % → blocage.
    ancien = _maille({"D": 0.0, "F": -0.20}, bati=0.90)
    nouveau = _maille({"D": 0.0, "F": -0.20}, bati=1.20)
    assert ecart_maille(ancien, nouveau, **_SEUILS).classe is ClasseEcart.BLOCAGE


def test_la_maille_prend_le_pire_ecart_de_ses_coefficients() -> None:
    # DPE quasi stable (normal) mais élasticité bâti explosée (blocage) → blocage.
    ancien = _maille({"D": 0.0, "F": -0.20}, bati=0.90)
    nouveau = _maille({"D": 0.0, "F": -0.21}, bati=1.20)
    assert ecart_maille(ancien, nouveau, **_SEUILS).classe is ClasseEcart.BLOCAGE


def test_changement_de_l_ensemble_des_classes_publiees_alerte() -> None:
    """Une classe qui apparaît/disparaît (rebanding entre millésimes) est un
    changement structurel à revoir → au moins une alerte, même coefficients stables."""
    ancien = _maille({"D": 0.0, "F": -0.20})
    nouveau = _maille({"D": 0.0, "F": -0.20, "C": 0.10})
    assert ecart_maille(ancien, nouveau, **_SEUILS).classe is ClasseEcart.ALERTE


# --- ecart_maille remonte le détail (classe + écart + coefficient en cause) ---


def test_ecart_maille_retourne_un_ecart_detaille() -> None:
    m = _maille({"D": 0.0, "F": -0.20})
    resultat = ecart_maille(m, m, **_SEUILS)
    assert isinstance(resultat, EcartMaille)
    assert resultat.classe is ClasseEcart.NORMAL


def test_ecart_maille_nomme_l_elasticite_en_cause() -> None:
    # Bâti explosé (0,90 → 1,20 = 33 %), DPE stable : le coefficient en cause est le bâti.
    ancien = _maille({"D": 0.0, "F": -0.20}, bati=0.90)
    nouveau = _maille({"D": 0.0, "F": -0.21}, bati=1.20)
    resultat = ecart_maille(ancien, nouveau, **_SEUILS)
    assert resultat.classe is ClasseEcart.BLOCAGE
    assert resultat.coefficient == "élasticité bâti"
    assert round(resultat.ecart, 2) == 0.33


def test_ecart_maille_nomme_la_classe_dpe_en_cause() -> None:
    # Seule F dérive (sur exp) : le coefficient en cause est « DPE F ».
    ancien = _maille({"D": 0.0, "F": -0.20})
    nouveau = _maille({"D": 0.0, "F": -0.362})
    resultat = ecart_maille(ancien, nouveau, **_SEUILS)
    assert resultat.classe is ClasseEcart.ALERTE
    assert resultat.coefficient == "DPE F"


def test_ecart_maille_structurel_nomme_les_classes_publiees() -> None:
    ancien = _maille({"D": 0.0, "F": -0.20})
    nouveau = _maille({"D": 0.0, "F": -0.20, "C": 0.10})
    resultat = ecart_maille(ancien, nouveau, **_SEUILS)
    assert resultat.classe is ClasseEcart.ALERTE
    assert resultat.coefficient == "classes publiées"


# --- RapportEcarts : le rapport dédié, une ligne par maille comparée (REG-1) ---


def test_rapport_ecarts_accumule_une_ligne_par_maille() -> None:
    rapport = RapportEcarts()
    rapport.ajouter("52001", EcartMaille(ClasseEcart.BLOCAGE, 0.30, "élasticité bâti"))
    rapport.ajouter("52002", EcartMaille(ClasseEcart.ALERTE, 0.15, "DPE F"))
    rapport.ajouter("52003", EcartMaille(ClasseEcart.NORMAL, 0.02, "DPE D"))

    assert rapport.lignes[0] == LigneEcart("52001", ClasseEcart.BLOCAGE, 0.30, "élasticité bâti")
    assert len(rapport.lignes) == 3


def test_rapport_ecarts_isole_blocages_et_alertes() -> None:
    rapport = RapportEcarts()
    rapport.ajouter("52001", EcartMaille(ClasseEcart.BLOCAGE, 0.30, "élasticité bâti"))
    rapport.ajouter("52002", EcartMaille(ClasseEcart.ALERTE, 0.15, "DPE F"))
    rapport.ajouter("52003", EcartMaille(ClasseEcart.NORMAL, 0.02, "DPE D"))

    assert [ligne.code_insee for ligne in rapport.blocages] == ["52001"]
    assert [ligne.code_insee for ligne in rapport.alertes] == ["52002"]

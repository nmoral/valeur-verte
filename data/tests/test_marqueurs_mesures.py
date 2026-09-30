"""Marqueurs mesurés (#341, Lot 2) — dérivés de nos données (``coefficient_maille``).

Trois marqueurs **de type mesure** (des faits, sans badge hypothèse) :

- ``levier_renovation`` fort/moyen/faible — tertiles nationaux du gain E→C ;
- ``sensibilite_dpe`` forte/modérée/faible — tertiles nationaux de l'amplitude A↔G ;
- ``fiabilite`` solide/correct/fragile — effectif et rayon de la maille.

Ici, la brique de base : convertir un écart de coefficients DPE (effet log, base D)
en pourcentage lisible, comme le fait déjà la restitution (``exp(Δcoef) − 1``).
"""

from __future__ import annotations

from valeur_verte_data.marqueurs import TYPE_MESURE
from valeur_verte_data.marqueurs_mesures import (
    MailleMesurable,
    amplitude_ag,
    fiabilite,
    gain_ec,
    levier_renovation,
    marqueurs_mesures_departement,
    sensibilite_dpe,
    tertiles,
)


def _maille(code: str, *, cC: float | None = None, n: int = 100, rayon: int | None = 15) -> MailleMesurable:
    """Une maille de test : gain E→C piloté par ``cC`` (base E=0), amplitude A↔G large."""
    coef: dict[str, float] = {"D": 0.0, "E": 0.0, "A": 0.6, "G": -0.3}
    if cC is not None:
        coef["C"] = cC
    else:
        del coef["A"]  # échelle tronquée : ni gain E→C (pas de C) ni amplitude (pas de A)
    return MailleMesurable(code_insee=code, coef_dpe=coef, n_transactions=n, rayon_retenu_km=rayon)


# Distribution nationale (6 communes, 3 départements) — tertiles calculés dessus.
_NATIONAL = [
    _maille("31001", cC=0.10, n=120, rayon=15),  # gain 11 → faible ; solide
    _maille("31002", cC=0.30, n=60, rayon=15),  # gain 35 → moyen ; correct
    _maille("31003", cC=0.55, n=30, rayon=10),  # gain 73 → fort ; fragile
    _maille("75056", cC=0.45, n=90, rayon=18),  # dept 75, hors périmètre écrit
    _maille("01001", cC=0.05, n=200, rayon=5),  # dept 01
    _maille("01002", cC=0.60, n=200, rayon=5),  # dept 01
]


def test_gain_ec_convertit_l_ecart_e_vers_c_en_pourcentage() -> None:
    # Δcoef = ln(1.35) ≈ 0.3001 → +35 % (exp(Δ) − 1).
    assert gain_ec({"C": 0.3001, "E": 0.0, "D": 0.0}) == 35


def test_gain_ec_absent_si_une_des_deux_classes_manque() -> None:
    assert gain_ec({"C": 0.30, "D": 0.0}) is None  # pas de E
    assert gain_ec({"E": -0.10, "D": 0.0}) is None  # pas de C


def test_amplitude_ag_mesure_l_ecart_a_vers_g_en_pourcentage() -> None:
    # exp(0.5 − (−0.4)) − 1 = exp(0.9) − 1 ≈ 1.4596 → +146 %.
    assert amplitude_ag({"A": 0.5, "G": -0.4, "D": 0.0}) == 146


def test_amplitude_ag_absente_si_une_des_deux_classes_manque() -> None:
    assert amplitude_ag({"A": 0.5, "D": 0.0}) is None  # pas de G
    assert amplitude_ag({"G": -0.4, "D": 0.0}) is None  # pas de A


def test_fiabilite_fragile_sous_l_effectif_plancher_ou_sans_rayon_local() -> None:
    assert fiabilite(30, 10) == "fragile"  # trop peu de ventes
    # Rayon absent = repli EPCI/départemental : jamais solide, quelle que soit la taille.
    assert fiabilite(500, None) == "fragile"


def test_fiabilite_solide_sur_gros_echantillon_local_et_resserre() -> None:
    assert fiabilite(100, 15) == "solide"
    assert fiabilite(80, 20) == "solide"  # bornes incluses (n ≥ 80, rayon ≤ 20)


def test_fiabilite_correct_entre_les_deux() -> None:
    assert fiabilite(60, 15) == "correct"  # effectif intermédiaire
    assert fiabilite(120, 25) == "correct"  # gros échantillon mais rayon trop large


def test_tertiles_separent_la_distribution_en_trois() -> None:
    bas, haut = tertiles(list(range(0, 99)))  # 0..98, réparti uniformément
    assert bas < haut
    assert 25 < bas < 40  # ~ p33
    assert 60 < haut < 75  # ~ p66


def test_tertiles_degeneres_sur_moins_de_deux_valeurs() -> None:
    # Pas de distribution nationale exploitable : seuils confondus, jamais d'erreur.
    assert tertiles([42.0]) == (42.0, 42.0)
    assert tertiles([]) == (0.0, 0.0)


def test_levier_renovation_classe_le_gain_sur_les_tertiles_nationaux() -> None:
    seuils = (20.0, 40.0)
    assert levier_renovation(10, seuils) == "faible"
    assert levier_renovation(30, seuils) == "moyen"
    assert levier_renovation(50, seuils) == "fort"
    # Bornes : ≤ p33 → faible, ≥ p66 → fort.
    assert levier_renovation(20, seuils) == "faible"
    assert levier_renovation(40, seuils) == "fort"


def test_sensibilite_dpe_classe_l_amplitude_sur_les_tertiles_nationaux() -> None:
    seuils = (100.0, 200.0)
    assert sensibilite_dpe(50, seuils) == "faible"
    assert sensibilite_dpe(150, seuils) == "modérée"
    assert sensibilite_dpe(250, seuils) == "forte"


def test_marqueurs_mesures_ne_couvre_que_le_departement_demande() -> None:
    marqueurs = marqueurs_mesures_departement(_NATIONAL, "31", 2025)

    assert {m.code_insee for m in marqueurs} == {"31001", "31002", "31003"}
    for m in marqueurs:
        assert m.code_departement is None
        assert m.type_marqueur == TYPE_MESURE
        assert "2025" in m.source  # source versionnée par millésime


def test_marqueurs_mesures_classe_chaque_commune_sur_la_distribution_nationale() -> None:
    marqueurs = marqueurs_mesures_departement(_NATIONAL, "31", 2025)
    par_cle = {(m.code_insee, m.nom): m.valeur for m in marqueurs}

    # Tertiles calculés sur les 6 communes nationales, pas sur les 3 du dept 31.
    assert par_cle[("31001", "levier_renovation")] == "faible"
    assert par_cle[("31002", "levier_renovation")] == "moyen"
    assert par_cle[("31003", "levier_renovation")] == "fort"
    assert par_cle[("31001", "sensibilite_dpe")] == "faible"
    assert par_cle[("31002", "sensibilite_dpe")] == "modérée"
    assert par_cle[("31003", "sensibilite_dpe")] == "forte"
    # Fiabilité : dérivée de (n, rayon) de chaque maille.
    assert par_cle[("31001", "fiabilite")] == "solide"
    assert par_cle[("31002", "fiabilite")] == "correct"
    assert par_cle[("31003", "fiabilite")] == "fragile"


def test_une_maille_a_l_echelle_tronquee_garde_sa_fiabilite() -> None:
    """Sans classe C ni A, ni gain ni amplitude ne sont calculables — mais la fiabilité,
    elle, ne dépend que de l'effectif et du rayon : elle reste émise."""
    national = [*_NATIONAL, _maille("31099", cC=None, n=200, rayon=5)]

    marqueurs = marqueurs_mesures_departement(national, "31", 2025)
    noms_31099 = {m.nom for m in marqueurs if m.code_insee == "31099"}

    assert noms_31099 == {"fiabilite"}
    assert next(m for m in marqueurs if m.code_insee == "31099").valeur == "solide"

"""Marqueurs mesurés (#341, Lot 2) — dérivés de nos données (``coefficient_maille``).

Trois marqueurs **de type mesure** (des faits, sans badge hypothèse) :

- ``levier_renovation`` fort/moyen/faible — tertiles nationaux du gain E→C ;
- ``sensibilite_dpe`` forte/modérée/faible — tertiles nationaux de l'amplitude A↔G ;
- ``fiabilite`` solide/correct/fragile — effectif et rayon de la maille.

Ici, la brique de base : convertir un écart de coefficients DPE (effet log, base D)
en pourcentage lisible, comme le fait déjà la restitution (``exp(Δcoef) − 1``).
"""

from __future__ import annotations

from valeur_verte_data.marqueurs_mesures import amplitude_ag, fiabilite, gain_ec


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

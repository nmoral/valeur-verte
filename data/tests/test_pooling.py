"""Pooling partiel hiérarchique (#249) — cœur empirical-Bayes, pur.

Chaque effet DPE communal ``β̂`` (de variance d'échantillonnage ``v = SE²``) est tiré
vers l'effet départemental ``μ`` (le fit poolé, D-E) proportionnellement à sa précision :
``θ̂ = μ + w·(β̂ − μ)``, ``w = τ²/(τ²+v)``. La variance inter-communes ``τ̂²`` s'estime en
forme fermée (méthode des moments précision-pondérée, autour du centre fixe ``μ`` :
``E[Σ w (β̂ − μ)²] = τ²·Σw + M``). Décisions **pures** : des estimations `(β̂, v)` et un
centre entrent, un τ̂² / un effet mélangé sortent.
"""

from __future__ import annotations

import math

import pytest

from valeur_verte_data.pooling import (
    effet_pool,
    poids_pool,
    variance_inter,
    variance_posterieure,
)


# --- poids_pool : w = τ²/(τ²+v) ---


def test_poids_pool_melange_a_parts_egales_quand_tau2_egale_variance() -> None:
    assert poids_pool(1.0, 1.0) == 0.5


def test_poids_pool_variance_infinie_donne_zero() -> None:
    # SE = ∞ (maille sans info, n ≤ p) → poids nul → tirée entièrement vers le prior.
    assert poids_pool(1.0, math.inf) == 0.0


def test_poids_pool_variance_nulle_garde_le_local() -> None:
    # Précision infinie (v = 0) → poids 1 → estimation locale conservée.
    assert poids_pool(1.0, 0.0) == 1.0


def test_poids_pool_grande_dispersion_tend_vers_le_local() -> None:
    # τ² ≫ v (communes très hétérogènes) → poids ≈ 1 (on respecte le signal local).
    assert poids_pool(1e9, 1.0) == pytest.approx(1.0)


def test_poids_pool_tau2_nul_donne_zero() -> None:
    # τ² = 0 (communes homogènes) → poids nul → pooling complet (μ partout).
    assert poids_pool(0.0, 1.0) == 0.0


# --- effet_pool : θ̂ = μ + w·(β̂ − μ) ---


def test_effet_pool_melange_vers_la_cible() -> None:
    # μ=0, β̂=1, τ²=1, v=1 → w=0,5 → θ̂ = 0,5 (à mi-chemin).
    assert effet_pool(1.0, 0.0, 1.0, 1.0) == 0.5


def test_effet_pool_pooling_complet_rend_la_cible() -> None:
    # τ² = 0 → θ̂ = μ (l'effet de la classe est homogène dans le département).
    assert effet_pool(1.0, 0.3, 0.0, 1.0) == 0.3


def test_effet_pool_sans_info_rend_la_cible() -> None:
    # v = ∞ → θ̂ = μ (aucune information locale, on sert le prior).
    assert effet_pool(1.0, 0.3, 2.0, math.inf) == 0.3


def test_effet_pool_precision_infinie_garde_le_local() -> None:
    # v = 0 → θ̂ = β̂ (effet parfaitement connu, jamais tiré).
    assert effet_pool(1.0, 0.3, 1.0, 0.0) == 1.0


# --- variance_inter : τ̂² (méthode des moments autour du centre μ) ---


def test_variance_inter_communes_homogenes_est_nulle() -> None:
    # Estimations dispersées seulement par leur bruit (proches de μ relativement à v) :
    # la variance inter-communes est nulle (plancher 0) → pooling complet.
    estimations = [(0.05, 1.0), (-0.05, 1.0), (0.05, 1.0), (-0.05, 1.0)]
    assert variance_inter(estimations, 0.0) == 0.0


def test_variance_inter_communes_dispersees_est_positive() -> None:
    # Écarts ±1 très au-delà du bruit (v=0,01) : Σw(β̂−μ)² = 400, M=4, Σw=400 ⇒
    # τ̂² = (400 − 4)/400 = 0,99.
    estimations = [(1.0, 0.01), (-1.0, 0.01), (1.0, 0.01), (-1.0, 0.01)]
    assert variance_inter(estimations, 0.0) == pytest.approx(0.99)


def test_variance_inter_ignore_les_variances_infinies() -> None:
    # Une maille sans info (v=∞) ne pèse ni sur μ ni sur τ̂² : le résultat est celui
    # des deux estimations finies (τ̂² = (200 − 2)/200 = 0,99).
    estimations = [(1.0, 0.01), (-1.0, 0.01), (5.0, math.inf)]
    assert variance_inter(estimations, 0.0) == pytest.approx(0.99)


def test_variance_inter_moins_de_deux_estimations_finies_est_indefinie() -> None:
    # Une seule commune (ou aucune finie) : τ² non séparable du bruit → None
    # (l'appelant garde alors le local, pas de shrinkage possible).
    assert variance_inter([(1.0, 0.01)], 0.0) is None
    assert variance_inter([(1.0, math.inf), (2.0, math.inf)], 0.0) is None
    assert variance_inter([], 0.0) is None


def test_variance_inter_est_independante_de_l_ordre() -> None:
    # Déterminisme (INV-5) : des sommes commutatives, l'ordre n'influe pas.
    a = [(1.0, 0.01), (-1.0, 0.02), (0.5, 0.03)]
    assert variance_inter(a, 0.0) == variance_inter(list(reversed(a)), 0.0)


# --- variance_posterieure : τ²·v/(τ²+v) (IC honnête, D-H) ---


def test_variance_posterieure_combine_prior_et_bruit() -> None:
    assert variance_posterieure(1.0, 1.0) == 0.5


def test_variance_posterieure_sans_info_vaut_le_prior() -> None:
    # v = ∞ → variance a posteriori = τ² (on ne sait que le prior).
    assert variance_posterieure(2.0, math.inf) == 2.0


def test_variance_posterieure_pooling_complet_est_nulle() -> None:
    # τ² = 0 → effet connu (= μ) → variance a posteriori nulle.
    assert variance_posterieure(0.0, 1.0) == 0.0

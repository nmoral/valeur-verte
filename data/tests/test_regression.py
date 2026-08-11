"""Tests de la régression pondérée (§5.2)."""

from __future__ import annotations

import math

from valeur_verte_data.regression import gaussian_weight, is_pre_1989


def test_gaussian_weight_maximal_a_distance_nulle() -> None:
    assert gaussian_weight(0.0) == 1.0


def test_gaussian_weight_decroit_avec_la_distance() -> None:
    assert gaussian_weight(10.0) < gaussian_weight(5.0) < 1.0


def test_gaussian_weight_a_un_sigma_vaut_exp_moins_un_demi() -> None:
    assert round(gaussian_weight(10.0, sigma_km=10.0), 6) == round(math.exp(-0.5), 6)


def test_is_pre_1989_reconnait_le_bati_ancien() -> None:
    assert is_pre_1989("1948-1974")
    assert is_pre_1989("avant 1948")
    assert is_pre_1989("1983-1988")


def test_is_pre_1989_rejette_le_bati_recent() -> None:
    assert not is_pre_1989("1989-2000")
    assert not is_pre_1989("après 2012")


def test_is_pre_1989_rejette_l_inconnu() -> None:
    assert not is_pre_1989("")
    assert not is_pre_1989(None)
    assert not is_pre_1989("inconnu")

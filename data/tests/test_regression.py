"""Tests de la régression pondérée (§5.2)."""

from __future__ import annotations

import math
from dataclasses import replace

import pytest

from valeur_verte_data.dvf import Mutation, aggregate_mutations
from valeur_verte_data.regression import (
    InsufficientSampleError,
    fit,
    gaussian_weight,
    is_pre_1989,
)

_SURFACES = ((80, 200), (100, 300), (120, 500))
_DPE_EFFECT = {"D": 0, "F": -25000, "C": 15000}


def _house(
    dpe: str,
    bati: int,
    terrain: int,
    prix: int,
    *,
    insee: str = "31555",
    year: int = 2024,
    periode: str = "1948-1974",
) -> Mutation:
    (base,) = aggregate_mutations(
        [
            {
                "id_mutation": f"{insee}-{dpe}-{bati}-{terrain}-{prix}",
                "valeur_fonciere": str(prix),
                "code_commune": insee,
                "surface_reelle_bati": str(bati),
                "surface_terrain": str(terrain),
                "nature_culture": "S",
                "id_parcelle": f"p-{bati}-{terrain}-{prix}",
                "latitude": "43.6",
                "longitude": "1.44",
            }
        ],
        millesime=year,
    )
    return replace(base, etiquette_dpe=dpe, periode_construction=periode)


def _sample_connu(insee: str = "31555", base_shift: int = 0) -> list[Mutation]:
    return [
        _house(dpe, bati, terrain, 1200 * bati + 40 * terrain + effect + base_shift, insee=insee)
        for dpe, effect in _DPE_EFFECT.items()
        for bati, terrain in _SURFACES
    ]


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


def test_fit_recupere_les_coefficients_connus() -> None:
    result = fit(_sample_connu(), 43.6, 1.44, reference_dpe="D")

    assert result.n == 9
    assert result.coef_dpe["D"] == 0.0
    assert round(result.coef_dpe["F"]) == -25000
    assert round(result.coef_dpe["C"]) == 15000
    assert round(result.coef_m2_bati) == 1200
    assert round(result.coef_m2_terrain) == 40
    assert result.r2 > 0.999


def test_fit_exclut_le_bati_recent() -> None:
    recent = _house("F", 100, 300, 999_999, periode="1990-2000")

    result = fit([*_sample_connu(), recent], 43.6, 1.44)

    assert result.n == 9


def test_fit_exclut_les_mutations_sans_etiquette() -> None:
    sans_dpe = replace(_house("D", 100, 300, 132_000), etiquette_dpe=None)

    result = fit([*_sample_connu(), sans_dpe], 43.6, 1.44)

    assert result.n == 9


def test_fit_echantillon_vide_apres_restriction_leve() -> None:
    with pytest.raises(InsufficientSampleError):
        fit([_house("D", 100, 300, 132_000, periode="2000-2010")], 43.6, 1.44)


def test_fit_effets_fixes_commune_isolent_le_coef_dpe() -> None:
    # Commune B décalée de +50 000 € : l'effet fixe commune doit l'absorber.
    sample = _sample_connu("31555", 0) + _sample_connu("31300", 50_000)

    result = fit(sample, 43.6, 1.44)

    assert round(result.coef_dpe["F"]) == -25000
    assert round(result.coef_dpe["C"]) == 15000

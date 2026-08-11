"""Tests de la validation croisée / erreur médiane (§5.4)."""

from __future__ import annotations

import math
from dataclasses import replace

from valeur_verte_data.cross_validation import median_relative_error
from valeur_verte_data.dvf import Mutation, aggregate_mutations


def _house(dpe: str, bati: int, prix: int, *, insee: str = "31555") -> Mutation:
    (base,) = aggregate_mutations(
        [
            {
                "id_mutation": f"{insee}-{dpe}-{bati}-{prix}",
                "valeur_fonciere": str(prix),
                "code_commune": insee,
                "surface_reelle_bati": str(bati),
                "latitude": "43.6",
                "longitude": "1.44",
            }
        ],
        millesime=2024,
    )
    return replace(base, etiquette_dpe=dpe, periode_construction="1948-1974")


# Effets en logarithme : le modèle est log-linéaire (#115).
_EFFECT = {"D": 0.0, "F": -0.20, "C": 0.10}


def _parfait() -> list[Mutation]:
    return [
        _house(dpe, bati, round(3000.0 * bati**0.9 * math.exp(effect)))
        for dpe, effect in _EFFECT.items()
        for bati in range(80, 130, 5)
    ]


def _bruite() -> list[Mutation]:
    sample: list[Mutation] = []
    for dpe, effect in _EFFECT.items():
        for i, bati in enumerate(range(80, 130, 5)):
            noise = 8000 if i % 2 == 0 else -8000
            sample.append(_house(dpe, bati, 1000 * bati + effect + noise))
    return sample


def test_median_relative_error_quasi_nulle_sur_donnees_parfaites() -> None:
    error = median_relative_error(_parfait(), 43.6, 1.44)

    assert error is not None
    assert error < 0.01


def test_median_relative_error_positive_sur_donnees_bruitees() -> None:
    error = median_relative_error(_bruite(), 43.6, 1.44)

    assert error is not None
    # Valeur dépendante de l'échelle d'ajustement ; l'intention est qu'un bruit
    # injecté ressorte comme une erreur franche mais non aberrante.
    assert 5.0 < error < 15.0  # médiane, exprimée en %


def test_median_relative_error_echantillon_trop_petit_donne_none() -> None:
    assert median_relative_error([_house("D", 100, 100_000)], 43.6, 1.44) is None


def test_median_relative_error_est_deterministe() -> None:
    sample = _bruite()

    assert median_relative_error(sample, 43.6, 1.44) == median_relative_error(sample, 43.6, 1.44)


def test_median_relative_error_exactement_k_lignes() -> None:
    sample = [_house("D", bati, 1000 * bati) for bati in (80, 90, 100, 110, 120)]

    assert median_relative_error(sample, 43.6, 1.44) is not None


def test_median_relative_error_ignore_les_prix_nuls() -> None:
    # Un prix nul ne doit pas provoquer de division par zéro.
    sample = [*_parfait(), replace(_house("D", 100, 100_000), prix=0.0)]

    assert median_relative_error(sample, 43.6, 1.44) is not None

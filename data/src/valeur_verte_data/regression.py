"""Régression linéaire pondérée pour les coefficients DPE (§5.2).

Modèle : ``prix ~ surface_bati + surface_terrain + dummies_DPE + effets_fixes
commune + effets_fixes_annee``, estimé par moindres carrés **pondérés** (poids
gaussien décroissant avec la distance). Restriction au **bâti ancien (< 1989)** :
cœur méthodologique, on mesure le gain réel de rénovation sans taux de capture.
"""

from __future__ import annotations

import math
import re
from collections.abc import Iterable, Sequence
from dataclasses import dataclass

import numpy as np
from numpy.typing import NDArray

from .dvf import Mutation
from .radius import haversine_km

DEFAULT_SIGMA_KM = 10.0
DEFAULT_REFERENCE_DPE = "D"
_YEAR_RE = re.compile(r"\d{4}")


class InsufficientSampleError(Exception):
    """L'échantillon est vide après restriction au bâti ancien."""


@dataclass(frozen=True)
class _Row:
    """Ligne éligible à la régression (champs requis non nuls)."""

    prix: float
    bati: float
    terrain: float
    dpe: str
    insee: str
    annee: str
    lat: float
    lon: float


@dataclass(frozen=True)
class RegressionResult:
    """Coefficients estimés et qualité d'ajustement pondérée."""

    coef_dpe: dict[str, float]
    coef_m2_bati: float
    coef_m2_terrain: float
    n: int
    r2: float


def gaussian_weight(distance_km: float, sigma_km: float = DEFAULT_SIGMA_KM) -> float:
    """Poids gaussien ``exp(-d² / 2σ²)`` (1 au centre, décroissant avec la distance)."""
    return math.exp(-(distance_km**2) / (2 * sigma_km**2))


def is_pre_1989(periode: str | None) -> bool:
    """Vrai si la période de construction est **antérieure à 1989** (bâti ancien).

    Les périodes ADEME sont des tranches (« avant 1948 », « 1975-1977 »…) : on
    retient l'année la plus tardive et on la compare à 1989. Période absente ou
    non datée → ``False`` (on n'inclut que le bâti ancien avéré).
    """
    if not periode:
        return False
    years = [int(match) for match in _YEAR_RE.findall(periode)]
    if not years:
        return False
    return max(years) < 1989


def _eligible(sample: Iterable[Mutation]) -> list[_Row]:
    """Filtre les mutations exploitables et restreint au bâti ancien (§5.2)."""
    rows: list[_Row] = []
    for mutation in sample:
        if mutation.prix is None or mutation.surface_bati is None or mutation.surface_bati <= 0:
            continue
        if mutation.etiquette_dpe is None:
            continue
        if mutation.latitude is None or mutation.longitude is None:
            continue
        if not is_pre_1989(mutation.periode_construction):
            continue
        rows.append(
            _Row(
                prix=mutation.prix,
                bati=mutation.surface_bati,
                terrain=mutation.surface_terrain or 0.0,
                dpe=mutation.etiquette_dpe,
                insee=mutation.code_insee,
                annee=str(mutation.millesime),
                lat=mutation.latitude,
                lon=mutation.longitude,
            )
        )
    return rows


def _dummies(
    values: Sequence[str], reference: str | None = None
) -> tuple[NDArray[np.float64], list[str]]:
    """Matrice d'indicatrices (une colonne par niveau sauf la référence)."""
    levels = sorted(set(values))
    matrix = np.zeros((len(values), 0))
    if not levels:
        return matrix, []
    ref = reference if reference in levels else levels[0]
    kept = [level for level in levels if level != ref]
    index = {level: column for column, level in enumerate(kept)}
    matrix = np.zeros((len(values), len(kept)))
    for row, value in enumerate(values):
        if value in index:
            matrix[row, index[value]] = 1.0
    return matrix, kept


def fit(
    sample: Iterable[Mutation],
    center_lat: float,
    center_lon: float,
    *,
    sigma_km: float = DEFAULT_SIGMA_KM,
    reference_dpe: str = DEFAULT_REFERENCE_DPE,
) -> RegressionResult:
    """Estime les coefficients DPE par moindres carrés pondérés (§5.2)."""
    rows = _eligible(sample)
    n = len(rows)
    if n == 0:
        raise InsufficientSampleError("échantillon vide après restriction au bâti ancien")

    y = np.array([row.prix for row in rows], dtype=float)
    bati = np.array([row.bati for row in rows], dtype=float)
    terrain = np.array([row.terrain for row in rows], dtype=float)
    dpe_matrix, dpe_levels = _dummies([row.dpe for row in rows], reference=reference_dpe)
    commune_matrix, _ = _dummies([row.insee for row in rows])
    annee_matrix, _ = _dummies([row.annee for row in rows])

    design = np.hstack(
        [
            np.ones((n, 1)),
            bati[:, None],
            terrain[:, None],
            dpe_matrix,
            commune_matrix,
            annee_matrix,
        ]
    )

    weights = np.array(
        [
            gaussian_weight(haversine_km(center_lat, center_lon, row.lat, row.lon), sigma_km)
            for row in rows
        ],
        dtype=float,
    )
    sqrt_w = np.sqrt(weights)
    beta, _, _, _ = np.linalg.lstsq(design * sqrt_w[:, None], y * sqrt_w, rcond=None)

    dpe_values = {row.dpe for row in rows}
    used_reference = reference_dpe if reference_dpe in dpe_values else sorted(dpe_values)[0]
    coef_dpe = {used_reference: 0.0}
    for offset, level in enumerate(dpe_levels):
        coef_dpe[level] = float(beta[3 + offset])

    predictions = design @ beta
    weighted_mean = float(np.sum(weights * y) / np.sum(weights))
    ss_res = float(np.sum(weights * (y - predictions) ** 2))
    ss_tot = float(np.sum(weights * (y - weighted_mean) ** 2))
    r2 = 1.0 - ss_res / ss_tot if ss_tot > 0 else 0.0

    return RegressionResult(
        coef_dpe=coef_dpe,
        coef_m2_bati=float(beta[1]),
        coef_m2_terrain=float(beta[2]),
        n=n,
        r2=r2,
    )

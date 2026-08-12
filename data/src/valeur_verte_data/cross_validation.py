"""Validation croisée et erreur médiane publiée par maille (§5.4).

On mesure la justesse **prédictive** du modèle : k plis déterministes (20 % par
pli), on entraîne sans un pli et on prédit ses ventes, puis on agrège l'erreur
relative. La métrique publiée est la **médiane en %** — défendable devant un
client, contrairement à un R². Plis déterministes (``i % k``) → reproductible.
"""

from __future__ import annotations

from collections.abc import Iterable

import numpy as np

from .dvf import Mutation
from .regression import (
    DEFAULT_REFERENCE_DPE,
    DEFAULT_SIGMA_KM,
    ETENDUE_MINIMALE_INDICE,
    VOISINS_INDICE_LOCAL,
    _eligible,
    _fit_model,
    _predict,
)

DEFAULT_FOLDS = 5


def median_relative_error(
    sample: Iterable[Mutation],
    center_lat: float,
    center_lon: float,
    *,
    folds: int = DEFAULT_FOLDS,
    sigma_km: float = DEFAULT_SIGMA_KM,
    reference_dpe: str = DEFAULT_REFERENCE_DPE,
    voisins_indice_local: int = VOISINS_INDICE_LOCAL,
    etendue_minimale_indice: float = ETENDUE_MINIMALE_INDICE,
) -> float | None:
    """Erreur médiane de prédiction en %, ou ``None`` si l'échantillon est trop petit."""
    rows = _eligible(sample)
    if len(rows) < folds:
        return None

    errors: list[float] = []
    for fold in range(folds):
        test = [row for index, row in enumerate(rows) if index % folds == fold]
        train = [row for index, row in enumerate(rows) if index % folds != fold]
        if not test or not train:
            continue
        model = _fit_model(
            train,
            center_lat,
            center_lon,
            sigma_km,
            reference_dpe,
            voisins=voisins_indice_local,
            etendue_minimale=etendue_minimale_indice,
        )
        for row, prediction in zip(test, _predict(model, test), strict=True):
            if row.prix > 0:
                errors.append(abs(float(prediction) - row.prix) / row.prix)

    if not errors:
        return None
    return float(np.median(errors)) * 100.0

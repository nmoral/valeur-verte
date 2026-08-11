"""Régression linéaire pondérée pour les coefficients DPE (§5.2).

Modèle : ``prix ~ surface_bati + surface_terrain + dummies_DPE + effets_fixes
commune + effets_fixes_annee``, estimé par moindres carrés **pondérés** (poids
gaussien décroissant avec la distance). Restriction au **bâti ancien (< 1989)** :
cœur méthodologique, on mesure le gain réel de rénovation sans taux de capture.
"""

from __future__ import annotations

import math
import re

DEFAULT_SIGMA_KM = 10.0
_YEAR_RE = re.compile(r"\d{4}")


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

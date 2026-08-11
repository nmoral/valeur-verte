"""Sélection d'échantillon par rayon adaptatif (§5.1).

On filtre les **transactions** (pas les communes) autour d'un point, en
élargissant le rayon 10 → 15 → 20 → 30 → 50 km jusqu'à atteindre ``min_sample``.
Si 50 km ne suffit pas (zones très rurales), ``suffisant`` est faux et l'appelant
bascule sur le repli EPCI puis département. Variante hors-ligne (haversine) ; la
variante PostGIS (``ST_DWithin``) vit dans le repository et est résolue au batch.
"""

from __future__ import annotations

import math
from collections.abc import Iterable, Sequence
from dataclasses import dataclass

from .dvf import Mutation

RADII_KM: tuple[int, ...] = (10, 15, 20, 30, 50)
MIN_SAMPLE = 80
_EARTH_RADIUS_KM = 6371.0088


def haversine_km(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Distance orthodromique en kilomètres entre deux points (lat/lon en degrés)."""
    phi1, phi2 = math.radians(lat1), math.radians(lat2)
    dphi = math.radians(lat2 - lat1)
    dlambda = math.radians(lon2 - lon1)
    a = math.sin(dphi / 2) ** 2 + math.cos(phi1) * math.cos(phi2) * math.sin(dlambda / 2) ** 2
    return 2 * _EARTH_RADIUS_KM * math.asin(math.sqrt(a))


@dataclass(frozen=True)
class RadiusSelection:
    """Échantillon retenu, rayon utilisé, et si le seuil a été atteint."""

    sample: list[Mutation]
    rayon_km: int
    suffisant: bool


def adaptive_selection(
    center_lat: float,
    center_lon: float,
    mutations: Iterable[Mutation],
    *,
    min_sample: int = MIN_SAMPLE,
    radii: Sequence[int] = RADII_KM,
) -> RadiusSelection:
    """Retourne le plus petit rayon atteignant ``min_sample`` transactions (§5.1)."""
    distances: list[tuple[float, Mutation]] = []
    for mutation in mutations:
        if mutation.latitude is None or mutation.longitude is None:
            continue
        distance = haversine_km(center_lat, center_lon, mutation.latitude, mutation.longitude)
        distances.append((distance, mutation))

    sample: list[Mutation] = []
    rayon = radii[-1]
    for radius_km in radii:
        sample = [mutation for distance, mutation in distances if distance <= radius_km]
        rayon = radius_km
        if len(sample) >= min_sample:
            return RadiusSelection(sample=sample, rayon_km=radius_km, suffisant=True)
    return RadiusSelection(sample=sample, rayon_km=rayon, suffisant=False)

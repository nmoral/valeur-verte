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

import numpy as np
from numpy.typing import NDArray

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


def distances_km(
    center_lat: float, center_lon: float, lats: NDArray[np.float64], lons: NDArray[np.float64]
) -> NDArray[np.float64]:
    """Haversine **vectorisé** : distances (km) du centre à chaque point.

    Une seule opération numpy remplace des dizaines de milliers d'appels Python —
    c'est le point chaud du batch (une sélection par commune sur tout le pool).
    """
    phi1 = math.radians(center_lat)
    phi2 = np.radians(lats)
    dphi = np.radians(lats - center_lat)
    dlambda = np.radians(lons - center_lon)
    a = np.sin(dphi / 2) ** 2 + math.cos(phi1) * np.cos(phi2) * np.sin(dlambda / 2) ** 2
    return np.asarray(2.0 * _EARTH_RADIUS_KM * np.arcsin(np.sqrt(a)), dtype=np.float64)


@dataclass(frozen=True)
class RadiusSelection:
    """Échantillon retenu, rayon utilisé, et si le seuil a été atteint.

    ``distance_min_sample`` est la distance **continue** à la ``min_sample``-ième
    vente la plus proche : une mesure de densité locale fine, là où ``rayon_km``
    est quantifié et plafonné (10 km au minimum). Elle distingue une ville très
    dense d'une ville simplement dense (S23 mode « densité »).
    """

    sample: list[Mutation]
    rayon_km: int
    suffisant: bool
    distance_min_sample: float = 0.0


def adaptive_selection(
    center_lat: float,
    center_lon: float,
    mutations: Iterable[Mutation],
    *,
    min_sample: int = MIN_SAMPLE,
    radii: Sequence[int] = RADII_KM,
) -> RadiusSelection:
    """Retourne le plus petit rayon atteignant ``min_sample`` transactions (§5.1)."""
    avec_coord = [m for m in mutations if m.latitude is not None and m.longitude is not None]
    if not avec_coord:
        return RadiusSelection([], radii[-1], False, 0.0)

    lats = np.array([m.latitude for m in avec_coord], dtype=np.float64)
    lons = np.array([m.longitude for m in avec_coord], dtype=np.float64)
    distances = distances_km(center_lat, center_lon, lats, lons)

    triees = np.sort(distances)
    rang = min_sample - 1 if len(triees) >= min_sample else len(triees) - 1
    distance_min_sample = float(triees[rang])

    def _echantillon(mask: NDArray[np.bool_]) -> list[Mutation]:
        return [avec_coord[int(i)] for i in np.flatnonzero(mask)]

    rayon = radii[-1]
    mask = distances <= rayon
    for radius_km in radii:
        mask = distances <= radius_km
        rayon = radius_km
        if int(np.count_nonzero(mask)) >= min_sample:
            return RadiusSelection(_echantillon(mask), radius_km, True, distance_min_sample)
    return RadiusSelection(_echantillon(mask), rayon, False, distance_min_sample)

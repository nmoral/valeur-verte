"""Sélection spatiale des transactions par rayon adaptatif (PostGIS, §5.1/§10.6).

Variante « base » de ``radius`` : ``ST_DWithin`` sur l'index GIST ``geom``
(geography, distances en mètres). Résolu au batch, jamais à la requête.
"""

from __future__ import annotations

from collections.abc import Sequence

from ..radius import MIN_SAMPLE, RADII_KM
from .runner import Connection

_COUNT_WITHIN = (
    "SELECT count(*) FROM data.vente "
    "WHERE geom IS NOT NULL "
    "AND ST_DWithin(geom, ST_SetSRID(ST_MakePoint(%s, %s), 4326)::geography, %s)"
)


def count_within_radius(conn: Connection, lat: float, lon: float, radius_km: int) -> int:
    """Nombre de transactions géolocalisées dans un rayon (km) autour du point."""
    rows = conn.execute(_COUNT_WITHIN, [lon, lat, radius_km * 1000]).fetchall()
    return int(rows[0][0]) if rows else 0


def adaptive_radius(
    conn: Connection,
    lat: float,
    lon: float,
    *,
    min_sample: int = MIN_SAMPLE,
    radii: Sequence[int] = RADII_KM,
) -> tuple[int, int, bool]:
    """Plus petit rayon atteignant ``min_sample`` : ``(rayon_km, n, suffisant)``."""
    rayon = radii[-1]
    n = 0
    for radius_km in radii:
        n = count_within_radius(conn, lat, lon, radius_km)
        rayon = radius_km
        if n >= min_sample:
            return radius_km, n, True
    return rayon, n, False

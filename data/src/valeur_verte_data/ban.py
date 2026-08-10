"""Parsing des réponses de géocodage BAN (Base Adresse Nationale).

L'API BAN (``api-adresse.data.gouv.fr``) répond en GeoJSON : une
``FeatureCollection`` dont chaque ``Feature`` porte un point (``coordinates``
= ``[longitude, latitude]``) et des ``properties`` (``label``, ``score``,
``citycode`` = code INSEE, ``postcode``, ``city``, ``type``). On en extrait le
``code_insee`` et les coordonnées nécessaires au rattachement des ventes (§7).

Logique pure, hors-ligne : l'appel HTTP est traité ailleurs.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from dataclasses import dataclass


@dataclass(frozen=True)
class GeocodeResult:
    """Un résultat de géocodage BAN, réduit aux champs utiles au rattachement."""

    label: str
    score: float
    code_insee: str
    longitude: float
    latitude: float
    type: str | None
    code_postal: str | None
    nom_commune: str | None


def _parse_feature(feature: Mapping[str, object]) -> GeocodeResult | None:
    geometry = feature.get("geometry")
    properties = feature.get("properties")
    if not isinstance(geometry, Mapping) or not isinstance(properties, Mapping):
        return None
    coordinates = geometry.get("coordinates")
    if not isinstance(coordinates, (list, tuple)) or len(coordinates) < 2:
        return None
    code_insee = str(properties.get("citycode", "")).strip()
    if not code_insee:
        return None
    return GeocodeResult(
        label=str(properties.get("label", "")),
        score=float(properties.get("score", 0.0)),
        code_insee=code_insee,
        longitude=float(coordinates[0]),
        latitude=float(coordinates[1]),
        type=_clean(properties.get("type")),
        code_postal=_clean(properties.get("postcode")),
        nom_commune=_clean(properties.get("city")),
    )


def _clean(value: object) -> str | None:
    text = "" if value is None else str(value).strip()
    return text or None


def geocode_results(payload: Mapping[str, object]) -> list[GeocodeResult]:
    """Parse une ``FeatureCollection`` BAN en résultats de géocodage."""
    features = payload.get("features")
    if not isinstance(features, Iterable):
        return []
    results: list[GeocodeResult] = []
    for feature in features:
        if isinstance(feature, Mapping):
            parsed = _parse_feature(feature)
            if parsed is not None:
                results.append(parsed)
    return results


def best_result(payload: Mapping[str, object]) -> GeocodeResult | None:
    """Retourne le résultat au meilleur score, ou ``None`` si aucun."""
    results = geocode_results(payload)
    if not results:
        return None
    return max(results, key=lambda result: result.score)

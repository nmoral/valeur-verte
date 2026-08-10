"""Tests du parsing des réponses de géocodage BAN (hors-ligne)."""

from __future__ import annotations

from valeur_verte_data.ban import GeocodeResult, best_result, geocode_results

_FEATURE = {
    "type": "Feature",
    "geometry": {"type": "Point", "coordinates": [2.29576, 49.894579]},
    "properties": {
        "label": "8 Boulevard du Port 80000 Amiens",
        "score": 0.82,
        "citycode": "80021",
        "postcode": "80000",
        "city": "Amiens",
        "type": "housenumber",
    },
}


def _collection(*features: dict[str, object]) -> dict[str, object]:
    return {"type": "FeatureCollection", "features": list(features)}


def test_geocode_results_parse_une_feature() -> None:
    (result,) = geocode_results(_collection(_FEATURE))

    assert result == GeocodeResult(
        label="8 Boulevard du Port 80000 Amiens",
        score=0.82,
        code_insee="80021",
        longitude=2.29576,
        latitude=49.894579,
        type="housenumber",
        code_postal="80000",
        nom_commune="Amiens",
    )


def test_best_result_retient_le_meilleur_score() -> None:
    faible = {**_FEATURE, "properties": {**_FEATURE["properties"], "score": 0.30, "citycode": "10000"}}  # type: ignore[dict-item]

    result = best_result(_collection(faible, _FEATURE))

    assert result is not None
    assert result.code_insee == "80021"


def test_best_result_collection_vide() -> None:
    assert best_result(_collection()) is None

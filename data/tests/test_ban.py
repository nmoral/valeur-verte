"""Tests du parsing des réponses de géocodage BAN (hors-ligne)."""

from __future__ import annotations

from collections.abc import Mapping

from valeur_verte_data.ban import GeocodeResult, best_result, geocode_results


def _feature(**props: object) -> dict[str, object]:
    properties: dict[str, object] = {
        "label": "8 Boulevard du Port 80000 Amiens",
        "score": 0.82,
        "citycode": "80021",
        "postcode": "80000",
        "city": "Amiens",
        "type": "housenumber",
    }
    properties.update(props)
    return {
        "type": "Feature",
        "geometry": {"type": "Point", "coordinates": [2.29576, 49.894579]},
        "properties": properties,
    }


def _collection(*features: Mapping[str, object]) -> dict[str, object]:
    return {"type": "FeatureCollection", "features": list(features)}


def test_geocode_results_parse_une_feature() -> None:
    (result,) = geocode_results(_collection(_feature()))

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
    faible = _feature(score=0.30, citycode="10000")

    result = best_result(_collection(faible, _feature()))

    assert result is not None
    assert result.code_insee == "80021"


def test_best_result_collection_vide() -> None:
    assert best_result(_collection()) is None


def _raw_feature(properties: dict[str, object]) -> dict[str, object]:
    return {
        "type": "Feature",
        "geometry": {"type": "Point", "coordinates": [2.29576, 49.894579]},
        "properties": properties,
    }


def test_ignore_feature_sans_geometrie() -> None:
    feature: dict[str, object] = {"type": "Feature", "properties": {"citycode": "80021"}}

    assert geocode_results(_collection(feature)) == []


def test_ignore_coordonnees_incompletes() -> None:
    feature: dict[str, object] = {
        "type": "Feature",
        "geometry": {"type": "Point", "coordinates": [2.29]},
        "properties": {"citycode": "80021"},
    }

    assert geocode_results(_collection(feature)) == []


def test_ignore_feature_sans_citycode() -> None:
    feature = _raw_feature({"label": "Sans code"})

    assert geocode_results(_collection(feature)) == []


def test_defauts_des_champs_optionnels_absents() -> None:
    (result,) = geocode_results(_collection(_raw_feature({"citycode": "80021"})))

    assert result.score == 0.0
    assert result.label == ""
    assert result.code_postal is None
    assert result.nom_commune is None
    assert result.type is None

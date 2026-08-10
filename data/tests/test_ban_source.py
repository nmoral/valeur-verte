"""Tests du client de géocodage BAN (construction requête + parsing, hors-ligne)."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from valeur_verte_data.ban_source import BAN_SEARCH_URL, geocode

_PAYLOAD: dict[str, Any] = {
    "type": "FeatureCollection",
    "features": [
        {
            "type": "Feature",
            "geometry": {"type": "Point", "coordinates": [2.29, 49.89]},
            "properties": {
                "label": "8 Boulevard du Port 80000 Amiens",
                "score": 0.9,
                "citycode": "80021",
                "postcode": "80000",
                "city": "Amiens",
                "type": "housenumber",
            },
        }
    ],
}


class _FakeFetcher:
    def __init__(self, payload: Any) -> None:
        self.payload = payload
        self.calls: list[tuple[str, dict[str, str]]] = []

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        raise AssertionError("get_text ne doit pas être appelé")

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        self.calls.append((url, dict(params) if params else {}))
        return self.payload


def test_geocode_retourne_le_meilleur_resultat() -> None:
    fetcher = _FakeFetcher(_PAYLOAD)

    result = geocode(fetcher, "8 Boulevard du Port", code_insee="80021")

    assert result is not None
    assert result.code_insee == "80021"
    url, params = fetcher.calls[0]
    assert url == BAN_SEARCH_URL
    assert params["q"] == "8 Boulevard du Port"
    assert params["citycode"] == "80021"
    assert params["limit"] == "1"


def test_geocode_sans_resultat_retourne_none() -> None:
    fetcher = _FakeFetcher({"type": "FeatureCollection", "features": []})

    assert geocode(fetcher, "adresse inconnue") is None


def test_geocode_sans_code_insee_n_envoie_pas_citycode() -> None:
    fetcher = _FakeFetcher(_PAYLOAD)

    geocode(fetcher, "8 Boulevard du Port")

    _, params = fetcher.calls[0]
    assert "citycode" not in params

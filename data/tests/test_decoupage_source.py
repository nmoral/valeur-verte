"""Tests du client référentiel communes (API Découpage Administratif, hors-ligne)."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from valeur_verte_data.decoupage_source import (
    fetch_commune,
    fetch_communes_by_departement,
    fetch_contours_by_departement,
)

_COMMUNE: dict[str, Any] = {
    "nom": "Amiens",
    "code": "80021",
    "codeDepartement": "80",
    "codeEpci": "200069250",
    "codesPostaux": ["80000"],
    "centre": {"type": "Point", "coordinates": [2.29, 49.89]},
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


def test_fetch_commune_extrait_le_referentiel() -> None:
    fetcher = _FakeFetcher(_COMMUNE)

    commune = fetch_commune(fetcher, "80021")

    assert commune is not None
    assert commune.code_epci == "200069250"
    assert commune.centre_longitude == 2.29
    url, params = fetcher.calls[0]
    assert url.endswith("/communes/80021")
    assert params["geometry"] == "centre"


def test_fetch_communes_by_departement() -> None:
    fetcher = _FakeFetcher([_COMMUNE, {**_COMMUNE, "code": "80001", "nom": "Ailly"}])

    communes = fetch_communes_by_departement(fetcher, "80")

    assert [c.code_insee for c in communes] == ["80021", "80001"]
    url, params = fetcher.calls[0]
    assert url.endswith("/departements/80/communes")
    assert params["geometry"] == "centre"


def test_fetch_commune_reponse_invalide_donne_none() -> None:
    fetcher = _FakeFetcher("oops")

    assert fetch_commune(fetcher, "80021") is None


def test_fetch_communes_reponse_invalide_donne_liste_vide() -> None:
    fetcher = _FakeFetcher({"erreur": "boom"})

    assert fetch_communes_by_departement(fetcher, "80") == []


_CONTOUR_POLYGON = {
    "type": "Polygon",
    "coordinates": [[[1.0, 43.0], [1.1, 43.0], [1.1, 43.1], [1.0, 43.0]]],
}


def test_fetch_contours_by_departement_extrait_les_polygones() -> None:
    payload = [
        {"code": "31001", "codeDepartement": "31", "contour": _CONTOUR_POLYGON},
        {"code": "31002", "codeDepartement": "31"},  # sans contour → ignorée
    ]
    fetcher = _FakeFetcher(payload)

    contours = fetch_contours_by_departement(fetcher, "31")

    assert [c.code_insee for c in contours] == ["31001"]
    assert contours[0].code_departement == "31"
    url, params = fetcher.calls[0]
    assert url.endswith("/departements/31/communes")
    assert params["geometry"] == "contour"


def test_fetch_contours_reponse_invalide_donne_liste_vide() -> None:
    fetcher = _FakeFetcher({"erreur": "boom"})

    assert fetch_contours_by_departement(fetcher, "31") == []

"""Tests du client référentiel communes (API Découpage Administratif, hors-ligne)."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from valeur_verte_data.decoupage_source import (
    fetch_commune,
    fetch_communes_by_departement,
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
    url, _ = fetcher.calls[0]
    assert url.endswith("/departements/80/communes")


def test_fetch_commune_reponse_invalide_donne_none() -> None:
    fetcher = _FakeFetcher("oops")

    assert fetch_commune(fetcher, "80021") is None


def test_fetch_communes_reponse_invalide_donne_liste_vide() -> None:
    fetcher = _FakeFetcher({"erreur": "boom"})

    assert fetch_communes_by_departement(fetcher, "80") == []

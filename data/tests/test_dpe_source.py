"""Tests du client DPE/ADEME (pagination + mapping des champs, hors-ligne).

Note : les noms de champs ADEME (``Etiquette_DPE``, ``Adresse_(BAN)``…) sont
une hypothèse à confirmer sur l'API réelle (schéma non accessible hors-ligne).
Le mapping est centralisé dans ``dpe_source`` pour être corrigé en un point.
"""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from valeur_verte_data.dpe_source import ADEME_LINES_URL, dpe_lookup, fetch_dpe_records


def _ademe(etiquette: str, adresse: str, periode: str) -> dict[str, str]:
    return {
        "Etiquette_DPE": etiquette,
        "Adresse_(BAN)": adresse,
        "Code_postal_(BAN)": "31000",
        "Période_construction": periode,
    }


_PAGE1: dict[str, Any] = {
    "total": 2,
    "next": "https://data.ademe.fr/data-fair/.../lines?after=cursor42",
    "results": [_ademe("D", "12 Rue des Fleurs 31000 Toulouse", "1948-1974")],
}
_PAGE2: dict[str, Any] = {
    "total": 2,
    "next": None,
    "results": [_ademe("E", "14 Rue des Fleurs 31000 Toulouse", "1975-1977")],
}


class _FakeFetcher:
    def __init__(self, pages: list[dict[str, Any]]) -> None:
        self.pages = list(pages)
        self.calls: list[tuple[str, dict[str, str]]] = []

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        raise AssertionError("get_text ne doit pas être appelé")

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        self.calls.append((url, dict(params) if params else {}))
        return self.pages.pop(0)


def test_fetch_dpe_records_suit_la_pagination_et_mappe_les_champs() -> None:
    fetcher = _FakeFetcher([_PAGE1, _PAGE2])

    records = fetch_dpe_records(fetcher, "31555")

    assert [r["etiquette_dpe"] for r in records] == ["D", "E"]
    assert records[0]["adresse_ban"] == "12 Rue des Fleurs 31000 Toulouse"
    assert records[0]["code_postal"] == "31000"
    assert records[0]["periode_construction"] == "1948-1974"
    # 1er appel = endpoint ADEME + filtre commune ; 2e = URL 'next'.
    assert fetcher.calls[0][0] == ADEME_LINES_URL
    assert "31555" in fetcher.calls[0][1]["qs"]
    assert fetcher.calls[1][0] == "https://data.ademe.fr/data-fair/.../lines?after=cursor42"


def test_fetch_dpe_records_page_unique() -> None:
    fetcher = _FakeFetcher([{"total": 1, "results": [_ademe("C", "1 Rue X 31000 T", "")]}])

    records = fetch_dpe_records(fetcher, "31555")

    assert len(records) == 1
    assert len(fetcher.calls) == 1


def test_dpe_lookup_construit_l_index() -> None:
    fetcher = _FakeFetcher([_PAGE1, _PAGE2])

    lookup = dpe_lookup(fetcher, "31555")

    assert lookup[("31000", "12 rue fleurs")].etiquette_dpe == "D"

"""Tests du client DVF (construction d'URL + récupération, hors-ligne)."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from valeur_verte_data.dvf_source import departement, dvf_url, fetch_mutations

_CSV = (
    "id_mutation,date_mutation,valeur_fonciere,code_commune,code_postal,"
    "type_local,surface_reelle_bati,longitude,latitude\n"
    "2024-1,2024-03-01,200000,31555,31000,Maison,80,1.44,43.60\n"
)


class _FakeFetcher:
    def __init__(self, text: str) -> None:
        self.text = text
        self.urls: list[str] = []

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        self.urls.append(url)
        return self.text

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        raise AssertionError("get_json ne doit pas être appelé")


def test_departement_metropole_dom_corse() -> None:
    assert departement("31555") == "31"
    assert departement("97411") == "974"
    assert departement("2A004") == "2A"


def test_dvf_url_metropole() -> None:
    assert (
        dvf_url(2024, "31555")
        == "https://files.data.gouv.fr/geo-dvf/latest/csv/2024/communes/31/31555.csv"
    )


def test_fetch_mutations_recupere_et_parse() -> None:
    fetcher = _FakeFetcher(_CSV)

    mutations = fetch_mutations(fetcher, "31555", 2024)

    assert len(mutations) == 1
    assert mutations[0].code_insee == "31555"
    assert mutations[0].millesime == 2024
    assert fetcher.urls == [
        "https://files.data.gouv.fr/geo-dvf/latest/csv/2024/communes/31/31555.csv"
    ]

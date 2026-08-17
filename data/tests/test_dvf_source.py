"""Tests du client DVF (construction d'URL + récupération, hors-ligne)."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

import pytest

from valeur_verte_data.dvf_source import departement, dvf_url, fetch_mutations
from valeur_verte_data.schema_source import COLONNES_DVF, SchemaSourceError

# En-tête geo-dvf complet (DAT-1) : toutes les colonnes lues par le pipeline. Le
# corps ne renseigne que le strict nécessaire à l'agrégation d'une mutation.
_ENTETE = ",".join(sorted(COLONNES_DVF))
_CSV = (
    _ENTETE + "\n"
    "RUE DES FLEURS,12,,31555,S,31000,2024-03-01,2024-1,P1,43.60,1.44,3,Toulouse,80,,Maison,200000\n"
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
    assert departement("98411") == "984"
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


def test_fetch_mutations_rejette_une_colonne_dvf_renommee() -> None:
    # DAT-1 (#124) : geo-dvf renomme `valeur_fonciere` → `valeur`. Sans garde-fou,
    # `.get()` renverrait None en silence ; la porte échoue en nommant la colonne.
    entete = ",".join(sorted((COLONNES_DVF - {"valeur_fonciere"}) | {"valeur"}))
    fetcher = _FakeFetcher(entete + "\n")

    with pytest.raises(SchemaSourceError) as info:
        fetch_mutations(fetcher, "31555", 2024)

    assert info.value.source == "DVF"
    assert "valeur_fonciere" in info.value.colonnes_manquantes


def test_fetch_mutations_tolere_une_commune_sans_fichier() -> None:
    # Corps vide (aucun en-tête) : rien d'observé, aucune colonne à juger.
    assert fetch_mutations(_FakeFetcher(""), "31555", 2024) == []

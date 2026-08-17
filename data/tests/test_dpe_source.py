"""Tests du client DPE/ADEME (pagination + mapping des champs, hors-ligne).

Note : les noms de champs ADEME (``etiquette_dpe``, ``adresse_ban``…) sont
une hypothèse à confirmer sur l'API réelle (schéma non accessible hors-ligne).
Le mapping est centralisé dans ``dpe_source`` pour être corrigé en un point.
"""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

import pytest

from valeur_verte_data.dpe_source import ADEME_LINES_URL, dpe_lookup, fetch_dpe_records
from valeur_verte_data.schema_source import SchemaSourceError


def _ademe(
    etiquette: str,
    adresse: str,
    periode: str,
    *,
    date: str = "2022-06-15",
    version: str = "2.2",
) -> dict[str, str]:
    return {
        "etiquette_dpe": etiquette,
        "adresse_ban": adresse,
        "code_postal_ban": "31000",
        "periode_construction": periode,
        "date_etablissement_dpe": date,
        "version_dpe": version,
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
    assert records[0]["date_etablissement_dpe"] == "2022-06-15"
    assert records[0]["version_dpe"] == "2.2"
    # 1er appel = endpoint ADEME + filtre commune ; 2e = URL 'next'.
    assert fetcher.calls[0][0] == ADEME_LINES_URL
    # 10 000 = maximum accepté par l'API : Toulouse compte 186 000 DPE, soit
    # 187 requêtes par pages de 1 000 pour une seule commune.
    assert fetcher.calls[0][1]["size"] == "10000"
    assert "31555" in fetcher.calls[0][1]["qs"]
    assert (
        fetcher.calls[0][1]["select"]
        == "etiquette_dpe,adresse_ban,code_postal_ban,periode_construction,"
        "date_etablissement_dpe,version_dpe"
    )
    assert fetcher.calls[1][0] == "https://data.ademe.fr/data-fair/.../lines?after=cursor42"


def test_fetch_dpe_records_page_unique() -> None:
    fetcher = _FakeFetcher([{"total": 1, "results": [_ademe("C", "1 Rue X 31000 T", "")]}])

    records = fetch_dpe_records(fetcher, "31555")

    assert len(records) == 1
    assert len(fetcher.calls) == 1


def test_fetch_dpe_records_champ_absent_donne_chaine_vide() -> None:
    # Un champ optionnel omis sur UN enregistrement (présent sur l'union, donc le
    # schéma reste valide) est normalisé en chaîne vide par `_normalize`.
    complet = _ademe("D", "12 Rue X 31000 T", "1948-1974")
    partiel = {"etiquette_dpe": "C"}
    fetcher = _FakeFetcher([{"total": 2, "results": [complet, partiel]}])

    records = fetch_dpe_records(fetcher, "31555")

    assert records[1]["periode_construction"] == ""
    assert records[1]["adresse_ban"] == ""


def test_dpe_lookup_construit_l_index() -> None:
    fetcher = _FakeFetcher([_PAGE1, _PAGE2])

    lookup = dpe_lookup(fetcher, "31555")

    assert lookup[("31000", "12 rue fleurs")].etiquette_dpe == "D"
    assert "31555" in fetcher.calls[0][1]["qs"]


def test_fetch_dpe_records_rejette_un_champ_ademe_disparu() -> None:
    # DAT-1 (#124) : l'ADEME renomme `etiquette_dpe`. Absent de TOUS les
    # enregistrements = schéma cassé ; la porte échoue en nommant le champ.
    ademe_casse = {
        "classe_dpe": "D",  # ex-`etiquette_dpe`
        "adresse_ban": "1 Rue X 31000 T",
        "code_postal_ban": "31000",
        "periode_construction": "1948-1974",
        "date_etablissement_dpe": "2022-01-01",
        "version_dpe": "2.2",
    }
    fetcher = _FakeFetcher([{"total": 1, "results": [ademe_casse]}])

    with pytest.raises(SchemaSourceError) as info:
        fetch_dpe_records(fetcher, "31555")

    assert info.value.source == "DPE"
    assert "etiquette_dpe" in info.value.colonnes_manquantes


def test_fetch_dpe_records_commune_sans_dpe_ne_juge_pas_le_schema() -> None:
    # Aucun enregistrement : on ne peut pas distinguer un champ absent d'un champ
    # renommé — on ne barre pas (une commune sans DPE publié est normale).
    fetcher = _FakeFetcher([{"total": 0, "results": []}])

    assert fetch_dpe_records(fetcher, "31555") == []


def test_fetch_dpe_records_signale_une_periode_inconnue_sans_bloquer(
    caplog: pytest.LogCaptureFixture,
) -> None:
    # DAT-1 : une tranche de période hors vocabulaire connu est **signalée** (log),
    # pas bloquante — un nouveau niveau catégoriel ne corrompt pas le modèle.
    fetcher = _FakeFetcher([{"total": 1, "results": [_ademe("D", "1 Rue X 31000 T", "1600-1700")]}])

    with caplog.at_level("WARNING"):
        records = fetch_dpe_records(fetcher, "31555")

    assert len(records) == 1  # non bloquant : l'enregistrement est bien retourné
    assert "1600-1700" in caplog.text
    assert "31555" in caplog.text  # le log nomme la commune concernée

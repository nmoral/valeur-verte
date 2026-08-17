"""Rapport de batch archivé (#131) : métriques exposées par les rapports d'exécution.

Le rapport archivé doit porter le **taux d'appariement DVF↔DPE** (DAT-3) : une base
chargée sans étiquette DPE est stérile (le moteur écarte ces ventes), et une chute de
l'appariement d'un millésime à l'autre est le premier symptôme à repérer six mois plus
tard. On le dérive des ventes réellement insérées, pas d'un compteur parallèle.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any

import pytest

from valeur_verte_data import orchestration
from valeur_verte_data.decoupage import Commune
from valeur_verte_data.ingest import ResultatIngestionCommune
from valeur_verte_data.orchestration import RapportIngestion

_COMMUNES = [
    Commune(
        code_insee="31555",
        nom="Toulouse",
        code_epci=None,
        codes_postaux=(),
        centre_longitude=1.44,
        centre_latitude=43.6,
        code_departement="31",
    ),
    Commune(
        code_insee="31149",
        nom="Colomiers",
        code_epci=None,
        codes_postaux=(),
        centre_longitude=1.33,
        centre_latitude=43.61,
        code_departement="31",
    ),
]


class _FauxFetcher:
    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return []

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        raise AssertionError("non utilisé")


class _FausseConnexion:
    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        raise AssertionError("l'ingestion réelle est mockée")

    def commit(self) -> None:
        pass

    def rollback(self) -> None:
        pass


def test_l_ingestion_departement_cumule_les_ventes_appariees(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Le rapport de département agrège l'appariement commune par commune (DAT-3).

    Toulouse apparie 30 de ses 40 ventes, Colomiers 5 de ses 10 : le département
    porte 35 appariées sur 50 insérées, soit un taux de 0,70.
    """
    resultats = iter(
        [ResultatIngestionCommune(inserees=40, appariees=30), ResultatIngestionCommune(10, 5)]
    )
    monkeypatch.setattr(
        orchestration, "fetch_communes_by_departement", lambda fetcher, dep: list(_COMMUNES)
    )
    monkeypatch.setattr(orchestration, "fetch_contours_by_departement", lambda fetcher, dep: [])
    monkeypatch.setattr(orchestration, "upsert_communes", lambda conn, communes: 0)
    monkeypatch.setattr(orchestration, "upsert_epci", lambda conn, entries: 0)
    monkeypatch.setattr(orchestration, "ingest_commune", lambda *a, **k: next(resultats))

    rapport = orchestration.ingest_departement(_FauxFetcher(), _FausseConnexion(), "31", 2024)

    assert rapport.mutations_inserees == 50
    assert rapport.ventes_appariees == 35
    assert rapport.taux_appariement == 0.7


def test_taux_appariement_est_la_part_de_ventes_avec_dpe() -> None:
    rapport = RapportIngestion(departement="31", millesime=2024)
    rapport.mutations_inserees = 60
    rapport.ventes_appariees = 45

    assert rapport.taux_appariement == 0.75


def test_taux_appariement_vaut_zero_sans_aucune_vente() -> None:
    # Un département sans ingestion ne doit pas faire diviser par zéro : l'absence
    # d'appariement est un taux nul, pas une erreur.
    rapport = RapportIngestion(departement="31", millesime=2024)

    assert rapport.taux_appariement == 0.0

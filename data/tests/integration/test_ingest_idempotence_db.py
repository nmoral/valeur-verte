"""Intégration : un ré-import ne duplique pas les transactions (#91).

``data.vente`` n'a pas de contrainte d'unicité sur la mutation, et un import est
rejoué chaque fois qu'un millésime est corrigé ou qu'une exécution a été
interrompue. Une duplication silencieuse fausserait tous les coefficients :
c'est le comportement le plus important de l'ingestion par département.
"""

from __future__ import annotations

import os
from collections.abc import Mapping
from typing import Any

import psycopg
import pytest

from valeur_verte_data.ingest import ingest_commune

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]

_CSV = (
    "id_mutation,valeur_fonciere,code_commune,code_postal,surface_reelle_bati,"
    "adresse_numero,adresse_nom_voie,longitude,latitude\n"
    "2024-1,200000,31555,31000,80,12,RUE DES FLEURS,1.44,43.6\n"
    "2024-2,250000,31555,31000,90,14,RUE DES FLEURS,1.45,43.61\n"
)


class _FakeFetcher:
    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        return _CSV

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return {"results": []}  # commune sans DPE publié


def _compter(conn: psycopg.Connection[Any], code_insee: str, millesime: int) -> int:
    ligne = conn.execute(
        "SELECT count(*) FROM data.vente WHERE code_insee = %s AND millesime = %s",
        [code_insee, millesime],
    ).fetchone()
    return 0 if ligne is None else int(ligne[0])


@pytest.fixture
def connexion() -> Any:
    with psycopg.connect(DSN) as conn:
        conn.execute("DELETE FROM data.vente WHERE code_insee = '31555'")
        yield conn
        conn.execute("DELETE FROM data.vente WHERE code_insee = '31555'")


def test_rejouer_une_ingestion_ne_duplique_pas(connexion: psycopg.Connection[Any]) -> None:
    """Deux exécutions consécutives doivent laisser la base dans le même état."""
    ingest_commune(_FakeFetcher(), connexion, "31555", 2024)
    apres_premier = _compter(connexion, "31555", 2024)

    ingest_commune(_FakeFetcher(), connexion, "31555", 2024)

    assert apres_premier == 2
    assert _compter(connexion, "31555", 2024) == 2


def test_un_reimport_ne_touche_pas_les_autres_millesimes(
    connexion: psycopg.Connection[Any],
) -> None:
    """Réimporter 2024 effacerait 2023 si la suppression ne filtrait pas l'année."""
    ingest_commune(_FakeFetcher(), connexion, "31555", 2023)
    ingest_commune(_FakeFetcher(), connexion, "31555", 2024)

    ingest_commune(_FakeFetcher(), connexion, "31555", 2024)

    assert _compter(connexion, "31555", 2023) == 2
    assert _compter(connexion, "31555", 2024) == 2

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
from valeur_verte_data.schema_source import COLONNES_DVF

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]

# geo-dvf porte toujours l'en-tête complet (DAT-1, #124) : on construit un CSV
# valide à partir des seules colonnes utiles au test, les autres restant vides.
_COLONNES_DVF = sorted(COLONNES_DVF)
_LIGNES_DVF = (
    {
        "id_mutation": "2024-1",
        "valeur_fonciere": "200000",
        "code_commune": "31555",
        "code_postal": "31000",
        "surface_reelle_bati": "80",
        "adresse_numero": "12",
        "adresse_nom_voie": "RUE DES FLEURS",
        "longitude": "1.44",
        "latitude": "43.6",
    },
    {
        "id_mutation": "2024-2",
        "valeur_fonciere": "250000",
        "code_commune": "31555",
        "code_postal": "31000",
        "surface_reelle_bati": "90",
        "adresse_numero": "14",
        "adresse_nom_voie": "RUE DES FLEURS",
        "longitude": "1.45",
        "latitude": "43.61",
    },
)
_CSV = (
    ",".join(_COLONNES_DVF)
    + "\n"
    + "".join(
        ",".join(ligne.get(colonne, "") for colonne in _COLONNES_DVF) + "\n"
        for ligne in _LIGNES_DVF
    )
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

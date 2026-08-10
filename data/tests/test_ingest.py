"""Tests de l'orchestration d'ingestion DVF (fetch → écriture, hors-ligne)."""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any

from valeur_verte_data.ingest import ingest_commune

_CSV = (
    "id_mutation,valeur_fonciere,code_commune,code_postal,surface_reelle_bati,"
    "adresse_numero,adresse_nom_voie,longitude,latitude\n"
    "2024-1,200000,31555,31000,80,12,RUE DES FLEURS,1.44,43.6\n"
)


class _FakeFetcher:
    def __init__(self, text: str) -> None:
        self.text = text

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        return self.text

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        raise AssertionError("get_json ne doit pas être appelé")


class _FakeCursor:
    def fetchall(self) -> list[tuple[Any, ...]]:
        return []


class _FakeConnection:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor()


def test_ingest_commune_enchaine_fetch_et_ecriture() -> None:
    conn = _FakeConnection()

    inserted = ingest_commune(_FakeFetcher(_CSV), conn, "31555", 2024)

    assert inserted == 1
    assert len(conn.calls) == 1
    assert "data.vente" in conn.calls[0][0]


def test_ingest_commune_csv_vide_n_ecrit_rien() -> None:
    conn = _FakeConnection()

    inserted = ingest_commune(_FakeFetcher("id_mutation,valeur_fonciere\n"), conn, "31555", 2024)

    assert inserted == 0
    assert conn.calls == []

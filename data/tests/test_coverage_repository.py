"""Tests du repository de couverture (génération SQL/params, hors-ligne)."""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from valeur_verte_data.db.coverage_repository import (
    department_statuses,
    log_out_of_zone,
    set_department_status,
)


class _FakeCursor:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


class _FakeConnection:
    def __init__(self, rows: Sequence[tuple[Any, ...]] = ()) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []
        self._rows = list(rows)

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        if "select" in query.lower():
            return _FakeCursor(self._rows)
        return _FakeCursor([])


def test_department_statuses_lit_les_lignes() -> None:
    conn = _FakeConnection(rows=[("31", "charge"), ("32", "calcule")])

    assert department_statuses(conn) == {"31": "charge", "32": "calcule"}


def test_set_department_status_fait_un_upsert() -> None:
    conn = _FakeConnection()

    set_department_status(conn, "31", "calcule")

    query, params = conn.calls[0]
    assert "couverture_departement" in query
    assert "on conflict" in query.lower()
    assert params == ["31", "calcule"]


def test_log_out_of_zone_insere() -> None:
    conn = _FakeConnection()

    log_out_of_zone(conn, "31555", "31000")

    query, params = conn.calls[0]
    assert "requete_hors_zone" in query
    assert params == ["31555", "31000"]


def test_log_out_of_zone_code_postal_optionnel() -> None:
    conn = _FakeConnection()

    log_out_of_zone(conn, "31555")

    _, params = conn.calls[0]
    assert params == ["31555", None]

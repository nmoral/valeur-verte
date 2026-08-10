"""Tests de l'orchestration des migrations (hors-ligne, fausse connexion)."""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

import pytest

from valeur_verte_data.db.migrations import Migration
from valeur_verte_data.db.runner import (
    apply_migrations,
    pending_migrations,
    resolve_dsn,
)


def _mig(version: int) -> Migration:
    return Migration(version=version, name=f"m{version}", sql=f"CREATE TABLE t{version} ();")


class _FakeCursor:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


class _FakeConnection:
    """Simule le suivi des versions appliquées en mémoire."""

    def __init__(self) -> None:
        self.executed: list[str] = []
        self._applied: list[int] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.executed.append(query)
        lowered = query.lower()
        if "insert into" in lowered and params is not None:
            self._applied.append(int(params[0]))
        if "select version" in lowered:
            return _FakeCursor([(version,) for version in self._applied])
        return _FakeCursor([])


def test_pending_migrations_exclut_les_versions_appliquees() -> None:
    migs = [_mig(1), _mig(2), _mig(3)]

    assert pending_migrations(migs, {1, 2}) == [_mig(3)]


def test_pending_migrations_preserve_l_ordre() -> None:
    migs = [_mig(1), _mig(2), _mig(3)]

    assert pending_migrations(migs, set()) == migs


def test_apply_migrations_applique_tout_sur_base_vierge() -> None:
    conn = _FakeConnection()

    applied = apply_migrations(conn, [_mig(1), _mig(2)])

    assert applied == [1, 2]
    assert "CREATE TABLE t1 ();" in conn.executed
    assert "CREATE TABLE t2 ();" in conn.executed


def test_apply_migrations_est_idempotent() -> None:
    conn = _FakeConnection()
    apply_migrations(conn, [_mig(1), _mig(2)])

    applied = apply_migrations(conn, [_mig(1), _mig(2)])

    assert applied == []


def test_apply_migrations_reprend_apres_partiel() -> None:
    conn = _FakeConnection()
    apply_migrations(conn, [_mig(1)])

    applied = apply_migrations(conn, [_mig(1), _mig(2), _mig(3)])

    assert applied == [2, 3]


def test_apply_migrations_charge_les_migrations_par_defaut() -> None:
    conn = _FakeConnection()

    applied = apply_migrations(conn)

    assert applied == [1, 2, 3, 4]


def test_resolve_dsn_lit_l_environnement() -> None:
    assert resolve_dsn({"DATABASE_URL": "postgresql://u@h/db"}) == "postgresql://u@h/db"


def test_resolve_dsn_absent_leve() -> None:
    with pytest.raises(RuntimeError):
        resolve_dsn({})

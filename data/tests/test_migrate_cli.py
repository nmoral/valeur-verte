"""Tests de la CLI de migration (glue, hors-ligne)."""

from __future__ import annotations

from collections.abc import Sequence
from contextlib import AbstractContextManager
from types import TracebackType
from typing import Any

import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.migrations import load_migrations


class _FakeCursor:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


class _FakeConnection:
    def __init__(self, applied: Sequence[int] = ()) -> None:
        self.executed: list[str] = []
        self._applied = list(applied)

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.executed.append(query)
        if "select version" in query.lower():
            return _FakeCursor([(version,) for version in self._applied])
        return _FakeCursor([])


class _FakeCtx(AbstractContextManager[_FakeConnection]):
    def __init__(self, conn: _FakeConnection) -> None:
        self._conn = conn

    def __enter__(self) -> _FakeConnection:
        return self._conn

    def __exit__(
        self,
        exc_type: type[BaseException] | None,
        exc: BaseException | None,
        tb: TracebackType | None,
    ) -> None:
        return None


def test_run_applique_via_le_connecteur() -> None:
    conn = _FakeConnection()

    applied = cli.run({"DATABASE_URL": "postgresql://x"}, lambda dsn: _FakeCtx(conn))

    assert applied == [migration.version for migration in load_migrations()]
    assert any("schema_migrations" in sql for sql in conn.executed)


def test_run_sans_dsn_leve() -> None:
    with pytest.raises(RuntimeError):
        cli.run({}, lambda dsn: _FakeCtx(_FakeConnection()))


def test_main_affiche_les_versions(capsys: pytest.CaptureFixture[str]) -> None:
    conn = _FakeConnection()

    code = cli.main(env={"DATABASE_URL": "postgresql://x"}, connect=lambda dsn: _FakeCtx(conn))

    assert code == 0
    expected = ", ".join(str(migration.version) for migration in load_migrations())
    assert f"Migrations appliquées : {expected}" in capsys.readouterr().out


def test_main_base_deja_a_jour(capsys: pytest.CaptureFixture[str]) -> None:
    conn = _FakeConnection(applied=[migration.version for migration in load_migrations()])

    code = cli.main(env={"DATABASE_URL": "postgresql://x"}, connect=lambda dsn: _FakeCtx(conn))

    assert code == 0
    assert "Base déjà à jour." in capsys.readouterr().out

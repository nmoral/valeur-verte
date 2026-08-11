"""Tests du writer coefficient_maille (SQL/params, hors-ligne)."""

from __future__ import annotations

import json
from collections.abc import Sequence
from typing import Any

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.db.coefficient_repository import upsert_coefficient
from valeur_verte_data.degraded import CoverageMode


class _FakeCursor:
    def fetchall(self) -> list[tuple[Any, ...]]:
        return []


class _FakeConnection:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor()


def _maille(**overrides: Any) -> MailleCoefficient:
    base: dict[str, Any] = {
        "code_insee": "31555",
        "millesime": 2024,
        "mode": CoverageMode.LOCAL,
        "rayon_retenu_km": 15,
        "n_transactions": 120,
        "r2": 0.87,
        "erreur_mediane": 12.3,
        "coef_m2_bati": 1200.0,
        "coef_m2_terrain": 40.0,
        "coef_dpe": {"D": 0.0, "F": -25000.0},
        "ic_dpe": {"F": (-27000.0, -23000.0)},
        "alerte": None,
    }
    base.update(overrides)
    return MailleCoefficient(**base)


def test_upsert_coefficient_construit_l_upsert() -> None:
    conn = _FakeConnection()

    upsert_coefficient(conn, _maille())

    query, params = conn.calls[0]
    assert "data.coefficient_maille" in query
    assert "on conflict" in query.lower()
    assert params is not None
    assert params[0] == "31555"
    assert params[1] == 2024


def test_upsert_coefficient_serialise_les_jsonb() -> None:
    conn = _FakeConnection()

    upsert_coefficient(conn, _maille())

    _, params = conn.calls[0]
    assert params is not None
    assert json.loads(params[8])["F"] == -25000.0  # coef_dpe
    assert json.loads(params[9])["F"] == [-27000.0, -23000.0]  # ic_dpe


def test_upsert_coefficient_rayon_departemental_devient_zero() -> None:
    conn = _FakeConnection()

    upsert_coefficient(conn, _maille(mode=CoverageMode.DEPARTEMENTAL, rayon_retenu_km=None))

    _, params = conn.calls[0]
    assert params is not None
    assert params[2] == 0

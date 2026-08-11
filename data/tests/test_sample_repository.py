"""Tests de la sélection spatiale PostGIS (SQL/params, hors-ligne)."""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from valeur_verte_data.db.sample_repository import adaptive_radius, count_within_radius


class _FakeCursor:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


class _FakeConnection:
    def __init__(self, counts_by_radius_m: dict[int, int]) -> None:
        self.counts = counts_by_radius_m
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        radius_m = int(params[2]) if params is not None else 0
        return _FakeCursor([(self.counts.get(radius_m, 0),)])


def test_count_within_radius_construit_la_requete_dwithin() -> None:
    conn = _FakeConnection({10_000: 5})

    n = count_within_radius(conn, 43.6, 1.44, 10)

    assert n == 5
    query, params = conn.calls[0]
    assert "ST_DWithin" in query
    assert params == [1.44, 43.6, 10_000]  # lon, lat, rayon en mètres


def test_adaptive_radius_prend_le_plus_petit_suffisant() -> None:
    conn = _FakeConnection({10_000: 2, 15_000: 5, 20_000: 5, 30_000: 5, 50_000: 5})

    result = adaptive_radius(conn, 43.6, 1.44, min_sample=5)

    assert result == (15, 5, True)


def test_adaptive_radius_insuffisant_a_50km() -> None:
    conn = _FakeConnection(dict.fromkeys((10_000, 15_000, 20_000, 30_000, 50_000), 1))

    result = adaptive_radius(conn, 43.6, 1.44, min_sample=5)

    assert result == (50, 1, False)

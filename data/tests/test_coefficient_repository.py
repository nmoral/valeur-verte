"""Tests du writer coefficient_maille (SQL/params, hors-ligne)."""

from __future__ import annotations

import json
from collections.abc import Sequence
from typing import Any

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.db.coefficient_repository import (
    archiver_coefficient,
    lire_coefficient_precedent,
    upsert_coefficient,
)
from valeur_verte_data.degraded import CoverageMode, PalierFiabilite


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


def test_upsert_coefficient_persiste_cran_palier_bandes_effectifs() -> None:
    """#169 : le contrat data→app doit transporter le cran (mode), le tier (palier),
    le mapping des bandes et l'effectif par classe — sinon la restitution ne peut ni
    distinguer EPCI/DÉPT, ni servir une classe fusionnée, ni tolérer INV-1 au rechargement.
    """
    conn = _FakeConnection()

    upsert_coefficient(
        conn,
        _maille(
            mode=CoverageMode.EPCI,
            palier=PalierFiabilite.DEGRADE,
            bandes={"F": "F", "G": "F"},
            effectifs_dpe={"D": 20, "F": 18},
        ),
    )

    query, params = conn.calls[0]
    assert "mode" in query and "palier" in query and "bandes" in query
    assert params is not None
    assert params[11] == "epci"  # mode (valeur d'enum)
    assert params[12] == "degrade"  # palier
    assert json.loads(params[13]) == {"F": "F", "G": "F"}  # bandes
    assert json.loads(params[14]) == {"D": 20, "F": 18}  # effectifs_dpe


def test_archiver_coefficient_versionne_par_millesime() -> None:
    """REG-1 (#129) : l'archivage conserve chaque millésime (clé code_insee+millesime),
    séparément de la table vive coefficient_maille, pour comparer les coefficients d'un
    millésime au précédent sans casser le contrat de lecture app (une ligne / commune)."""
    conn = _FakeConnection()

    archiver_coefficient(conn, _maille())

    query, params = conn.calls[0]
    assert "coefficient_maille_historique" in query
    assert "on conflict (code_insee, millesime)" in query.lower()
    assert params is not None
    assert params[0] == "31555"
    assert params[1] == 2024


class _FakeCursorRows:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


class _FakeReadConnection:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows
        self.params: Sequence[Any] | None = None

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursorRows:
        self.params = params
        return _FakeCursorRows(self._rows)


def test_lire_coefficient_precedent_reconstruit_la_maille() -> None:
    """REG-1 (#129) : on relit le millésime antérieur le plus récent (millesime < N),
    reconstruit en MailleCoefficient — les JSONB reviennent en dict (psycopg), le rayon
    départemental stocké à 0 redevient None, mode/palier reviennent d'enum."""
    # Ordre des colonnes du SELECT (voir _SELECT_PRECEDENT).
    row = (
        "52001",
        2023,
        "epci",
        0,
        55,
        0.5,
        12.0,
        0.90,
        0.30,
        {"D": 0.0, "F": -0.20},
        {},
        None,
        "degrade",
        {"F": "F", "G": "F"},
        {"D": 20, "F": 18},
    )
    conn = _FakeReadConnection([row])

    maille = lire_coefficient_precedent(conn, "52001", 2024)

    assert conn.params == ["52001", 2024]
    assert maille is not None
    assert maille.millesime == 2023
    assert maille.mode is CoverageMode.EPCI
    assert maille.palier is PalierFiabilite.DEGRADE
    assert maille.rayon_retenu_km is None  # 0 stocké → repli sans rayon
    assert maille.coef_dpe == {"D": 0.0, "F": -0.20}
    assert maille.coef_m2_bati == 0.90
    assert maille.bandes == {"F": "F", "G": "F"}


def test_lire_coefficient_precedent_absent_renvoie_none() -> None:
    """Aucun millésime antérieur (première publication d'une commune) → None."""
    conn = _FakeReadConnection([])
    assert lire_coefficient_precedent(conn, "52001", 2024) is None

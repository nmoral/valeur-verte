"""Suppression des ventes d'une commune, préalable à un ré-import (#91).

``data.vente`` n'a pas de contrainte d'unicité sur la mutation : rejouer une
ingestion y ajouterait une seconde copie de chaque transaction, faussant tous
les coefficients calculés ensuite. Un ré-import remplace donc le contenu d'une
commune pour un millésime donné.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from valeur_verte_data.db.vente_repository import delete_mutations


class _FakeCursor:
    def fetchall(self) -> list[tuple[Any, ...]]:
        return []


class _FakeConnection:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor()


def test_la_suppression_cible_une_commune_et_un_millesime() -> None:
    """Sans les deux critères, un ré-import effacerait les autres années ou communes."""
    conn = _FakeConnection()

    delete_mutations(conn, "31555", 2024)

    assert len(conn.calls) == 1
    requete, params = conn.calls[0]
    assert "DELETE" in requete.upper()
    assert "data.vente" in requete
    assert params is not None
    assert list(params) == ["31555", 2024]

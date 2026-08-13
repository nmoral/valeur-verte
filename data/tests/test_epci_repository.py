"""Test unitaire de l'accès EPCI (parsing/paramètres, hors-ligne, fausse connexion).

Le comportement contre une vraie base (jointure PostGIS) est couvert par
``tests/integration/test_epci_db.py`` ; ici on isole le **parsing** du résultat et
le passage du paramètre, sans base.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from valeur_verte_data.db.epci_repository import communes_de_meme_epci


class _FakeCursor:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


class _FakeConnection:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows
        self.params: Sequence[Any] | None = None

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.params = params
        return _FakeCursor(self._rows)


def test_communes_de_meme_epci_normalise_et_passe_le_code() -> None:
    """Les codes INSEE sont lus en première colonne et détourés ; la requête est
    paramétrée par le code interrogé (jamais interpolé)."""
    conn = _FakeConnection([("31555 ",), (" 31149",)])

    result = communes_de_meme_epci(conn, "31555")

    assert result == ("31555", "31149")
    assert conn.params == ["31555"]


def test_communes_de_meme_epci_vide_si_commune_inconnue() -> None:
    """Aucune ligne (EPCI non enregistré) → tuple vide, pas d'erreur."""
    conn = _FakeConnection([])

    assert communes_de_meme_epci(conn, "99999") == ()

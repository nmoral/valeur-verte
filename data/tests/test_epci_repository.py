"""Test unitaire de l'accès EPCI (parsing/paramètres, hors-ligne, fausse connexion).

Le comportement contre une vraie base (jointure PostGIS) est couvert par
``tests/integration/test_epci_db.py`` ; ici on isole le **parsing** du résultat et
le passage du paramètre, sans base.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from valeur_verte_data.db.epci_repository import communes_de_meme_epci, upsert_epci_noms
from valeur_verte_data.decoupage import Commune


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


class _ConnexionEnregistreuse:
    """Fausse connexion qui retient chaque (requête, params) — sans base."""

    def __init__(self) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor([])


def _commune(code_insee: str, code_epci: str | None, nom_epci: str | None) -> Commune:
    return Commune(
        code_insee=code_insee,
        nom="",
        code_epci=code_epci,
        codes_postaux=(),
        centre_longitude=None,
        centre_latitude=None,
        code_departement="80",
        nom_epci=nom_epci,
    )


def test_upsert_epci_noms_ecrit_le_code_et_le_nom() -> None:
    conn = _ConnexionEnregistreuse()

    upsert_epci_noms(conn, [_commune("80021", "200069250", "Amiens Métropole")])

    requete, params = conn.calls[0]
    assert "data.epci" in requete
    assert params == ["200069250", "Amiens Métropole"]


def test_upsert_epci_noms_est_idempotent() -> None:
    """Rechargé à chaque ingestion : réécrire ne duplique pas et reprend un
    renommage d'EPCI (fusions intercommunales fréquentes)."""
    conn = _ConnexionEnregistreuse()

    upsert_epci_noms(conn, [_commune("80021", "200069250", "Amiens Métropole")])

    requete, _ = conn.calls[0]
    assert "on conflict (code_epci)" in requete.lower()
    assert "nom = excluded.nom" in requete.lower()


def test_upsert_epci_noms_dedoublonne_par_epci() -> None:
    """Toutes les communes d'un EPCI portent le même nom : on n'écrit l'EPCI
    qu'une fois, pas une ligne par commune."""
    conn = _ConnexionEnregistreuse()

    ecrits = upsert_epci_noms(
        conn,
        [
            _commune("80021", "200069250", "Amiens Métropole"),
            _commune("80001", "200069250", "Amiens Métropole"),
        ],
    )

    assert ecrits == 1
    assert len(conn.calls) == 1


def test_upsert_epci_noms_ignore_les_entrees_sans_code_ou_nom() -> None:
    """Une commune sans EPCI ou sans nom d'EPCI est sautée, sans faire échouer
    le reste du département."""
    conn = _ConnexionEnregistreuse()

    ecrits = upsert_epci_noms(
        conn,
        [
            _commune("80021", None, "Sans code"),
            _commune("80002", "200069250", "  "),
            _commune("80003", "240800391", "CC du Grand Roye"),
        ],
    )

    assert ecrits == 1
    assert [params[0] for _, params in conn.calls if params] == ["240800391"]


def test_upsert_epci_noms_sans_epci_valide_n_ecrit_rien() -> None:
    conn = _ConnexionEnregistreuse()

    assert upsert_epci_noms(conn, []) == 0
    assert conn.calls == []

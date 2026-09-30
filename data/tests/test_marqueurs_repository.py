"""Écriture des marqueurs territoriaux (#341) dans ``data.marqueurs``.

Un marqueur qualifie une commune ou un département (portée exclusive) par un
attribut lisible, mesuré ou contextuel, avec sa source. L'écriture est idempotente
(rechargée à chaque ingestion de référentiel) et ignore les entrées bancales
plutôt que de faire échouer tout le lot — comme le référentiel des communes (#220).
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from valeur_verte_data.db.marqueurs_repository import upsert_marqueurs
from valeur_verte_data.marqueurs import Marqueur


class _FakeCursor:
    def fetchall(self) -> list[tuple[Any, ...]]:
        return []


class _FakeConnection:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor()


def _departemental(nom: str = "climat", valeur: str = "froid") -> Marqueur:
    return Marqueur(
        code_insee=None,
        code_departement="55",
        nom=nom,
        valeur=valeur,
        type_marqueur="contextuel",
        source="Zonage climatique RT2012",
    )


def _communal(nom: str = "densite", valeur: str = "rural") -> Marqueur:
    return Marqueur(
        code_insee="55039",
        code_departement=None,
        nom=nom,
        valeur=valeur,
        type_marqueur="contextuel",
        source="Grille de densité INSEE",
    )


def test_upsert_ecrit_un_marqueur_departemental() -> None:
    conn = _FakeConnection()

    upsert_marqueurs(conn, [_departemental()])

    requete, params = conn.calls[0]
    assert "data.marqueurs" in requete
    assert params == [None, "55", "climat", "froid", "contextuel", "Zonage climatique RT2012"]


def test_upsert_ecrit_un_marqueur_communal() -> None:
    conn = _FakeConnection()

    upsert_marqueurs(conn, [_communal()])

    _, params = conn.calls[0]
    assert params == ["55039", None, "densite", "rural", "contextuel", "Grille de densité INSEE"]


def test_upsert_est_idempotent_par_cible() -> None:
    """Le référentiel est rechargé : réécrire le même marqueur met à jour la
    valeur au lieu de dupliquer (ON CONFLICT sur la contrainte d'unicité)."""
    conn = _FakeConnection()

    upsert_marqueurs(conn, [_departemental()])

    requete, _ = conn.calls[0]
    assert "on conflict (code_insee, code_departement, nom)" in requete.lower()
    assert "valeur = excluded.valeur" in requete.lower()


def test_upsert_rend_le_nombre_de_lignes_ecrites() -> None:
    conn = _FakeConnection()

    assert upsert_marqueurs(conn, [_departemental(), _communal()]) == 2


def test_un_marqueur_sans_portee_est_ignore() -> None:
    """Ni commune ni département : la portée est obligatoire (CHECK en base).
    On saute l'entrée plutôt que de lever une contrainte au milieu du lot."""
    conn = _FakeConnection()

    ecrites = upsert_marqueurs(
        conn,
        [
            Marqueur(None, None, "climat", "froid", "contextuel", "src"),
            _departemental(),
        ],
    )

    assert ecrites == 1
    assert [params[1] for _, params in conn.calls if params] == ["55"]


def test_un_marqueur_a_double_portee_est_ignore() -> None:
    """Commune ET département : portée ambiguë, refusée par le CHECK en base.
    On la saute côté écriture pour ne pas casser le lot."""
    conn = _FakeConnection()

    ecrites = upsert_marqueurs(
        conn,
        [Marqueur("55039", "55", "climat", "froid", "contextuel", "src")],
    )

    assert ecrites == 0
    assert conn.calls == []


def test_un_marqueur_sans_valeur_est_ignore() -> None:
    conn = _FakeConnection()

    upsert_marqueurs(conn, [_departemental(valeur="  ")])

    assert conn.calls == []


def test_sans_aucun_marqueur_rien_n_est_ecrit() -> None:
    conn = _FakeConnection()

    assert upsert_marqueurs(conn, []) == 0

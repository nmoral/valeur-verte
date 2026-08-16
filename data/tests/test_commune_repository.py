"""Table de référence des communes (#220) : nom lisible depuis un code INSEE.

Le pipeline récupère déjà `nom` du référentiel (`decoupage_source._FIELDS`) sans le
persister : les écrans n'ont donc que des codes bruts à afficher. On le stocke une
fois, au lieu d'interroger une API publique à chaque ligne de tableau.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from valeur_verte_data.db.commune_repository import upsert_communes
from valeur_verte_data.decoupage import Commune


class _FakeCursor:
    def fetchall(self) -> list[tuple[Any, ...]]:
        return []


class _FakeConnection:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor()


def _commune(code_insee: str, nom: str, departement: str | None = "31") -> Commune:
    return Commune(
        code_insee=code_insee,
        nom=nom,
        code_epci=None,
        codes_postaux=(),
        centre_longitude=1.44,
        centre_latitude=43.6,
        code_departement=departement,
    )


def test_upsert_communes_ecrit_le_code_et_le_nom() -> None:
    conn = _FakeConnection()

    upsert_communes(conn, [_commune("31555", "Toulouse")])

    requete, params = conn.calls[0]
    assert "data.commune" in requete
    assert params == ["31555", "Toulouse", "31"]


def test_upsert_communes_est_idempotent() -> None:
    """Le référentiel est rechargé à chaque ingestion : réécrire ne doit pas
    dupliquer, et un renommage de commune doit bien être repris."""
    conn = _FakeConnection()

    upsert_communes(conn, [_commune("31555", "Toulouse")])

    requete, _ = conn.calls[0]
    assert "on conflict (code_insee)" in requete.lower()
    assert "nom = excluded.nom" in requete.lower()


def test_upsert_communes_ecrit_chaque_commune() -> None:
    conn = _FakeConnection()

    upsert_communes(conn, [_commune("31555", "Toulouse"), _commune("31149", "Colomiers")])

    assert [params[0] for _, params in conn.calls if params] == ["31555", "31149"]


def test_upsert_communes_rend_le_nombre_de_lignes_ecrites() -> None:
    """Le compte alimente le rapport d'ingestion : sans lui, l'opérateur ne voit
    pas si le référentiel a été rafraîchi."""
    conn = _FakeConnection()

    assert (
        upsert_communes(conn, [_commune("31555", "Toulouse"), _commune("31149", "Colomiers")]) == 2
    )


def test_une_entree_incomplete_n_interrompt_pas_les_suivantes() -> None:
    """Ignorer une entrée bancale ne doit pas faire perdre tout le reste du
    département — on la saute, on ne s'arrête pas."""
    conn = _FakeConnection()

    ecrites = upsert_communes(
        conn,
        [_commune("31555", "  "), _commune("31149", "Colomiers")],
    )

    assert ecrites == 1
    assert [params[0] for _, params in conn.calls if params] == ["31149"]


def test_sans_aucune_commune_valide_rien_n_est_ecrit() -> None:
    conn = _FakeConnection()

    assert upsert_communes(conn, []) == 0


def test_une_commune_sans_departement_est_ignoree() -> None:
    """Le département est requis en base (il sert à recharger par lot). Une entrée
    incomplète du référentiel ne doit pas faire échouer toute l'ingestion."""
    conn = _FakeConnection()

    upsert_communes(conn, [_commune("31555", "Toulouse", departement=None)])

    assert conn.calls == []


def test_une_commune_sans_nom_est_ignoree() -> None:
    conn = _FakeConnection()

    upsert_communes(conn, [_commune("31555", "  ")])

    assert conn.calls == []

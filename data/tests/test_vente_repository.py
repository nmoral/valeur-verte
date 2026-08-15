"""Tests du writer data.vente (génération SQL/params, hors-ligne)."""

from __future__ import annotations

from collections.abc import Sequence
from dataclasses import replace
from typing import Any

from valeur_verte_data.db.vente_repository import insert_mutations
from valeur_verte_data.dvf import Mutation, aggregate_mutations


def _mutation(**overrides: str) -> Mutation:
    row = {
        "id_mutation": "m",
        "valeur_fonciere": "200000",
        "code_commune": "31555",
        "code_postal": "31000",
        "surface_reelle_bati": "80",
        "adresse_numero": "12",
        "adresse_nom_voie": "RUE DES FLEURS",
        "longitude": "1.44",
        "latitude": "43.6",
    }
    row.update(overrides)
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


class _FakeCursor:
    def fetchall(self) -> list[tuple[Any, ...]]:
        return []


class _FakeConnection:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor()


def test_insert_mutations_insere_chaque_ligne() -> None:
    conn = _FakeConnection()

    inserted = insert_mutations(conn, [_mutation(id_mutation="a"), _mutation(id_mutation="b")])

    assert inserted == 2
    assert len(conn.calls) == 2
    query, params = conn.calls[0]
    assert "data.vente" in query
    assert params is not None
    assert params[3] == "31555"  # code_insee


def test_insert_mutations_calcule_adresse_key_normalisee() -> None:
    conn = _FakeConnection()

    insert_mutations(conn, [_mutation()])

    _, params = conn.calls[0]
    assert params is not None
    assert params[15] == "12 rue fleurs"


def test_insert_mutations_adresse_key_vide_donne_none() -> None:
    conn = _FakeConnection()

    insert_mutations(conn, [_mutation(adresse_nom_voie="")])

    _, params = conn.calls[0]
    assert params is not None
    assert params[15] is None


def test_insert_mutations_inclut_le_suffixe_dans_adresse_key() -> None:
    conn = _FakeConnection()

    insert_mutations(conn, [_mutation(adresse_suffixe="bis")])

    _, params = conn.calls[0]
    assert params is not None
    assert params[15] == "12 bis rue fleurs"


def test_insert_mutations_ecrit_date_et_version_dpe() -> None:
    # #211 : la date d'établissement et la version du DPE sont persistées, juste
    # après periode_construction (indices 18/19), avant les coordonnées.
    conn = _FakeConnection()
    mutation = replace(_mutation(), date_etablissement_dpe="2023-07-10", version_dpe="2.3")

    insert_mutations(conn, [mutation])

    query, params = conn.calls[0]
    assert "date_etablissement_dpe" in query
    assert "version_dpe" in query
    assert params is not None
    assert params[18] == "2023-07-10"
    assert params[19] == "2.3"


def test_insert_mutations_passe_les_coordonnees_au_geom() -> None:
    conn = _FakeConnection()

    insert_mutations(conn, [_mutation()])

    query, params = conn.calls[0]
    assert "ST_MakePoint" in query
    assert params is not None
    assert params[22] == 1.44
    assert params[23] == 43.6

"""Test d'intégration : écriture réelle dans data.vente + géométrie PostGIS.

Joué en CI contre un service ``postgis/postgis`` ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.vente_repository import insert_mutations
from valeur_verte_data.dvf import Mutation, aggregate_mutations

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def _mutation(id_mutation: str) -> Mutation:
    row = {
        "id_mutation": id_mutation,
        "date_mutation": "2024-03-01",
        "valeur_fonciere": "200000",
        "code_commune": "31555",
        "code_postal": "31000",
        "surface_reelle_bati": "80",
        "adresse_numero": "12",
        "adresse_nom_voie": "RUE DES FLEURS",
        "longitude": "1.44",
        "latitude": "43.6",
    }
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


def test_insert_mutations_ecrit_la_ligne_et_le_geom() -> None:
    cli.run(os.environ)  # garantit le schéma

    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.vente")
        inserted = insert_mutations(conn, [_mutation("itest-1")])
        conn.commit()

        assert inserted == 1
        row = conn.execute(
            "SELECT code_insee, adresse_key, "
            "ST_X(geom::geometry), ST_Y(geom::geometry) "
            "FROM data.vente WHERE id_mutation = 'itest-1'"
        ).fetchone()

    assert row is not None
    assert row[0] == "31555"
    assert row[1] == "12 rue fleurs"
    assert round(row[2], 2) == 1.44
    assert round(row[3], 2) == 43.6

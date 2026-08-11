"""Test d'intégration : sélection spatiale ST_DWithin sur PostGIS.

Insère des points à distances connues et vérifie le comptage par rayon et
l'élargissement adaptatif. Joué en CI ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.sample_repository import adaptive_radius, count_within_radius
from valeur_verte_data.db.vente_repository import insert_mutations
from valeur_verte_data.dvf import Mutation, aggregate_mutations

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]

_CENTER_LAT = 43.6
_CENTER_LON = 1.44


def _at(id_mutation: str, lat: float, lon: float) -> Mutation:
    row = {
        "id_mutation": id_mutation,
        "valeur_fonciere": "200000",
        "code_commune": "31555",
        "surface_reelle_bati": "80",
        "latitude": str(lat),
        "longitude": str(lon),
    }
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


def test_selection_spatiale_par_rayon() -> None:
    cli.run(os.environ)
    # 0 km, ~1,1 km, ~12,2 km du centre.
    mutations = [
        _at("centre", 43.6, 1.44),
        _at("proche", 43.61, 1.44),
        _at("loin", 43.71, 1.44),
    ]

    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.vente")
        insert_mutations(conn, mutations)
        conn.commit()

        assert count_within_radius(conn, _CENTER_LAT, _CENTER_LON, 10) == 2
        assert count_within_radius(conn, _CENTER_LAT, _CENTER_LON, 15) == 3
        assert adaptive_radius(conn, _CENTER_LAT, _CENTER_LON, min_sample=2) == (10, 2, True)
        assert adaptive_radius(conn, _CENTER_LAT, _CENTER_LON, min_sample=3) == (15, 3, True)

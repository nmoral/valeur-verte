"""Test d'intégration : contiguïté communale dérivée par PostGIS (INV-3, #121).

Deux communes sont limitrophes si leurs contours **partagent une frontière**
(``ST_Touches``). Le test vérifie qu'une paire jointive est retenue, qu'une
commune disjointe est ignorée, et surtout qu'une **frontière inter-département**
est captée (cœur du franchissement de frontières, #24/#91).

Joué en CI ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import json
import os

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.jetable import exiger_base_jetable
from valeur_verte_data.db.limitrophe_repository import (
    derive_limitrophes,
    read_adjacence,
    upsert_contour,
)

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def _carre(x0: float, y0: float) -> str:
    # Carré de 0,1° de côté, coin bas-gauche (x0, y0), en GeoJSON WGS84.
    anneau = [
        [x0, y0],
        [x0 + 0.1, y0],
        [x0 + 0.1, y0 + 0.1],
        [x0, y0 + 0.1],
        [x0, y0],
    ]
    return json.dumps({"type": "Polygon", "coordinates": [anneau]})


def test_contiguite_derivee_par_st_touches() -> None:
    cli.run(os.environ)
    exiger_base_jetable(os.environ)

    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.commune_limitrophe")
        conn.execute("TRUNCATE data.commune_contour")
        # A (31) et B (31) partagent l'arête x=1,1 → limitrophes.
        upsert_contour(conn, "31001", "31", _carre(1.0, 43.0))
        upsert_contour(conn, "31002", "31", _carre(1.1, 43.0))
        # C (31) est disjointe → pas limitrophe.
        upsert_contour(conn, "31003", "31", _carre(2.0, 44.0))
        # D (32, département limitrophe) partage l'arête x=1,0 avec A → frontière
        # inter-département captée.
        upsert_contour(conn, "32001", "32", _carre(0.9, 43.0))
        conn.commit()

        paires = derive_limitrophes(conn)
        conn.commit()

        adjacence = read_adjacence(conn, "31")

    # 31001-31002 et 31001-32001 : deux paires, pas plus.
    assert paires == 2
    assert set(adjacence["31001"]) == {"31002", "32001"}
    assert adjacence["31002"] == ("31001",)
    assert "31003" not in adjacence


def test_derive_limitrophes_est_idempotent() -> None:
    cli.run(os.environ)
    exiger_base_jetable(os.environ)

    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.commune_limitrophe")
        conn.execute("TRUNCATE data.commune_contour")
        upsert_contour(conn, "31001", "31", _carre(1.0, 43.0))
        upsert_contour(conn, "31002", "31", _carre(1.1, 43.0))
        conn.commit()

        derive_limitrophes(conn)
        conn.commit()
        # Deuxième passe : aucune paire en double.
        second = derive_limitrophes(conn)
        conn.commit()

        total = conn.execute("SELECT count(*) FROM data.commune_limitrophe").fetchone()

    assert second == 0
    assert total is not None
    assert total[0] == 1

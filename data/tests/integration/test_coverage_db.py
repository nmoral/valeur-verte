"""Test d'intégration : couverture (upsert statut) + log hors zone sur PostGIS.

Joué en CI ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.coverage_repository import (
    department_statuses,
    log_out_of_zone,
    set_department_status,
)

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def test_couverture_upsert_et_journal_hors_zone() -> None:
    cli.run(os.environ)

    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.couverture_departement")
        conn.execute("TRUNCATE data.requete_hors_zone")
        set_department_status(conn, "31", "charge")
        set_department_status(conn, "31", "calcule")  # upsert : remplace 'charge'
        log_out_of_zone(conn, "12345", "12000")
        conn.commit()

        statuses = department_statuses(conn)
        row = conn.execute(
            "SELECT count(*) FROM data.requete_hors_zone WHERE code_insee = '12345'"
        ).fetchone()

    assert statuses["31"] == "calcule"
    assert row is not None
    assert row[0] == 1

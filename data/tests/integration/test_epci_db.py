"""Test d'intégration : appartenance EPCI des communes (#169).

Le cran EPCI de la cascade de couverture regroupe les ventes des communes d'un
même EPCI. On persiste l'appartenance ``code_insee → code_epci`` (source :
``codeEpci`` de geo.api.gouv.fr) et on interroge les communes du même EPCI.

Joué en CI ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.epci_repository import communes_de_meme_epci, upsert_epci
from valeur_verte_data.db.jetable import exiger_base_jetable

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def test_communes_du_meme_epci() -> None:
    cli.run(os.environ)
    exiger_base_jetable(os.environ)
    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.commune_epci")
        upsert_epci(conn, "52001", "200068658")
        upsert_epci(conn, "52002", "200068658")
        upsert_epci(conn, "52050", "240700789")  # un autre EPCI
        conn.commit()

        # La commune interrogée est incluse ; l'autre EPCI est exclu.
        assert set(communes_de_meme_epci(conn, "52001")) == {"52001", "52002"}
        assert set(communes_de_meme_epci(conn, "52050")) == {"52050"}
        # Commune inconnue : aucun EPCI, donc aucune commune.
        assert communes_de_meme_epci(conn, "99999") == ()


def test_upsert_epci_est_idempotent() -> None:
    cli.run(os.environ)
    exiger_base_jetable(os.environ)
    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.commune_epci")
        upsert_epci(conn, "52001", "200068658")
        upsert_epci(conn, "52001", "240700789")  # ré-ingestion : l'EPCI est mis à jour
        conn.commit()

        rows = conn.execute(
            "SELECT code_epci FROM data.commune_epci WHERE code_insee = %s", ["52001"]
        ).fetchall()
        assert rows == [("240700789",)]

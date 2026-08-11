"""Test d'intégration : application réelle des migrations sur PostGIS.

Joué en CI contre un service ``postgis/postgis`` ; ignoré en local si
``DATABASE_URL`` n'est pas défini (voir le job ``integration`` de data-ci).
"""

from __future__ import annotations

import os
from typing import Any

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.migrations import load_migrations

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def _scalar(conn: psycopg.Connection[Any], query: str) -> object:
    row = conn.execute(query).fetchone()
    return None if row is None else row[0]


def test_migrations_creent_le_schema_postgis_et_sont_idempotentes() -> None:
    cli.run(os.environ)

    # Rejouer ne réapplique rien : suivi dans public.schema_migrations.
    # (Assertion indépendante de l'ordre des tests d'intégration.)
    assert cli.run(os.environ) == []

    with psycopg.connect(DSN) as conn:
        versions = {
            row[0]
            for row in conn.execute("SELECT version FROM public.schema_migrations").fetchall()
        }
        assert versions == {migration.version for migration in load_migrations()}
        assert (
            _scalar(
                conn,
                "SELECT 1 FROM information_schema.schemata WHERE schema_name = 'data'",
            )
            == 1
        )
        assert _scalar(conn, "SELECT 1 FROM pg_extension WHERE extname = 'postgis'") == 1
        assert (
            _scalar(
                conn,
                "SELECT 1 FROM information_schema.tables "
                "WHERE table_schema = 'data' AND table_name = 'vente'",
            )
            == 1
        )
        assert (
            _scalar(
                conn,
                "SELECT 1 FROM information_schema.columns "
                "WHERE table_schema = 'data' AND table_name = 'vente' "
                "AND column_name = 'geom'",
            )
            == 1
        )
        assert (
            _scalar(
                conn,
                "SELECT 1 FROM pg_indexes "
                "WHERE schemaname = 'data' AND indexname = 'idx_vente_geom'",
            )
            == 1
        )
        assert _scalar(conn, "SELECT 1 FROM pg_roles WHERE rolname = 'valeur_verte_app'") == 1

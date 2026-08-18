"""Test d'intégration DAT-2 (#125) : lecture de la volumétrie précédente dans rapport_batch.

Le nombre de ventes DVF insérées par (dept, millésime) est déjà **archivé** par #131
(``mutations_inserees``, une ligne d'ingestion par exécution dans
``data.rapport_batch``). DAT-2 relit la volumétrie du **millésime précédent** pour
mesurer l'écart ± 30 % ; la volumétrie courante, elle, est en main en fin
d'ingestion (pas relue). La dernière exécution d'un millésime fait foi (jamais
d'``UPDATE``).

Joué en CI contre PostGIS ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os
from datetime import datetime

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.jetable import exiger_base_jetable
from valeur_verte_data.db.rapport_batch_repository import (
    enregistrer_rapport_ingestion,
    lire_volumetrie_precedent,
)
from valeur_verte_data.orchestration import RapportIngestion

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def _base_vide() -> psycopg.Connection:
    cli.run(os.environ)
    conn = psycopg.connect(DSN)
    exiger_base_jetable(os.environ)
    conn.execute("TRUNCATE data.rapport_batch")
    conn.commit()
    return conn


def _ingest(
    conn: psycopg.Connection,
    departement: str,
    millesime: int,
    mutations: int,
    debut: datetime,
) -> None:
    rapport = RapportIngestion(
        departement=departement,
        millesime=millesime,
        communes_traitees=1,
        mutations_inserees=mutations,
    )
    enregistrer_rapport_ingestion(conn, rapport, debut=debut, fin=debut)


def test_lit_la_volumetrie_du_millesime_precedent() -> None:
    with _base_vide() as conn:
        _ingest(conn, "31", 2022, 800, datetime(2024, 1, 1))
        _ingest(conn, "31", 2024, 1000, datetime(2026, 1, 1))
        _ingest(conn, "31", 2025, 1100, datetime(2027, 1, 1))
        conn.commit()
        assert lire_volumetrie_precedent(conn, "31", 2025) == 1000


def test_volumetrie_precedente_absente_rend_none() -> None:
    with _base_vide() as conn:
        _ingest(conn, "31", 2025, 1100, datetime(2027, 1, 1))  # aucun antérieur
        conn.commit()
        assert lire_volumetrie_precedent(conn, "31", 2025) is None


def test_la_derniere_execution_du_precedent_fait_foi() -> None:
    # Deux exécutions du millésime précédent (jamais d'UPDATE) : la plus récente compte.
    with _base_vide() as conn:
        _ingest(conn, "31", 2024, 1000, datetime(2026, 1, 1))
        _ingest(conn, "31", 2024, 1500, datetime(2026, 2, 1))  # ré-ingestion
        conn.commit()
        assert lire_volumetrie_precedent(conn, "31", 2025) == 1500


def test_volumetrie_precedente_ignore_les_autres_departements() -> None:
    with _base_vide() as conn:
        _ingest(conn, "09", 2024, 1000, datetime(2026, 1, 1))
        conn.commit()
        assert lire_volumetrie_precedent(conn, "31", 2025) is None

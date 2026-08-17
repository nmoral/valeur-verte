"""Test d'intégration DAT-3 (#126) : lecture du taux d'appariement dans rapport_batch.

Le taux DVF ↔ DPE par département est déjà **archivé** par #131 (une ligne
d'ingestion par exécution dans ``data.rapport_batch`` : ``ventes_appariees`` /
``mutations_inserees``). DAT-3 n'a pas besoin d'une table parallèle — il **relit**
ce taux : celui du millésime courant (pour l'évaluer) et celui du précédent (pour
mesurer la chute). La dernière exécution d'un millésime fait foi (jamais d'``UPDATE``).

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
    lire_taux_appariement,
    lire_taux_appariement_precedent,
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
    appariees: int,
    debut: datetime,
) -> None:
    rapport = RapportIngestion(
        departement=departement,
        millesime=millesime,
        communes_traitees=1,
        mutations_inserees=mutations,
        ventes_appariees=appariees,
    )
    enregistrer_rapport_ingestion(conn, rapport, debut=debut, fin=debut)


# --- taux du millésime courant ---


def test_lit_le_taux_du_millesime() -> None:
    with _base_vide() as conn:
        _ingest(conn, "31", 2024, 100, 72, datetime(2026, 1, 1))
        conn.commit()
        taux = lire_taux_appariement(conn, "31", 2024)
        assert isinstance(taux, float)
        assert taux == pytest.approx(0.72)


def test_taux_absent_rend_none() -> None:
    with _base_vide() as conn:
        assert lire_taux_appariement(conn, "31", 2024) is None


def test_ingestion_sans_mutation_rend_none() -> None:
    # 0 mutation insérée : taux indéfini (pas de 0/0), donc None — pas de faux 0 %.
    with _base_vide() as conn:
        _ingest(conn, "31", 2024, 0, 0, datetime(2026, 1, 1))
        conn.commit()
        assert lire_taux_appariement(conn, "31", 2024) is None


def test_la_derniere_execution_fait_foi() -> None:
    # Deux exécutions du même millésime (jamais d'UPDATE) : la plus récente compte.
    with _base_vide() as conn:
        _ingest(conn, "31", 2024, 100, 90, datetime(2026, 1, 1))
        _ingest(conn, "31", 2024, 100, 50, datetime(2026, 2, 1))  # ré-ingestion
        conn.commit()
        assert lire_taux_appariement(conn, "31", 2024) == pytest.approx(0.50)


# --- taux du millésime précédent (chute) ---


def test_precedent_prend_le_millesime_le_plus_recent_anterieur() -> None:
    with _base_vide() as conn:
        _ingest(conn, "31", 2022, 100, 50, datetime(2024, 1, 1))
        _ingest(conn, "31", 2024, 100, 70, datetime(2026, 1, 1))
        _ingest(conn, "31", 2025, 100, 80, datetime(2027, 1, 1))
        conn.commit()
        assert lire_taux_appariement_precedent(conn, "31", 2025) == pytest.approx(0.70)


def test_precedent_absent_rend_none() -> None:
    with _base_vide() as conn:
        _ingest(conn, "31", 2025, 100, 80, datetime(2027, 1, 1))  # aucun antérieur
        conn.commit()
        assert lire_taux_appariement_precedent(conn, "31", 2025) is None


def test_precedent_ignore_les_autres_departements() -> None:
    with _base_vide() as conn:
        _ingest(conn, "09", 2024, 100, 40, datetime(2026, 1, 1))
        conn.commit()
        assert lire_taux_appariement_precedent(conn, "31", 2025) is None

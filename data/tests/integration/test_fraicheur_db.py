"""Test d'intégration DAT-5 (#128) : date de mutation la plus récente, mesurée/archivée.

``date_mutation_max`` mesure sur ``data.vente`` (jointe à ``data.commune`` pour borner
le département) la date de mutation la plus récente. Elle est **archivée** à
l'ingestion dans ``data.rapport_batch`` (``mutation_la_plus_recente``, #131, migration
016) — source de vérité de la fraîcheur/millésime consommée par l'admin (#46/#48). La
porte de fraîcheur (alerte, non bloquante) juge cette date au regard de la date
d'exécution.

Joué en CI contre PostGIS ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os
from datetime import date, datetime

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.jetable import exiger_base_jetable
from valeur_verte_data.db.rapport_batch_repository import enregistrer_rapport_ingestion
from valeur_verte_data.db.vente_repository import date_mutation_max, insert_mutations
from valeur_verte_data.dvf import Mutation, aggregate_mutations
from valeur_verte_data.orchestration import RapportIngestion

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def _vente(id_mutation: str, code_insee: str, *, date_mutation: str) -> Mutation:
    row = {
        "id_mutation": id_mutation,
        "valeur_fonciere": "200000",
        "code_commune": code_insee,
        "surface_reelle_bati": "80",
        "date_mutation": date_mutation,
        "latitude": "43.6",
        "longitude": "1.44",
    }
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


def _base_vide() -> psycopg.Connection:
    cli.run(os.environ)
    conn = psycopg.connect(DSN)
    exiger_base_jetable(os.environ)
    conn.execute("TRUNCATE data.vente")
    conn.execute("TRUNCATE data.rapport_batch")
    conn.execute("DELETE FROM data.commune WHERE code_departement IN ('31', '09')")
    conn.execute(
        "INSERT INTO data.commune (code_insee, nom, code_departement) VALUES ('31555', 'X', '31')"
    )
    conn.commit()
    return conn


def test_date_mutation_max_prend_la_plus_recente() -> None:
    with _base_vide() as conn:
        insert_mutations(
            conn,
            [
                _vente("m1", "31555", date_mutation="2024-03-01"),
                _vente("m2", "31555", date_mutation="2024-11-20"),
                _vente("m3", "31555", date_mutation="2024-07-15"),
            ],
        )
        conn.commit()
        assert date_mutation_max(conn, "31", 2024) == date(2024, 11, 20)


def test_date_mutation_max_absente_rend_none() -> None:
    with _base_vide() as conn:
        assert date_mutation_max(conn, "31", 2024) is None


def test_date_mutation_max_ne_compte_que_le_departement_demande() -> None:
    with _base_vide() as conn:
        conn.execute(
            "INSERT INTO data.commune (code_insee, nom, code_departement) "
            "VALUES ('09122', 'Y', '09')"
        )
        conn.commit()
        insert_mutations(
            conn,
            [
                _vente("m1", "31555", date_mutation="2024-01-10"),
                _vente("mA", "09122", date_mutation="2024-12-31"),
            ],
        )
        conn.commit()
        # La mutation 09 (plus récente) ne doit pas remonter pour le 31.
        assert date_mutation_max(conn, "31", 2024) == date(2024, 1, 10)


def test_archive_la_date_de_mutation_la_plus_recente() -> None:
    with _base_vide() as conn:
        rapport = RapportIngestion(departement="31", millesime=2024, communes_traitees=1)
        rapport.mutation_la_plus_recente = date(2024, 11, 20)
        moment = datetime(2026, 1, 1)
        enregistrer_rapport_ingestion(conn, rapport, debut=moment, fin=moment)
        conn.commit()

        (archivee,) = conn.execute(
            "SELECT mutation_la_plus_recente FROM data.rapport_batch "
            "WHERE phase = 'ingestion' AND departement = '31' AND millesime = 2024"
        ).fetchall()[0]
        assert archivee == date(2024, 11, 20)

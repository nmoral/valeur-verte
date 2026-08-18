"""Test d'intégration DAT-4 (#127) : distributions mesurées puis archivées/relues.

``calculer_distribution`` mesure sur ``data.vente`` (jointe à ``data.commune`` pour
borner le département) le prix médian, la surface bâtie médiane, la part de terrain
nul/aberrant et l'histogramme DPE. Ces distributions sont **archivées** à l'ingestion
dans ``data.rapport_batch`` (JSONB, #131) puis **relues** à la porte au calcul :
``lire_distribution`` (millésime courant, plausibilité) et
``lire_distribution_dpe_precedent`` (répartition DPE du millésime précédent).

Joué en CI contre PostGIS ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import dataclasses
import os
from datetime import datetime

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.jetable import exiger_base_jetable
from valeur_verte_data.db.rapport_batch_repository import (
    enregistrer_rapport_ingestion,
    lire_distribution,
    lire_distribution_dpe_precedent,
)
from valeur_verte_data.db.vente_repository import calculer_distribution, insert_mutations
from valeur_verte_data.distributions import Distribution
from valeur_verte_data.dvf import Mutation, aggregate_mutations
from valeur_verte_data.orchestration import RapportIngestion

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]

_BORNES = {"terrain_min": 50.0, "terrain_max": 5_000.0}


def _vente(
    id_mutation: str,
    code_insee: str,
    *,
    prix: float,
    bati: float,
    terrain: float | None,
    dpe: str | None,
) -> Mutation:
    row = {
        "id_mutation": id_mutation,
        "valeur_fonciere": str(prix),
        "code_commune": code_insee,
        "surface_reelle_bati": str(bati),
        "surface_terrain": "" if terrain is None else str(terrain),
        "latitude": "43.6",
        "longitude": "1.44",
    }
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return dataclasses.replace(mutation, etiquette_dpe=dpe)


def _base_vide() -> psycopg.Connection:
    cli.run(os.environ)
    conn = psycopg.connect(DSN)
    exiger_base_jetable(os.environ)
    conn.execute("TRUNCATE data.vente")
    conn.execute("TRUNCATE data.rapport_batch")
    conn.execute("DELETE FROM data.commune WHERE code_departement = '31'")
    conn.execute(
        "INSERT INTO data.commune (code_insee, nom, code_departement) VALUES ('31555', 'X', '31')"
    )
    conn.commit()
    return conn


def test_calcule_medianes_terrain_et_histogramme_dpe() -> None:
    with _base_vide() as conn:
        insert_mutations(
            conn,
            [
                _vente("m1", "31555", prix=100_000, bati=80, terrain=500, dpe="D"),
                _vente("m2", "31555", prix=200_000, bati=100, terrain=None, dpe="D"),
                _vente("m3", "31555", prix=300_000, bati=120, terrain=30, dpe="E"),
            ],
        )
        conn.commit()

        distribution = calculer_distribution(conn, "31", 2024, **_BORNES)

        assert distribution.prix_median == pytest.approx(200_000.0)
        assert distribution.surface_bati_median == pytest.approx(100.0)
        # m2 (terrain nul) + m3 (30 m² < 50) = 2 aberrants sur 3 ventes.
        assert distribution.terrain_aberrant == 2
        assert distribution.effectif == 3
        assert distribution.effectifs_dpe == {"D": 2, "E": 1}


def test_ne_compte_que_le_departement_demande() -> None:
    with _base_vide() as conn:
        conn.execute(
            "INSERT INTO data.commune (code_insee, nom, code_departement) "
            "VALUES ('09122', 'Y', '09')"
        )
        conn.commit()
        insert_mutations(
            conn,
            [
                _vente("m1", "31555", prix=200_000, bati=100, terrain=500, dpe="D"),
                _vente("mA", "09122", prix=999_000, bati=250, terrain=10, dpe="G"),
            ],
        )
        conn.commit()

        distribution = calculer_distribution(conn, "31", 2024, **_BORNES)

        assert distribution.effectif == 1
        assert distribution.effectifs_dpe == {"D": 1}


def _archiver(conn: psycopg.Connection, millesime: int, distribution: Distribution) -> None:
    rapport = RapportIngestion(departement="31", millesime=millesime, communes_traitees=1)
    rapport.distribution = distribution
    enregistrer_rapport_ingestion(conn, rapport, debut=datetime(2026, 1, 1), fin=datetime(2026, 1, 1))


def test_archive_puis_relit_la_distribution_courante() -> None:
    with _base_vide() as conn:
        distribution = Distribution(200_000.0, 100.0, 120, 1000, {"D": 500, "E": 500})
        _archiver(conn, 2024, distribution)
        conn.commit()

        assert lire_distribution(conn, "31", 2024) == distribution


def test_distribution_courante_absente_rend_none() -> None:
    with _base_vide() as conn:
        assert lire_distribution(conn, "31", 2024) is None


def test_relit_l_histogramme_dpe_du_millesime_precedent() -> None:
    with _base_vide() as conn:
        _archiver(conn, 2023, Distribution(190_000.0, 99.0, 100, 900, {"D": 450, "E": 450}))
        _archiver(conn, 2024, Distribution(200_000.0, 100.0, 120, 1000, {"D": 600, "E": 400}))
        conn.commit()

        assert lire_distribution_dpe_precedent(conn, "31", 2024) == {"D": 450, "E": 450}


def test_histogramme_dpe_precedent_absent_rend_none() -> None:
    with _base_vide() as conn:
        _archiver(conn, 2024, Distribution(200_000.0, 100.0, 120, 1000, {"D": 600, "E": 400}))
        conn.commit()

        assert lire_distribution_dpe_precedent(conn, "31", 2024) is None

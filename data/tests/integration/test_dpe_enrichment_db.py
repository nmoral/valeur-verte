"""Test d'intégration : une mutation enrichie DPE est écrite dans data.vente.

Valide la chaîne appariement DPE → écriture (étiquette + période) contre un
vrai PostGIS. Joué en CI ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.jetable import exiger_base_jetable
from valeur_verte_data.db.vente_repository import insert_mutations
from valeur_verte_data.dpe import DpeInfo, attach_dpe
from valeur_verte_data.dvf import Mutation, aggregate_mutations

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def _mutation(id_mutation: str) -> Mutation:
    row = {
        "id_mutation": id_mutation,
        "valeur_fonciere": "200000",
        "code_commune": "31555",
        "code_postal": "31000",
        "surface_reelle_bati": "80",
        "adresse_numero": "12",
        "adresse_nom_voie": "RUE DES FLEURS",
    }
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


def test_mutation_enrichie_dpe_est_ecrite() -> None:
    cli.run(os.environ)
    lookup = {("31000", "12 rue fleurs"): DpeInfo("D", "1948-1974")}
    (enriched,) = attach_dpe([_mutation("dpe-1")], lookup)

    with psycopg.connect(DSN) as conn:
        exiger_base_jetable(os.environ)
        conn.execute("TRUNCATE data.vente")
        insert_mutations(conn, [enriched])
        conn.commit()
        row = conn.execute(
            "SELECT etiquette_dpe, periode_construction FROM data.vente WHERE id_mutation = 'dpe-1'"
        ).fetchone()

    assert row is not None
    assert row[0] == "D"
    assert row[1] == "1948-1974"

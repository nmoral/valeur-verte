"""Test d'intégration : pré-calcul d'une maille et écriture dans coefficient_maille.

Joué en CI contre PostGIS ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os
from dataclasses import replace

import psycopg
import pytest

from valeur_verte_data.batch import compute_maille
from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.coefficient_repository import upsert_coefficient
from valeur_verte_data.dvf import Mutation, aggregate_mutations

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]

_EFFECT = {"D": 0, "F": -25000, "C": 15000}


def _house(dpe: str, bati: int, prix: int) -> Mutation:
    (base,) = aggregate_mutations(
        [
            {
                "id_mutation": f"{dpe}-{bati}",
                "valeur_fonciere": str(prix),
                "code_commune": "31555",
                "surface_reelle_bati": str(bati),
                "latitude": "43.6",
                "longitude": "1.44",
            }
        ],
        millesime=2024,
    )
    return replace(base, etiquette_dpe=dpe, periode_construction="1948-1974")


def test_compute_maille_puis_upsert_est_relu_et_idempotent() -> None:
    cli.run(os.environ)
    pool = [
        _house(dpe, 80 + 5 * i, 1000 * (80 + 5 * i) + effect)
        for dpe, effect in _EFFECT.items()
        for i in range(4)
    ]

    maille = compute_maille("31555", 43.6, 1.44, 2024, pool, seuil=10, min_sample=10)
    assert maille is not None

    with psycopg.connect(DSN) as conn:
        conn.execute("DELETE FROM data.coefficient_maille WHERE code_insee = '31555'")
        upsert_coefficient(conn, maille)
        upsert_coefficient(conn, maille)  # deuxième passe : upsert idempotent
        conn.commit()

        row = conn.execute(
            "SELECT n_transactions, coef_dpe->>'F', rayon_retenu_km "
            "FROM data.coefficient_maille WHERE code_insee = '31555'"
        ).fetchone()
        count_row = conn.execute(
            "SELECT count(*) FROM data.coefficient_maille WHERE code_insee = '31555'"
        ).fetchone()

    assert count_row is not None and count_row[0] == 1
    assert row is not None
    assert row[0] == 12
    assert round(float(row[1])) == -25000
    assert row[2] == 10

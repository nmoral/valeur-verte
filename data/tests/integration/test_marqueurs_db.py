"""Test d'intégration : marqueurs territoriaux (#341, table data.marqueurs).

Valide le SQL réel que les tests unitaires ne voient pas : upsert idempotent par
cible (contrainte NULLS NOT DISTINCT), portée exclusive (CHECK), et contrat
data→app (SELECT hérité de la migration 004).

Joué en CI ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.jetable import exiger_base_jetable
from valeur_verte_data.db.marqueurs_repository import upsert_marqueurs
from valeur_verte_data.marqueurs import Marqueur

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def _climat(departement: str, valeur: str) -> Marqueur:
    return Marqueur(
        code_insee=None,
        code_departement=departement,
        nom="climat",
        valeur=valeur,
        type_marqueur="contextuel",
        source="Zonage climatique RT2012",
    )


def test_ecrit_un_marqueur_departemental_et_communal() -> None:
    cli.run(os.environ)
    exiger_base_jetable(os.environ)
    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.marqueurs")
        n = upsert_marqueurs(
            conn,
            [
                _climat("55", "froid"),
                Marqueur("55039", None, "densite", "rural", "contextuel", "INSEE"),
            ],
        )
        conn.commit()

        assert n == 2
        rows = conn.execute(
            "SELECT valeur FROM data.marqueurs WHERE code_departement = %s AND nom = %s",
            ["55", "climat"],
        ).fetchall()
        assert rows == [("froid",)]


def test_upsert_est_idempotent_par_cible() -> None:
    """NULLS NOT DISTINCT : réécrire (dept, nom) met à jour la valeur, sans doublon —
    la clé nulle de la portée absente compte comme une valeur."""
    cli.run(os.environ)
    exiger_base_jetable(os.environ)
    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.marqueurs")
        upsert_marqueurs(conn, [_climat("55", "froid")])
        upsert_marqueurs(conn, [_climat("55", "doux")])  # recharge : mise à jour
        conn.commit()

        rows = conn.execute(
            "SELECT valeur FROM data.marqueurs WHERE code_departement = %s AND nom = %s",
            ["55", "climat"],
        ).fetchall()
        assert rows == [("doux",)]


def test_une_portee_ambigue_est_refusee_par_la_contrainte() -> None:
    """Le CHECK marqueur_une_seule_portee interdit une ligne sans portée en base
    (le repository la filtre déjà en amont ; ceci valide le garde-fou SQL)."""
    cli.run(os.environ)
    with (
        psycopg.connect(DSN, autocommit=True) as conn,
        pytest.raises(psycopg.errors.CheckViolation),
    ):
        conn.execute(
            "INSERT INTO data.marqueurs "
            "(code_insee, code_departement, nom, valeur, type_marqueur, source) "
            "VALUES (NULL, NULL, 'climat', 'froid', 'contextuel', 'src')"
        )


def test_l_app_peut_lire_les_marqueurs() -> None:
    """Contrat data→app (#341) : le rôle applicatif lit data.marqueurs en SELECT,
    hérité des privilèges par défaut du schéma data (migration 004)."""
    cli.run(os.environ)
    with psycopg.connect(DSN) as conn:
        peut_lire = conn.execute(
            "SELECT has_table_privilege('valeur_verte_app', 'data.marqueurs', 'SELECT')"
        ).fetchone()
        assert peut_lire == (True,)

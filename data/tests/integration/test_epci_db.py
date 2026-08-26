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
from valeur_verte_data.db.epci_repository import (
    communes_de_meme_epci,
    upsert_epci,
    upsert_epci_noms,
)
from valeur_verte_data.db.jetable import exiger_base_jetable
from valeur_verte_data.decoupage import Commune

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


def _commune(code_epci: str, nom_epci: str, code_insee: str = "52001") -> Commune:
    return Commune(
        code_insee=code_insee,
        nom="",
        code_epci=code_epci,
        codes_postaux=(),
        centre_longitude=None,
        centre_latitude=None,
        code_departement="52",
        nom_epci=nom_epci,
    )


def test_le_nom_d_epci_est_persiste_et_relu() -> None:
    """#366 : data.epci existe (migration 020) et rend code_epci → nom, contrat de
    lecture pour le slug d'URL du hub EPCI côté app."""
    cli.run(os.environ)
    exiger_base_jetable(os.environ)
    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.epci")
        upsert_epci_noms(
            conn,
            [
                _commune("200068658", "CA de Chaumont", "52001"),
                _commune("200068658", "CA de Chaumont", "52002"),
            ],
        )
        conn.commit()

        rows = conn.execute(
            "SELECT nom FROM data.epci WHERE code_epci = %s", ["200068658"]
        ).fetchall()
        assert rows == [("CA de Chaumont",)]


def test_upsert_epci_noms_reprend_un_renommage() -> None:
    """Rechargé à chaque ingestion : une fusion/renommage d'EPCI est repris, sans
    doublon de clé."""
    cli.run(os.environ)
    exiger_base_jetable(os.environ)
    with psycopg.connect(DSN) as conn:
        conn.execute("TRUNCATE data.epci")
        upsert_epci_noms(conn, [_commune("200068658", "Ancien nom")])
        upsert_epci_noms(conn, [_commune("200068658", "CA de Chaumont")])
        conn.commit()

        rows = conn.execute(
            "SELECT nom FROM data.epci WHERE code_epci = %s", ["200068658"]
        ).fetchall()
        assert rows == [("CA de Chaumont",)]


def test_l_app_peut_lire_les_noms_d_epci() -> None:
    """Contrat data→app (#366) : le rôle applicatif lit data.epci en SELECT, hérité
    des privilèges par défaut du schéma data (migration 004)."""
    cli.run(os.environ)
    with psycopg.connect(DSN) as conn:
        peut_lire = conn.execute(
            "SELECT has_table_privilege('valeur_verte_app', 'data.epci', 'SELECT')"
        ).fetchone()
        assert peut_lire == (True,)

"""Test d'intégration : pré-calcul d'une maille et écriture dans coefficient_maille.

Joué en CI contre PostGIS ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import math
import os
from dataclasses import replace

import psycopg
import pytest

from valeur_verte_data.batch import MailleCoefficient, compute_maille
from valeur_verte_data.config import CalculConfig
from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.coefficient_repository import archiver_coefficient
from valeur_verte_data.db.publication_repository import publier_maille, publier_millesime
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.dvf import Mutation, aggregate_mutations

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]

# Effets en logarithme : le modèle est log-linéaire (#115).
_EFFECT = {"D": 0.0, "F": -0.20, "C": 0.10}


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
        _house(dpe, 80 + 5 * i, round(3000.0 * (80 + 5 * i) ** 0.9 * math.exp(effect)))
        for dpe, effect in _EFFECT.items()
        for i in range(4)
    ]

    # min_class_sample=1 : ce test porte sur l'écriture et la relecture en base,
    # pas sur le seuil de publication par classe.
    maille = compute_maille(
        "31555",
        43.6,
        1.44,
        2024,
        pool,
        config=replace(CalculConfig(), min_local_sample=10, min_sample=10, min_class_sample=1),
    )
    assert maille is not None

    with psycopg.connect(DSN) as conn:
        conn.execute("DELETE FROM data.coefficient_maille WHERE code_insee = '31555'")
        # REG-2 (#130) : le batch archive, la publication projette. Publier deux fois
        # doit rester idempotent — une commune, une ligne vive.
        archiver_coefficient(conn, maille)
        publier_millesime(conn, 2024, "test@integration")
        publier_millesime(conn, 2024, "test@integration")
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
    assert round(float(row[1]), 2) == -0.20  # effet relatif, non euros
    assert row[2] == 10


def _maille(code_insee: str, alerte: str | None) -> MailleCoefficient:
    return MailleCoefficient(
        code_insee=code_insee,
        millesime=2024,
        mode=CoverageMode.LOCAL,
        rayon_retenu_km=10,
        n_transactions=40,
        r2=0.5,
        erreur_mediane=15.0,
        coef_m2_bati=1500.0,
        coef_m2_terrain=100.0,
        coef_dpe={"D": 0.0, "F": -0.20},
        ic_dpe={},
        alerte=alerte,
    )


def test_la_publication_persiste_l_alerte_de_bord() -> None:
    # #162 : le bandeau (bord INV-3 #121 / dégradé #22) doit être servable, donc
    # écrit en base. Sans alerte, la colonne reste NULL.
    cli.run(os.environ)
    avec = _maille("31901", "Écart de valeur important avec une commune limitrophe (facteur 3.0)")
    sans = _maille("31902", None)

    with psycopg.connect(DSN) as conn:
        conn.execute("DELETE FROM data.coefficient_maille WHERE code_insee IN ('31901', '31902')")
        archiver_coefficient(conn, avec)
        archiver_coefficient(conn, sans)
        publier_millesime(conn, 2024, "test@integration")
        conn.commit()

        lu_avec = conn.execute(
            "SELECT alerte FROM data.coefficient_maille WHERE code_insee = '31901'"
        ).fetchone()
        lu_sans = conn.execute(
            "SELECT alerte FROM data.coefficient_maille WHERE code_insee = '31902'"
        ).fetchone()

    assert lu_avec is not None and lu_avec[0] == avec.alerte
    assert lu_sans is not None and lu_sans[0] is None


def test_debloquer_une_maille_est_refuse_tant_que_le_millesime_n_est_pas_publie() -> None:
    """REG-2 (#130) : la garde vit dans la fonction SQL, pas côté Python.

    L'app invoque ces fonctions directement (elle est en lecture seule sur les
    tables) : une garde côté client serait contournable. Débloquer une maille d'un
    millésime non publié créerait une commune seule en N au milieu d'un parc en N-1.
    """
    cli.run(os.environ)
    maille = replace(_maille("31903", None), millesime=2099)

    with psycopg.connect(DSN) as conn:
        conn.execute("DELETE FROM data.publication_millesime WHERE millesime = 2099")
        archiver_coefficient(conn, maille)
        conn.commit()

        with pytest.raises(psycopg.errors.RaiseException, match="pas publie"):
            publier_maille(conn, "31903", 2099, "test@integration")
        conn.rollback()

        # Une fois le millésime publié, le déblocage est accepté.
        publier_millesime(conn, 2099, "test@integration")
        assert publier_maille(conn, "31903", 2099, "test@integration") == 1
        conn.commit()

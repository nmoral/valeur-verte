"""Archivage du rapport de batch (#131) : SQL/params, hors-ligne.

Le batch archive un rapport par exécution dans ``data.rapport_batch`` (jamais
écrasé). L'ingestion et le calcul écrivent chacun leur phase : les métriques de
l'autre phase restent NULL. Les listes de communes en échec sont sérialisées en
JSONB ; les autres détails (nombres) sont des compteurs.
"""

from __future__ import annotations

import json
from collections.abc import Sequence
from datetime import datetime
from typing import Any

from valeur_verte_data.db.rapport_batch_repository import (
    enregistrer_rapport_calcul,
    enregistrer_rapport_ingestion,
)
from valeur_verte_data.distributions import Distribution
from valeur_verte_data.ecarts import ClasseEcart, EcartMaille
from valeur_verte_data.orchestration import RapportCalcul, RapportIngestion

_DEBUT = datetime(2026, 8, 17, 3, 0, 0)
_FIN = datetime(2026, 8, 17, 4, 30, 0)


class _FakeConnection:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> None:
        self.calls.append((query, params))


def test_enregistre_le_rapport_d_ingestion() -> None:
    conn = _FakeConnection()
    rapport = RapportIngestion(departement="31", millesime=2024)
    rapport.communes_traitees = 580
    rapport.mutations_inserees = 12000
    rapport.ventes_appariees = 9000
    rapport.communes_referencees = 586
    rapport.contours_charges = 585
    rapport.epci_charges = 40
    rapport.sans_donnees = ["31001", "31002"]
    rapport.echecs = ["31999"]

    enregistrer_rapport_ingestion(conn, rapport, debut=_DEBUT, fin=_FIN)

    query, params = conn.calls[0]
    assert params is not None
    assert "INSERT INTO data.rapport_batch" in query
    assert params[0] == "ingestion"
    assert params[1] == "31"
    assert params[2] == 2024
    assert params[3] == _DEBUT
    assert params[4] == _FIN
    # Volumétrie et appariement effectivement portés.
    assert 580 in params  # communes_traitees
    assert 12000 in params  # mutations_inserees
    assert 9000 in params  # ventes_appariees
    assert 586 in params  # communes_referencees
    # sans_donnees est un compteur, pas la liste ; echecs conserve les codes INSEE.
    assert 2 in params  # len(sans_donnees)
    echecs = next(p for p in params if isinstance(p, str) and p.startswith("["))
    assert json.loads(echecs) == ["31999"]


def test_archive_la_distribution_dat4_en_jsonb() -> None:
    # DAT-4 (#127) : les distributions observées sont archivées en JSONB pour que la
    # porte au calcul les relise (plausibilité + répartition DPE vs précédent).
    conn = _FakeConnection()
    rapport = RapportIngestion(departement="31", millesime=2024)
    rapport.communes_traitees = 1
    rapport.distribution = Distribution(200_000.0, 100.0, 120, 1000, {"D": 500, "E": 500})

    enregistrer_rapport_ingestion(conn, rapport, debut=_DEBUT, fin=_FIN)

    query, params = conn.calls[0]
    assert params is not None
    assert "distribution" in query
    distribution = next(
        p for p in params if isinstance(p, str) and p.startswith("{") and "dpe" in p
    )
    charge = json.loads(distribution)
    assert charge["prix_median"] == 200_000.0
    assert charge["terrain_aberrant"] == 120
    assert charge["dpe"] == {"D": 500, "E": 500}


def test_archive_une_distribution_absente_en_null() -> None:
    # Sans distribution calculée (rétro-compatibilité), la colonne reste NULL — pas
    # un JSON « null » qui masquerait l'absence.
    conn = _FakeConnection()
    rapport = RapportIngestion(departement="31", millesime=2024)
    rapport.communes_traitees = 1

    enregistrer_rapport_ingestion(conn, rapport, debut=_DEBUT, fin=_FIN)

    _, params = conn.calls[0]
    assert params is not None
    assert None in params


def test_archive_la_date_de_mutation_la_plus_recente_dat5() -> None:
    # DAT-5 (#128) : la date la plus récente est archivée (source de vérité de la
    # fraîcheur, consommée par l'admin #46/#48).
    from datetime import date

    conn = _FakeConnection()
    rapport = RapportIngestion(departement="31", millesime=2024)
    rapport.communes_traitees = 1
    rapport.mutation_la_plus_recente = date(2024, 11, 20)

    enregistrer_rapport_ingestion(conn, rapport, debut=_DEBUT, fin=_FIN)

    _, params = conn.calls[0]
    assert params is not None
    assert date(2024, 11, 20) in params


def test_enregistre_le_rapport_de_calcul() -> None:
    conn = _FakeConnection()
    rapport = RapportCalcul(departement="31", millesime=2024)
    rapport.communes_calculees = 500
    rapport.refus = ["31010", "31011", "31012"]
    rapport.rejets_invariants = ["31020"]
    rapport.alertes_bord = ["31030", "31031"]
    rapport.inversions_dpe = ["31040"]
    rapport.echecs = ["31099"]
    rapport.ecarts.ajouter("31050", EcartMaille(ClasseEcart.BLOCAGE, 0.42, "DPE F"))
    rapport.ecarts.ajouter("31051", EcartMaille(ClasseEcart.ALERTE, 0.15, "DPE E"))
    rapport.ecarts.ajouter("31052", EcartMaille(ClasseEcart.ALERTE, 0.12, "DPE C"))

    enregistrer_rapport_calcul(conn, rapport, debut=_DEBUT, fin=_FIN)

    query, params = conn.calls[0]
    assert params is not None
    assert "INSERT INTO data.rapport_batch" in query
    assert params[0] == "calcul"
    assert params[1] == "31"
    assert params[2] == 2024
    assert 500 in params  # communes_calculees
    assert 3 in params  # len(refus)
    assert 2 in params  # len(alertes_bord) OU len(ecarts_alertes)
    # Les compteurs d'écarts REG-1 dérivent des classes, pas des longueurs brutes.
    ecarts_blocages = _valeur_apres(query, params, "ecarts_blocages")
    ecarts_alertes = _valeur_apres(query, params, "ecarts_alertes")
    assert ecarts_blocages == 1
    assert ecarts_alertes == 2


def _valeur_apres(query: str, params: Sequence[Any], colonne: str) -> Any:
    """Retrouve la valeur d'une colonne par sa position dans la liste INSERT.

    Rend le test lisible sans figer l'ordre complet des colonnes : on lit la
    position de ``colonne`` dans l'entête ``INSERT (...)`` et on prend le paramètre
    de même rang.
    """
    entete = query[query.index("(") + 1 : query.index(")")]
    colonnes = [c.strip() for c in entete.split(",")]
    return params[colonnes.index(colonne)]

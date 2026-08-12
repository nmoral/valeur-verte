"""Commande ``load-contours`` : charge les contours communaux d'un département (#121).

Les contours sont normalement chargés en fin d'ingestion, mais une commande
dédiée permet de les (re)charger **sans re-télécharger tout DVF** — utile après
le déploiement d'INV-3 sur des départements déjà ingérés. La contiguïté est
dérivée (``ST_Touches``) dans la foulée.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

import pytest

from valeur_verte_data import cli, orchestration
from valeur_verte_data.decoupage import CommuneContour

_CONTOURS = [
    CommuneContour(
        "31555", "31", '{"coordinates":[[[1,43],[1,44],[2,44],[1,43]]],"type":"Polygon"}'
    ),
    CommuneContour(
        "31149", "31", '{"coordinates":[[[1,43],[1,44],[0,44],[1,43]]],"type":"Polygon"}'
    ),
]


class _FausseConnexion:
    def __init__(self) -> None:
        self.commits = 0
        self.rollbacks = 0

    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        return None

    def commit(self) -> None:
        self.commits += 1

    def rollback(self) -> None:
        self.rollbacks += 1


def test_load_contours_departement_stocke_et_derive(monkeypatch: pytest.MonkeyPatch) -> None:
    conn = _FausseConnexion()
    stockes: list[str] = []
    derivations: list[str] = []
    monkeypatch.setattr(orchestration, "fetch_contours_by_departement", lambda f, d: _CONTOURS)
    monkeypatch.setattr(
        orchestration,
        "upsert_contour",
        lambda conn, code_insee, code_departement, contour: stockes.append(code_insee),
    )
    monkeypatch.setattr(orchestration, "derive_limitrophes", lambda conn: derivations.append("x"))

    n = orchestration.load_contours_departement(object(), conn, "31")

    assert n == 2
    assert stockes == ["31555", "31149"]
    assert derivations == ["x"]
    assert conn.commits == 1


def test_load_contours_departement_sans_contour_ne_derive_pas(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Aucun contour (dept inconnu de geo.api) : rien à dériver, rien à committer.
    conn = _FausseConnexion()
    derivations: list[str] = []
    monkeypatch.setattr(orchestration, "fetch_contours_by_departement", lambda f, d: [])
    monkeypatch.setattr(orchestration, "derive_limitrophes", lambda conn: derivations.append("x"))

    n = orchestration.load_contours_departement(object(), conn, "31")

    assert n == 0
    assert derivations == []
    assert conn.commits == 0


def test_la_commande_load_contours_exige_departement() -> None:
    # Sans --departement, on ne sait pas quoi charger : échec explicite.
    with pytest.raises(SystemExit):
        cli.main(["load-contours"])


def test_la_commande_load_contours_ne_prend_pas_de_millesime() -> None:
    # Les contours sont indépendants du millésime : --millesime n'existe pas ici.
    with pytest.raises(SystemExit):
        cli.main(["load-contours", "--departement", "31", "--millesime", "2024"])

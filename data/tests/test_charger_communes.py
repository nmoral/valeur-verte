"""Chargement du référentiel des noms de communes d'un département (#220).

``data.commune`` est normalement peuplé en fin d'ingestion (``upsert_communes``),
mais une commande dédiée permet de le (re)charger **sans re-télécharger tout DVF** —
utile sur des départements ingérés avant #220, ou après une fusion de communes.
Idempotent (upsert). Miroir de ``load-epci`` / ``load-contours``.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

import pytest

from valeur_verte_data import cli, orchestration
from valeur_verte_data.decoupage import Commune


def _commune(code_insee: str, nom: str) -> Commune:
    return Commune(
        code_insee=code_insee,
        nom=nom,
        code_epci="200068658",
        codes_postaux=(),
        centre_longitude=5.0,
        centre_latitude=48.0,
        code_departement="52",
    )


class _FausseConnexion:
    def __init__(self) -> None:
        self.commits = 0

    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        return None

    def commit(self) -> None:
        self.commits += 1

    def rollback(self) -> None:  # pragma: no cover - présent pour le protocole
        pass


def test_charge_le_referentiel_des_communes(monkeypatch: pytest.MonkeyPatch) -> None:
    """Les communes du département sont enregistrées, en une seule transaction."""
    conn = _FausseConnexion()
    communes = [_commune("52001", "Chaumont"), _commune("52002", "Langres")]
    monkeypatch.setattr(orchestration, "fetch_communes_by_departement", lambda f, d: communes)
    ecrites: list[str] = []

    def _upsert(conn: Any, communes: Sequence[Commune]) -> int:
        ecrites.extend(c.code_insee for c in communes)
        return len(ecrites)

    monkeypatch.setattr(orchestration, "upsert_communes", _upsert)

    n = orchestration.charger_communes_departement(object(), conn, "52")

    assert n == 2
    assert ecrites == ["52001", "52002"]
    assert conn.commits == 1


def test_sans_commune_ne_committe_pas(monkeypatch: pytest.MonkeyPatch) -> None:
    """Département inconnu de geo.api : rien à enregistrer, aucune transaction."""
    conn = _FausseConnexion()
    monkeypatch.setattr(orchestration, "fetch_communes_by_departement", lambda f, d: [])
    monkeypatch.setattr(orchestration, "upsert_communes", lambda conn, communes: 0)

    n = orchestration.charger_communes_departement(object(), conn, "52")

    assert n == 0
    assert conn.commits == 0


def test_la_commande_load_communes_charge_le_departement(monkeypatch: pytest.MonkeyPatch) -> None:
    """load-communes délègue à charger_communes_departement pour le département donné."""
    appels: list[str] = []
    conn = _FausseConnexion()

    class _Ctx:
        def __enter__(self) -> _FausseConnexion:
            return conn

        def __exit__(self, *args: object) -> bool:
            return False

    monkeypatch.setenv("DATABASE_URL", "postgresql://hote/base")
    monkeypatch.setattr("psycopg.connect", lambda *a, **k: _Ctx())
    monkeypatch.setattr(cli, "charger_communes_departement", lambda f, c, d: appels.append(d) or 5)

    code = cli.main(["load-communes", "--departement", "52"])

    assert code == 0
    assert appels == ["52"]


def test_la_commande_load_communes_ne_prend_pas_de_millesime() -> None:
    """Le référentiel est indépendant du millésime : --millesime n'existe pas ici."""
    with pytest.raises(SystemExit):
        cli.main(["load-communes", "--departement", "52", "--millesime", "2024"])

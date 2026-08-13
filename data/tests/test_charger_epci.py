"""Chargement de l'appartenance EPCI d'un département (#169).

Comme les contours, l'EPCI des communes se charge depuis geo.api.gouv.fr
(``fetch_communes_by_departement`` porte ``codeEpci``). Utile après coup sur des
départements déjà ingérés, sans re-télécharger tout DVF.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

import pytest

from valeur_verte_data import orchestration
from valeur_verte_data.decoupage import Commune


def _commune(code_insee: str, code_epci: str | None) -> Commune:
    return Commune(
        code_insee=code_insee,
        nom="",
        code_epci=code_epci,
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


def test_charge_l_epci_des_communes(monkeypatch: pytest.MonkeyPatch) -> None:
    """Chaque commune porteuse d'un EPCI est enregistrée ; une seule transaction."""
    conn = _FausseConnexion()
    enregistres: list[tuple[str, str]] = []
    communes = [_commune("52001", "200068658"), _commune("52002", "200068658")]
    monkeypatch.setattr(orchestration, "fetch_communes_by_departement", lambda f, d: communes)
    monkeypatch.setattr(
        orchestration,
        "upsert_epci",
        lambda conn, code_insee, code_epci: enregistres.append((code_insee, code_epci)),
    )

    n = orchestration.charger_epci_departement(object(), conn, "52")

    assert n == 2
    assert enregistres == [("52001", "200068658"), ("52002", "200068658")]
    assert conn.commits == 1


def test_ignore_les_communes_sans_epci(monkeypatch: pytest.MonkeyPatch) -> None:
    """Une commune sans EPCI (rare) est ignorée, pas enregistrée."""
    conn = _FausseConnexion()
    enregistres: list[str] = []
    communes = [_commune("52001", "200068658"), _commune("52333", None)]
    monkeypatch.setattr(orchestration, "fetch_communes_by_departement", lambda f, d: communes)
    monkeypatch.setattr(
        orchestration,
        "upsert_epci",
        lambda conn, code_insee, code_epci: enregistres.append(code_insee),
    )

    n = orchestration.charger_epci_departement(object(), conn, "52")

    assert n == 1
    assert enregistres == ["52001"]


def test_sans_commune_ne_committe_pas(monkeypatch: pytest.MonkeyPatch) -> None:
    """Département inconnu de geo.api : rien à enregistrer, aucune transaction."""
    conn = _FausseConnexion()
    monkeypatch.setattr(orchestration, "fetch_communes_by_departement", lambda f, d: [])

    n = orchestration.charger_epci_departement(object(), conn, "52")

    assert n == 0
    assert conn.commits == 0

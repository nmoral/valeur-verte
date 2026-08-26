"""Chargement des marqueurs contextuels d'un département (#341, Lot 1).

Référentiels figés **embarqués** (climat…) : aucun appel réseau, contrairement à
``load-communes``/``load-epci``. La commande écrit dans ``data.marqueurs`` de façon
idempotente (upsert), en une seule transaction committée s'il y a eu écriture.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

import pytest

from valeur_verte_data import cli, orchestration


class _FausseConnexion:
    def __init__(self) -> None:
        self.commits = 0
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        self.calls.append((query, params))
        return None

    def commit(self) -> None:
        self.commits += 1

    def rollback(self) -> None:  # pragma: no cover - présent pour le protocole
        pass


def test_charge_le_marqueur_climat_du_departement() -> None:
    """Le climat (marqueur contextuel départemental) est écrit pour le département,
    en une seule transaction."""
    conn = _FausseConnexion()

    n = orchestration.charger_marqueurs_departement(conn, "55")

    assert n == 1
    assert conn.commits == 1
    _, params = conn.calls[0]
    assert params is not None
    assert list(params)[:5] == [None, "55", "climat", "froid", "contextuel"]


def test_charge_aussi_les_marqueurs_littoral_communaux() -> None:
    """Un département côtier écrit, en plus du climat départemental, un marqueur
    littoral=oui par commune de la loi Littoral — de quoi varier d'une commune à
    l'autre (anti-duplicate #341)."""
    conn = _FausseConnexion()

    orchestration.charger_marqueurs_departement(conn, "29")  # Finistère

    ecrits = [tuple(params) for _, params in conn.calls if params is not None]
    # climat départemental (Finistère = H2 tempéré).
    assert (None, "29", "climat", "tempere", "contextuel") == ecrits[0][:5]
    # littoral communal : Brest (29019) figure parmi les communes écrites.
    littoral = [e for e in ecrits if e[2] == "littoral"]
    assert any(e[0] == "29019" and e[3] == "oui" for e in littoral)
    assert conn.commits == 1


def test_un_departement_hors_zonage_ne_committe_pas() -> None:
    """Un département sans référentiel contextuel (DOM, hors zonage climatique) :
    rien à écrire, aucune transaction."""
    conn = _FausseConnexion()

    n = orchestration.charger_marqueurs_departement(conn, "971")

    assert n == 0
    assert conn.commits == 0
    assert conn.calls == []


def test_la_commande_load_marqueurs_charge_le_departement(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """load-marqueurs délègue à charger_marqueurs_departement pour le département donné."""
    appels: list[str] = []
    conn = _FausseConnexion()

    class _Ctx:
        def __enter__(self) -> _FausseConnexion:
            return conn

        def __exit__(self, *args: object) -> bool:
            return False

    monkeypatch.setenv("DATABASE_URL", "postgresql://hote/base")
    monkeypatch.setattr("psycopg.connect", lambda *a, **k: _Ctx())
    monkeypatch.setattr(cli, "charger_marqueurs_departement", lambda c, d: appels.append(d) or 3)

    code = cli.main(["load-marqueurs", "--departement", "55"])

    assert code == 0
    assert appels == ["55"]


def test_la_commande_load_marqueurs_ne_prend_pas_de_millesime() -> None:
    """Les référentiels contextuels sont indépendants du millésime : pas de --millesime."""
    with pytest.raises(SystemExit):
        cli.main(["load-marqueurs", "--departement", "55", "--millesime", "2024"])

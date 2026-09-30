"""Chargement des marqueurs **mesurés** d'un département (#341, Lot 2).

Contrairement aux marqueurs contextuels (Lot 1, référentiels figés embarqués), les
marqueurs mesurés se dérivent de la table vive ``coefficient_maille`` : la commande
lit les coefficients **publiés** du millésime au niveau national (pour les tertiles),
puis n'écrit que les communes du département demandé. Une seule transaction,
committée s'il y a eu écriture.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

import pytest

from valeur_verte_data import cli, orchestration


def _coef(cC: float) -> dict[str, float]:
    """Coefficients DPE d'une maille de test : ``cC`` pilote gain E→C et amplitude A↔G."""
    return {"D": 0.0, "E": 0.0, "C": cC, "A": cC, "G": -cC}


# Distribution nationale (6 communes, 3 départements) : (code_insee, coef_dpe, n, rayon).
_ROWS: list[tuple[Any, ...]] = [
    ("31001", _coef(0.10), 120, 15),  # gain 11 → faible ; solide
    ("31002", _coef(0.30), 60, 15),  # gain 35 → moyen ; correct
    ("31003", _coef(0.55), 30, 10),  # gain 73 → fort ; fragile
    ("75056", _coef(0.45), 90, 18),  # dept 75, hors périmètre écrit
    ("01001", _coef(0.05), 200, 5),  # dept 01
    ("01002", _coef(0.60), 200, 5),  # dept 01
]


class _CurseurRows:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


class _FausseConnexion:
    """Répond aux SELECT (coefficients publiés) et collecte les INSERT (marqueurs)."""

    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows
        self.commits = 0
        self.ecrits: list[Sequence[Any]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _CurseurRows:
        if query.lstrip().upper().startswith("SELECT"):
            return _CurseurRows(self._rows)
        assert params is not None
        self.ecrits.append(params)
        return _CurseurRows([])

    def commit(self) -> None:
        self.commits += 1

    def rollback(self) -> None:  # pragma: no cover - présent pour le protocole
        pass


def test_charge_les_marqueurs_mesures_du_departement() -> None:
    conn = _FausseConnexion(_ROWS)

    n = orchestration.charger_marqueurs_mesures_departement(conn, "31", 2025)

    ecrits = [tuple(p) for p in conn.ecrits]
    # N'écrit que les communes du département 31 (75/01 servent aux tertiles nationaux).
    assert {e[0] for e in ecrits} == {"31001", "31002", "31003"}
    # Tous de type mesure, portée communale (code_departement None).
    assert all(e[1] is None and e[4] == "mesure" for e in ecrits)
    # Le levier de 31003 est fort au regard de la distribution nationale.
    levier = [e for e in ecrits if e[2] == "levier_renovation"]
    assert any(e[0] == "31003" and e[3] == "fort" for e in levier)
    assert any(e[0] == "31001" and e[3] == "faible" for e in levier)
    assert n == len(ecrits)
    assert conn.commits == 1


def test_un_departement_sans_maille_publiee_ne_committe_pas() -> None:
    """Aucun coefficient publié pour ce département : rien à écrire, aucune transaction."""
    conn = _FausseConnexion(_ROWS)

    n = orchestration.charger_marqueurs_mesures_departement(conn, "99", 2025)

    assert n == 0
    assert conn.ecrits == []
    assert conn.commits == 0


def test_la_commande_load_marqueurs_mesures_delegue(monkeypatch: pytest.MonkeyPatch) -> None:
    """load-marqueurs-mesures délègue à charger_marqueurs_mesures_departement (dept + millésime)."""
    appels: list[tuple[str, int]] = []

    class _Ctx:
        def __enter__(self) -> object:
            return object()

        def __exit__(self, *args: object) -> bool:
            return False

    monkeypatch.setenv("DATABASE_URL", "postgresql://hote/base")
    monkeypatch.setattr("psycopg.connect", lambda *a, **k: _Ctx())
    monkeypatch.setattr(
        cli,
        "charger_marqueurs_mesures_departement",
        lambda c, d, m: appels.append((d, m)) or 5,
    )

    code = cli.main(["load-marqueurs-mesures", "--departement", "31", "--millesime", "2025"])

    assert code == 0
    assert appels == [("31", 2025)]


def test_la_commande_load_marqueurs_mesures_exige_le_millesime() -> None:
    """Les marqueurs mesurés dépendent du millésime servi : --millesime est requis."""
    with pytest.raises(SystemExit):
        cli.main(["load-marqueurs-mesures", "--departement", "31"])

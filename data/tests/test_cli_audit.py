"""CLI d'audit de validité de ``data.vente`` (read-only), testée hors-ligne.

L'audit **constate** : il ouvre une connexion, scanne la base et rend un rapport ;
il n'écrit rien. Ces tests câblent la commande sans base réelle (``auditer``
mocké, ``psycopg.connect`` remplacé) et vérifient l'analyse d'arguments, l'affichage
et surtout le **code de sortie** (≠ 0 si anomalie) — le contrat d'un usage en cron.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

import pytest

from valeur_verte_data import cli
from valeur_verte_data.controle_qualite import (
    Anomalie,
    Severite,
    construire_rapport,
)


class _ConnexionContexte:
    """Fausse connexion psycopg : gestionnaire de contexte inerte (audit read-only)."""

    def __enter__(self) -> _ConnexionContexte:
        return self

    def __exit__(self, *_: Any) -> bool:
        return False

    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        return None

    def commit(self) -> None:
        return None


def _cabler(monkeypatch: pytest.MonkeyPatch, rapport: Any) -> dict[str, Any]:
    """Câble une CLI d'audit hors-ligne rendant ``rapport`` ; capture les kwargs d'appel."""
    import psycopg

    monkeypatch.setenv("DATABASE_URL", "postgresql://x")
    monkeypatch.setattr(psycopg, "connect", lambda *a, **k: _ConnexionContexte())
    captures: dict[str, Any] = {}

    def _auditer(conn: Any, **kwargs: Any) -> Any:
        captures.update(kwargs)
        return rapport

    monkeypatch.setattr(cli, "auditer", _auditer, raising=False)
    return captures


def _rapport(*anomalies: Anomalie, lignes: int = 1) -> Any:
    return construire_rapport(anomalies, lignes_controlees=lignes)


def _anomalie(code: str, severite: Severite = Severite.ANOMALIE) -> Anomalie:
    return Anomalie(code=code, severite=severite, message=code, reference="vente#1")


def test_audit_exige_un_perimetre() -> None:
    """Ni --departement ni --tous : refus (un audit sans périmètre est ambigu)."""
    with pytest.raises(SystemExit):
        cli.main(["audit"])


def test_audit_refuse_departement_et_tous_ensemble() -> None:
    with pytest.raises(SystemExit):
        cli.main(["audit", "--departement", "31", "--tous"])


def test_audit_sain_sort_en_zero(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    _cabler(monkeypatch, _rapport())
    code = cli.main(["audit", "--departement", "31"])
    assert code == 0
    assert "0 anomalie" in capsys.readouterr().out


def test_audit_avec_anomalie_sort_en_erreur(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    """Une anomalie fait échouer le code de sortie (utilisable en cron/CI)."""
    _cabler(monkeypatch, _rapport(_anomalie("prix_non_positif")))
    code = cli.main(["audit", "--departement", "31"])
    assert code == 1
    out = capsys.readouterr().out
    assert "prix_non_positif" in out


def test_audit_alerte_seule_ne_fait_pas_echouer(
    monkeypatch: pytest.MonkeyPatch, capsys: pytest.CaptureFixture[str]
) -> None:
    """Une ALERTE est affichée mais ne change pas le code de sortie (non bloquante)."""
    _cabler(monkeypatch, _rapport(_anomalie("hors_bbox", Severite.ALERTE)))
    code = cli.main(["audit", "--departement", "31"])
    assert code == 0
    assert "hors_bbox" in capsys.readouterr().out


def test_audit_departement_transmet_le_filtre(monkeypatch: pytest.MonkeyPatch) -> None:
    captures = _cabler(monkeypatch, _rapport())
    cli.main(["audit", "--departement", "31", "--millesime", "2024"])
    assert captures["departement"] == "31"
    assert captures["millesime"] == 2024


def test_audit_tous_scanne_sans_filtre_departement(monkeypatch: pytest.MonkeyPatch) -> None:
    """--tous ⇒ pas de filtre département : les doublons inter-départements sont vus."""
    captures = _cabler(monkeypatch, _rapport())
    cli.main(["audit", "--tous"])
    assert captures["departement"] is None


def test_audit_ecrit_le_csv_quand_demande(monkeypatch: pytest.MonkeyPatch, tmp_path: Any) -> None:
    _cabler(monkeypatch, _rapport(_anomalie("prix_non_positif")))
    chemin = tmp_path / "anomalies.csv"
    cli.main(["audit", "--departement", "31", "--rapport", str(chemin)])
    contenu = chemin.read_text(encoding="utf-8")
    assert "severite" in contenu  # en-tête
    assert "prix_non_positif" in contenu

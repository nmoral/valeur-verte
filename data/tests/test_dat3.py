"""DAT-3 (#126) — la porte : lire les taux archivés puis décider (sans base).

``porte_dat3`` relit le taux d'appariement du millésime (déjà archivé par #131) et
celui du précédent, puis délègue à ``evaluer_appariement``. Sans taux courant (pas
d'ingestion, ou 0 mutation), il n'y a **rien à barrer** : on rend ``None`` sans même
lire le précédent. Les lecteurs SQL sont mockés ici (validés en intégration) ; on
teste la logique de la porte, mutée sans base.
"""

from __future__ import annotations

import pytest

from valeur_verte_data import dat3

_SEUILS = {"seuil_min": 0.60, "chute_max": 0.10}


def test_taux_sain_rend_un_verdict_non_bloquant(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat3, "lire_taux_appariement", lambda *a: 0.75)
    monkeypatch.setattr(dat3, "lire_taux_appariement_precedent", lambda *a: 0.78)
    verdict = dat3.porte_dat3(object(), "31", 2025, **_SEUILS)
    assert verdict is not None
    assert verdict.bloque is False


def test_taux_sous_le_seuil_bloque(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat3, "lire_taux_appariement", lambda *a: 0.55)
    monkeypatch.setattr(dat3, "lire_taux_appariement_precedent", lambda *a: None)
    verdict = dat3.porte_dat3(object(), "31", 2025, **_SEUILS)
    assert verdict is not None
    assert verdict.bloque is True


def test_chute_vs_precedent_bloque(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat3, "lire_taux_appariement", lambda *a: 0.68)
    monkeypatch.setattr(dat3, "lire_taux_appariement_precedent", lambda *a: 0.80)
    verdict = dat3.porte_dat3(object(), "31", 2025, **_SEUILS)
    assert verdict is not None
    assert verdict.bloque is True


def test_le_departement_du_verdict_est_celui_demande(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat3, "lire_taux_appariement", lambda *a: 0.55)
    monkeypatch.setattr(dat3, "lire_taux_appariement_precedent", lambda *a: None)
    assert dat3.porte_dat3(object(), "09", 2025, **_SEUILS).departement == "09"  # type: ignore[union-attr]


def test_porte_transmet_conn_departement_et_millesime_aux_lecteurs(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # La porte doit interroger les lecteurs avec la bonne connexion, le bon
    # département et le bon millésime — sinon elle jugerait une autre maille.
    conn = object()
    recu: dict[str, tuple[object, str, int]] = {}

    def _courant(c: object, dept: str, mil: int) -> float:
        recu["courant"] = (c, dept, mil)
        return 0.55

    def _precedent(c: object, dept: str, mil: int) -> None:
        recu["precedent"] = (c, dept, mil)
        return None

    monkeypatch.setattr(dat3, "lire_taux_appariement", _courant)
    monkeypatch.setattr(dat3, "lire_taux_appariement_precedent", _precedent)

    dat3.porte_dat3(conn, "31", 2025, **_SEUILS)

    assert recu["courant"] == (conn, "31", 2025)
    assert recu["precedent"] == (conn, "31", 2025)


def test_taux_absent_rend_none_sans_lire_le_precedent(monkeypatch: pytest.MonkeyPatch) -> None:
    # Pas de taux courant (aucune ingestion ou 0 mutation) → rien à barrer, et on ne
    # lit même pas le précédent (court-circuit).
    monkeypatch.setattr(dat3, "lire_taux_appariement", lambda *a: None)
    appels: list[int] = []
    monkeypatch.setattr(dat3, "lire_taux_appariement_precedent", lambda *a: appels.append(1))
    assert dat3.porte_dat3(object(), "31", 2025, **_SEUILS) is None
    assert appels == []

"""DAT-4 (#127) — la porte : relire les distributions archivées puis décider (sans base).

Comme DAT-3, ingestion et calcul sont deux commandes séparées : la porte s'évalue au
**calcul**, en relisant les distributions archivées à l'ingestion (#131). Sans
distribution courante (ingestion antérieure à DAT-4, ou département sans vente), il n'y
a **rien à juger** : on rend ``None`` sans lire le précédent. Les lecteurs SQL sont
mockés ici (validés en intégration) ; on teste la logique de la porte, mutée sans base.
"""

from __future__ import annotations

import pytest

from valeur_verte_data import dat4
from valeur_verte_data.distributions import Distribution

_BORNES = {
    "prix_median_min": 40_000.0,
    "prix_median_max": 1_000_000.0,
    "bati_median_min": 60.0,
    "bati_median_max": 160.0,
    "terrain_aberrant_max": 0.50,
    "dpe_ecart_max": 0.15,
}


def _plausible() -> Distribution:
    return Distribution(200_000.0, 100.0, 0, 1000, {"D": 500, "E": 500})


def test_distribution_plausible_ne_bloque_pas(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat4, "lire_distribution", lambda *a: _plausible())
    monkeypatch.setattr(dat4, "lire_distribution_dpe_precedent", lambda *a: None)
    verdict = dat4.porte_dat4(object(), "31", 2025, **_BORNES)
    assert verdict is not None
    assert verdict.bloque is False


def test_plausibilite_hors_fourchette_bloque(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(
        dat4, "lire_distribution", lambda *a: Distribution(1_500.0, 100.0, 0, 1000, {"D": 1000})
    )
    monkeypatch.setattr(dat4, "lire_distribution_dpe_precedent", lambda *a: None)
    verdict = dat4.porte_dat4(object(), "31", 2025, **_BORNES)
    assert verdict is not None
    assert verdict.bloque is True


def test_deplacement_dpe_alerte_sans_bloquer(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(
        dat4,
        "lire_distribution",
        lambda *a: Distribution(200_000.0, 100.0, 0, 1000, {"D": 800, "E": 200}),
    )
    monkeypatch.setattr(dat4, "lire_distribution_dpe_precedent", lambda *a: {"D": 200, "E": 800})
    verdict = dat4.porte_dat4(object(), "31", 2025, **_BORNES)
    assert verdict is not None
    assert verdict.alerte is True
    assert verdict.bloque is False


def test_distribution_absente_rend_none_sans_lire_le_precedent(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # Ingestion antérieure à DAT-4 (pas de distribution archivée) → rien à juger, et on
    # ne lit même pas le précédent.
    monkeypatch.setattr(dat4, "lire_distribution", lambda *a: None)
    appels: list[int] = []
    monkeypatch.setattr(
        dat4, "lire_distribution_dpe_precedent", lambda *a: appels.append(1)
    )
    assert dat4.porte_dat4(object(), "31", 2025, **_BORNES) is None
    assert appels == []


def test_departement_sans_vente_rend_none(monkeypatch: pytest.MonkeyPatch) -> None:
    # Distribution archivée mais effectif nul : aucune médiane, rien à juger.
    monkeypatch.setattr(
        dat4, "lire_distribution", lambda *a: Distribution(None, None, 0, 0, {})
    )
    assert dat4.porte_dat4(object(), "31", 2025, **_BORNES) is None


def test_porte_transmet_conn_departement_et_millesime_aux_lecteurs(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    conn = object()
    recu: dict[str, tuple[object, str, int]] = {}

    def _courant(c: object, dept: str, mil: int) -> Distribution:
        recu["courant"] = (c, dept, mil)
        return _plausible()

    def _precedent(c: object, dept: str, mil: int) -> None:
        recu["precedent"] = (c, dept, mil)
        return None

    monkeypatch.setattr(dat4, "lire_distribution", _courant)
    monkeypatch.setattr(dat4, "lire_distribution_dpe_precedent", _precedent)

    dat4.porte_dat4(conn, "31", 2025, **_BORNES)

    assert recu["courant"] == (conn, "31", 2025)
    assert recu["precedent"] == (conn, "31", 2025)


def test_le_departement_du_verdict_est_celui_demande(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat4, "lire_distribution", lambda *a: _plausible())
    monkeypatch.setattr(dat4, "lire_distribution_dpe_precedent", lambda *a: None)
    verdict = dat4.porte_dat4(object(), "09", 2025, **_BORNES)
    assert verdict is not None
    assert verdict.departement == "09"

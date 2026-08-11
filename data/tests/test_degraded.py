"""Tests de la décision de mode (local / départemental / refus) — §5.3."""

from __future__ import annotations

from valeur_verte_data.degraded import (
    DEGRADED_ALERT,
    MIN_LOCAL_SAMPLE,
    REFUS_MESSAGE,
    CoverageMode,
    decide_mode,
)


def test_mode_local_si_echantillon_local_suffisant() -> None:
    decision = decide_mode(n_local=80, n_departement=1000)

    assert decision.mode is CoverageMode.LOCAL
    assert decision.alerte is None


def test_mode_local_au_seuil_exact() -> None:
    assert decide_mode(n_local=MIN_LOCAL_SAMPLE, n_departement=1000).mode is CoverageMode.LOCAL


def test_mode_departemental_sous_le_seuil_local() -> None:
    decision = decide_mode(n_local=29, n_departement=500)

    assert decision.mode is CoverageMode.DEPARTEMENTAL
    assert decision.alerte == DEGRADED_ALERT


def test_mode_departemental_au_seuil_exact_du_departement() -> None:
    decision = decide_mode(n_local=10, n_departement=MIN_LOCAL_SAMPLE)

    assert decision.mode is CoverageMode.DEPARTEMENTAL


def test_mode_refus_si_departement_aussi_insuffisant() -> None:
    decision = decide_mode(n_local=10, n_departement=29)

    assert decision.mode is CoverageMode.REFUS
    assert decision.alerte == REFUS_MESSAGE


def test_seuil_configurable() -> None:
    decision = decide_mode(n_local=50, n_departement=1000, seuil=80)

    assert decision.mode is CoverageMode.DEPARTEMENTAL

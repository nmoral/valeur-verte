"""Tests de la décision de mode (local / départemental / refus) — §5.3."""

from __future__ import annotations

from valeur_verte_data.degraded import (
    DEGRADED_ALERT,
    MIN_LOCAL_SAMPLE,
    REFUS_MESSAGE,
    CoverageMode,
    PalierFiabilite,
    decide_mode,
    palier_erreur,
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


# --- Palier de fiabilité par erreur médiane (#169) ---


def test_palier_standard_sous_le_seuil() -> None:
    """Erreur sous le seuil standard : tier standard."""
    assert palier_erreur(15.0, seuil_standard=20.0, seuil_degrade=30.0) is PalierFiabilite.STANDARD


def test_palier_degrade_entre_les_seuils() -> None:
    """Erreur entre standard et dégradé : tier dégradé (bandeau)."""
    assert palier_erreur(25.0, seuil_standard=20.0, seuil_degrade=30.0) is PalierFiabilite.DEGRADE


def test_palier_refuse_au_dela_du_degrade() -> None:
    """Erreur au-delà du seuil dégradé : refusée."""
    assert palier_erreur(35.0, seuil_standard=20.0, seuil_degrade=30.0) is PalierFiabilite.REFUSE


def test_palier_bornes_exclusives() -> None:
    """Bornes exclusives, cohérentes avec INV-4 (err >= seuil → hors tier)."""
    assert palier_erreur(20.0, seuil_standard=20.0, seuil_degrade=30.0) is PalierFiabilite.DEGRADE
    assert palier_erreur(30.0, seuil_standard=20.0, seuil_degrade=30.0) is PalierFiabilite.REFUSE


def test_palier_erreur_indisponible_refuse() -> None:
    """Erreur non calculable (validation croisée impossible) : refusée."""
    assert palier_erreur(None, seuil_standard=20.0, seuil_degrade=30.0) is PalierFiabilite.REFUSE

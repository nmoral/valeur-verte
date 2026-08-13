"""INV-4 (#122) — la qualité du modèle comme critère de publication.

Fin de calcul, par maille : une maille dont l'erreur médiane, le R² ou le n
ne tiennent pas les seuils **n'est pas publiée** (elle remonte en alerte, #22
prend le relais). Les seuils viennent d'une **source unique** configurable :
erreur médiane < 20 %, R² > 0,30, n ≥ 30 (= ``min_local_sample``, seuil #22).
"""

from __future__ import annotations

from dataclasses import replace

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.config import CalculConfig
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.invariants import VerdictPublication, evaluer_invariants_publication


def _maille(
    *, erreur_mediane: float | None = 15.0, r2: float = 0.5, n: int = 40
) -> MailleCoefficient:
    return MailleCoefficient(
        code_insee="31555",
        millesime=2024,
        mode=CoverageMode.LOCAL,
        rayon_retenu_km=10,
        n_transactions=n,
        r2=r2,
        erreur_mediane=erreur_mediane,
        coef_m2_bati=1500.0,
        coef_m2_terrain=100.0,
        coef_dpe={"D": 0.0, "F": -0.20},
        ic_dpe={},
        alerte=None,
    )


def test_une_maille_saine_est_publiable() -> None:
    verdict = evaluer_invariants_publication(_maille(), CalculConfig())

    assert isinstance(verdict, VerdictPublication)
    assert verdict.publiable is True
    assert verdict.raisons == ()


def test_erreur_au_seuil_standard_reste_publiable() -> None:
    # #169 : le gate de publication est désormais le **plafond dégradé** (30 %),
    # pas le seuil standard (20 %). À 20 % pile, la maille est publiée en tier
    # dégradé (son palier porte le bandeau) au lieu du refus sec.
    verdict = evaluer_invariants_publication(_maille(erreur_mediane=20.0), CalculConfig())

    assert verdict.publiable is True
    assert verdict.raisons == ()


def test_erreur_dans_le_tier_degrade_est_publiable() -> None:
    # #169 : entre seuil standard et plafond dégradé (20-30 %), la maille passe.
    # C'est le levier qui ouvre le rural (1-3 % → 30-68 % de communes publiées).
    verdict = evaluer_invariants_publication(_maille(erreur_mediane=25.0), CalculConfig())

    assert verdict.publiable is True
    assert verdict.raisons == ()


def test_erreur_au_plafond_degrade_bloque() -> None:
    # Plafond strict « < 30 % » : à 30 % pile, la maille n'est pas publiée.
    verdict = evaluer_invariants_publication(_maille(erreur_mediane=30.0), CalculConfig())

    assert verdict.publiable is False
    assert any("médiane" in raison for raison in verdict.raisons)


def test_erreur_au_dela_du_plafond_degrade_bloque() -> None:
    verdict = evaluer_invariants_publication(_maille(erreur_mediane=35.0), CalculConfig())

    assert verdict.publiable is False
    assert any("médiane" in raison for raison in verdict.raisons)


def test_erreur_mediane_absente_bloque_la_publication() -> None:
    # CV non calculable (échantillon trop petit) : on ne peut pas juger la fiabilité.
    verdict = evaluer_invariants_publication(_maille(erreur_mediane=None), CalculConfig())

    assert verdict.publiable is False
    assert any("médiane" in raison for raison in verdict.raisons)


def test_r2_trop_bas_bloque_la_publication() -> None:
    verdict = evaluer_invariants_publication(_maille(r2=0.20), CalculConfig())

    assert verdict.publiable is False
    assert any("R²" in raison for raison in verdict.raisons)


def test_r2_egal_au_seuil_bloque() -> None:
    # Seuil strict « R² > 0,30 » : à 0,30 pile, la maille n'est pas publiée.
    verdict = evaluer_invariants_publication(_maille(r2=0.30), CalculConfig())

    assert verdict.publiable is False


def test_n_egal_au_seuil_est_publiable() -> None:
    # Le plancher est « n ≥ 30 » : à 30 pile, la maille passe (bornes de #22).
    verdict = evaluer_invariants_publication(_maille(n=30), CalculConfig())

    assert verdict.publiable is True


def test_n_sous_le_seuil_bloque_la_publication() -> None:
    verdict = evaluer_invariants_publication(_maille(n=29), CalculConfig())

    assert verdict.publiable is False
    assert any("n" in raison.lower() for raison in verdict.raisons)


def test_les_raisons_se_cumulent() -> None:
    verdict = evaluer_invariants_publication(
        _maille(erreur_mediane=30.0, r2=0.1, n=10), CalculConfig()
    )

    assert verdict.publiable is False
    assert len(verdict.raisons) == 3


def test_le_gate_de_publication_lit_le_plafond_degrade_de_la_config() -> None:
    # #169 : le gate d'erreur est le **plafond dégradé**. Une erreur de 35 % bloque
    # au défaut (30 %) mais passe si le plafond est relevé à 40 % : la borne est une
    # constante configurable unique (``erreur_mediane_max_pct_degrade``), pas codée en dur.
    assert evaluer_invariants_publication(_maille(erreur_mediane=35.0), CalculConfig()).publiable is False

    permissif = replace(CalculConfig(), erreur_mediane_max_pct_degrade=40.0)
    verdict = evaluer_invariants_publication(_maille(erreur_mediane=35.0), permissif)

    assert verdict.publiable is True

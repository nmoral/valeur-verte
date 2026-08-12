"""INV-1 (#119) — monotonie de l'échelle DPE, par maille.

À surface/terrain/commune contrôlés, l'ordre doit tenir :
``coef(A) > coef(B) > … > coef(G)``. Une inversion entre **deux classes
adjacentes** est admise si l'effectif de la classe la moins fournie est sous le
seuil (petit échantillon = faiblesse statistique, pas un bug) — mais elle est
**journalisée**. Toute autre inversion (classes non adjacentes, ou adjacentes
mais toutes deux fournies) n'est pas publiée et remonte en alerte.

Les coefficients sont des **effets log** relatifs à D : le log préservant
l'ordre, la monotonie se vérifie directement sur ``coef_dpe``.
"""

from __future__ import annotations

from dataclasses import replace

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.config import CalculConfig
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.invariants import ResultatMonotonie, evaluer_monotonie_dpe


def _maille(coef_dpe: dict[str, float], effectifs_dpe: dict[str, int]) -> MailleCoefficient:
    return MailleCoefficient(
        code_insee="31555",
        millesime=2024,
        mode=CoverageMode.LOCAL,
        rayon_retenu_km=10,
        n_transactions=40,
        r2=0.5,
        erreur_mediane=15.0,
        coef_m2_bati=1500.0,
        coef_m2_terrain=100.0,
        coef_dpe=coef_dpe,
        ic_dpe={},
        alerte=None,
        effectifs_dpe=effectifs_dpe,
    )


def test_echelle_monotone_est_publiable() -> None:
    maille = _maille(
        {"A": 0.30, "C": 0.10, "D": 0.0, "F": -0.20},
        {"A": 40, "C": 40, "D": 40, "F": 40},
    )

    resultat = evaluer_monotonie_dpe(maille, CalculConfig())

    assert isinstance(resultat, ResultatMonotonie)
    assert resultat.publiable is True
    assert resultat.raisons == ()
    assert resultat.inversions_tolerees == ()


def test_inversion_adjacente_avec_classe_rare_est_toleree() -> None:
    # F et G adjacentes ; G peu fournie (12 < 30) → inversion admise, journalisée.
    maille = _maille({"F": -0.30, "G": -0.10}, {"F": 40, "G": 12})

    resultat = evaluer_monotonie_dpe(maille, CalculConfig())

    assert resultat.publiable is True
    assert resultat.raisons == ()
    assert any("F" in trace and "G" in trace for trace in resultat.inversions_tolerees)


def test_inversion_adjacente_deux_classes_fournies_bloque() -> None:
    # F et G adjacentes mais toutes deux fournies (≥ 30) → anomalie, non publiée.
    maille = _maille({"F": -0.30, "G": -0.10}, {"F": 40, "G": 35})

    resultat = evaluer_monotonie_dpe(maille, CalculConfig())

    assert resultat.publiable is False
    assert any("F" in raison and "G" in raison for raison in resultat.raisons)
    assert resultat.inversions_tolerees == ()


def test_inversion_non_adjacente_est_toujours_une_anomalie() -> None:
    # C et E non adjacentes (D entre) : inversion bloquante même si classes rares.
    maille = _maille({"C": 0.05, "E": 0.20}, {"C": 10, "E": 10})

    resultat = evaluer_monotonie_dpe(maille, CalculConfig())

    assert resultat.publiable is False
    assert resultat.inversions_tolerees == ()


def test_le_seuil_de_tolerance_provient_de_la_config() -> None:
    # G à 12 : toléré au seuil 30, mais bloquant si le seuil descend à 10.
    maille = _maille({"F": -0.30, "G": -0.10}, {"F": 40, "G": 12})
    strict = replace(CalculConfig(), inversion_dpe_seuil=10)

    assert evaluer_monotonie_dpe(maille, strict).publiable is False


def test_une_seule_classe_publiee_ne_pose_pas_de_probleme() -> None:
    maille = _maille({"D": 0.0}, {"D": 40})

    resultat = evaluer_monotonie_dpe(maille, CalculConfig())

    assert resultat.publiable is True
    assert resultat.inversions_tolerees == ()

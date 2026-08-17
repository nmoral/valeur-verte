"""REG-3 (#56) — vérificateur des cas de référence.

Un cas de référence décrit une adresse **figée** et le domaine plausible de sa
maille : ``mode``/``palier`` attendus (catégoriel, épingle le profil) et
**fourchettes** sur le gain E→C, l'erreur médiane et l'effectif. Le vérificateur
confronte un cas au ``MailleCoefficient`` recalculé et rend un ``Verdict`` :
conforme, ou la liste des écarts. Fonction **pure** : ``(cas, maille) → verdict``.

REG-3 attrape la régression de **code** (à données figées), là où REG-1 (#129)
mesure la dérive entre millésimes. Les valeurs attendues sont des **fourchettes**,
jamais des égalités exactes (anti-pattern proscrit par ``QUALITE-DONNEES.md``).
"""

from __future__ import annotations

import math

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.degraded import CoverageMode, PalierFiabilite
from valeur_verte_data.reg3 import CasReference, Profil, Verdict, verifier_cas

# coef_dpe de référence : C − E = 0,20 → gain E→C = exp(0,20) − 1 ≈ 22,14 %.
_GAIN_REF = (math.exp(0.20) - 1) * 100


def _maille(
    *,
    mode: CoverageMode = CoverageMode.LOCAL,
    palier: PalierFiabilite = PalierFiabilite.STANDARD,
    n: int = 2000,
    erreur: float | None = 12.0,
    coef_dpe: dict[str, float] | None = None,
) -> MailleCoefficient:
    """Maille recalculée minimale (seuls les champs jugés par REG-3 comptent)."""
    return MailleCoefficient(
        code_insee="31555",
        millesime=2025,
        mode=mode,
        rayon_retenu_km=10,
        n_transactions=n,
        r2=0.6,
        erreur_mediane=erreur,
        coef_m2_bati=0.9,
        coef_m2_terrain=0.3,
        coef_dpe=coef_dpe if coef_dpe is not None else {"D": 0.0, "C": 0.10, "E": -0.10},
        ic_dpe={},
        alerte=None,
        palier=palier,
    )


def _cas(
    *,
    mode: CoverageMode = CoverageMode.LOCAL,
    palier: PalierFiabilite = PalierFiabilite.STANDARD,
    n_min: int = 2000,
    erreur_max: float = 15.0,
    gain: tuple[float, float] = (14.0, 24.0),
    profil: Profil = Profil.SOLIDE,
) -> CasReference:
    return CasReference(
        code_insee="31555",
        profil=profil,
        mode_attendu=mode,
        palier_attendu=palier,
        n_min=n_min,
        erreur_max_pct=erreur_max,
        gain_ec_pct=gain,
    )


# --- cas conforme ---


def test_cas_conforme_rend_un_verdict_sans_ecart() -> None:
    verdict = verifier_cas(_cas(), _maille())
    assert isinstance(verdict, Verdict)
    assert verdict.conforme is True
    assert verdict.ecarts == ()


def test_le_verdict_porte_le_code_insee() -> None:
    assert verifier_cas(_cas(), _maille()).code_insee == "31555"


# --- mode / palier : catégoriel exact (épingle le profil) ---


def test_mode_different_est_non_conforme() -> None:
    verdict = verifier_cas(_cas(mode=CoverageMode.LOCAL), _maille(mode=CoverageMode.EPCI))
    assert verdict.conforme is False
    assert any("mode" in e for e in verdict.ecarts)


def test_palier_different_est_non_conforme() -> None:
    verdict = verifier_cas(
        _cas(palier=PalierFiabilite.STANDARD), _maille(palier=PalierFiabilite.DEGRADE)
    )
    assert verdict.conforme is False
    assert any("palier" in e for e in verdict.ecarts)


# --- effectif : fourchette basse (n_min) ---


def test_effectif_sous_le_minimum_est_non_conforme() -> None:
    verdict = verifier_cas(_cas(n_min=2000), _maille(n=1500))
    assert verdict.conforme is False
    assert any("effectif" in e for e in verdict.ecarts)


def test_effectif_au_minimum_est_conforme() -> None:
    # Borne incluse : n == n_min passe.
    assert verifier_cas(_cas(n_min=2000), _maille(n=2000)).conforme is True


# --- erreur médiane : plafond ---


def test_erreur_au_dessus_du_plafond_est_non_conforme() -> None:
    verdict = verifier_cas(_cas(erreur_max=15.0), _maille(erreur=18.0))
    assert verdict.conforme is False
    assert any("erreur" in e for e in verdict.ecarts)


def test_erreur_au_plafond_est_conforme() -> None:
    # Borne incluse : erreur == plafond passe.
    assert verifier_cas(_cas(erreur_max=15.0), _maille(erreur=15.0)).conforme is True


def test_erreur_absente_est_non_conforme() -> None:
    # Une maille sans erreur médiane n'est pas vérifiable → non conforme.
    verdict = verifier_cas(_cas(), _maille(erreur=None))
    assert verdict.conforme is False
    assert any("erreur" in e for e in verdict.ecarts)


# --- gain E→C : fourchette (le signal produit) ---


def test_gain_sous_la_fourchette_est_non_conforme() -> None:
    verdict = verifier_cas(_cas(gain=(30.0, 40.0)), _maille())  # gain réel ≈ 22 %
    assert verdict.conforme is False
    assert any("gain" in e for e in verdict.ecarts)


def test_gain_au_dessus_de_la_fourchette_est_non_conforme() -> None:
    verdict = verifier_cas(_cas(gain=(5.0, 10.0)), _maille())  # gain réel ≈ 22 %
    assert verdict.conforme is False
    assert any("gain" in e for e in verdict.ecarts)


def test_gain_borne_basse_incluse_est_conforme() -> None:
    # Le gain réel est exactement la borne basse (même calcul exp) → conforme.
    assert verifier_cas(_cas(gain=(_GAIN_REF, 30.0)), _maille()).conforme is True


def test_gain_borne_haute_incluse_est_conforme() -> None:
    assert verifier_cas(_cas(gain=(10.0, _GAIN_REF)), _maille()).conforme is True


def test_classe_c_absente_est_non_conforme() -> None:
    # Une classe publiée qui disparaît (C absente) = changement majeur → non conforme.
    # Le message doit nommer **la** classe manquante (C), sinon il est inexploitable.
    verdict = verifier_cas(_cas(), _maille(coef_dpe={"D": 0.0, "E": -0.10}))
    assert verdict.conforme is False
    assert any("gain" in e and "classe C" in e for e in verdict.ecarts)


def test_classe_e_absente_est_non_conforme() -> None:
    verdict = verifier_cas(_cas(), _maille(coef_dpe={"D": 0.0, "C": 0.10}))
    assert verdict.conforme is False
    assert any("gain" in e and "classe E" in e for e in verdict.ecarts)


# --- accumulation : tous les écarts sont remontés, pas de court-circuit ---


def test_plusieurs_ecarts_sont_tous_remontes() -> None:
    verdict = verifier_cas(
        _cas(mode=CoverageMode.LOCAL, n_min=2000, erreur_max=15.0),
        _maille(mode=CoverageMode.EPCI, n=100, erreur=25.0),
    )
    assert verdict.conforme is False
    assert len(verdict.ecarts) >= 3  # mode + effectif + erreur

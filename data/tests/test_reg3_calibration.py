"""REG-3 (#56) — calibration des fourchettes depuis une maille de référence.

Le calibrateur transforme une maille **recalculée** (baseline déterministe, INV-5)
en ``CasReference`` : ``mode``/``palier`` viennent de la maille ; les grandeurs
(gain E→C, erreur médiane, effectif) deviennent des **fourchettes** baseline ±
tolérance. La maille de référence est, par construction, **toujours conforme** à sa
propre fourchette — seule une dérive du moteur l'en fera sortir. Fonction pure.
"""

from __future__ import annotations

import io
import math

import pytest

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.degraded import CoverageMode, PalierFiabilite
from valeur_verte_data.reg3 import (
    CasReference,
    CasReferenceInvalide,
    Profil,
    calibrer_cas,
    charger_cas_reference,
    serialiser_cas,
    verifier_cas,
)

# gain E→C de référence : C − E = 0,20 → (exp(0,20) − 1)·100 ≈ 22,14 %.
_GAIN_REF = (math.exp(0.20) - 1) * 100


def _maille(
    *,
    mode: CoverageMode = CoverageMode.LOCAL,
    palier: PalierFiabilite = PalierFiabilite.STANDARD,
    n: int = 2000,
    erreur: float | None = 12.0,
    coef_dpe: dict[str, float] | None = None,
) -> MailleCoefficient:
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


def test_calibre_tous_les_champs_depuis_la_maille_et_la_tolerance() -> None:
    # erreur 12,1 (pas 12,0) : 12,1 · 1,25 = 15,125 → arrondi à 0,1 = 15,1 (fixe la
    # précision d'arrondi, qu'un plafond « rond » laisserait indéterminée).
    cas = calibrer_cas(Profil.SOLIDE, _maille(n=2000, erreur=12.1), tolerance=0.25)
    assert cas == CasReference(
        code_insee="31555",
        profil=Profil.SOLIDE,
        mode_attendu=CoverageMode.LOCAL,
        palier_attendu=PalierFiabilite.STANDARD,
        n_min=1500,  # floor(2000 · 0,75)
        erreur_max_pct=15.1,  # round(12,1 · 1,25 ; 1)
        gain_ec_pct=(16.6, 27.7),  # 22,14 · 0,75 et · 1,25, arrondis à 0,1
    )


def test_mode_et_palier_viennent_de_la_maille() -> None:
    cas = calibrer_cas(
        Profil.REPLI_DEPARTEMENTAL,
        _maille(mode=CoverageMode.DEPARTEMENTAL, palier=PalierFiabilite.STANDARD),
        tolerance=0.25,
    )
    assert cas.mode_attendu is CoverageMode.DEPARTEMENTAL


def test_une_tolerance_plus_large_elargit_la_fourchette() -> None:
    etroit = calibrer_cas(Profil.SOLIDE, _maille(), tolerance=0.10)
    large = calibrer_cas(Profil.SOLIDE, _maille(), tolerance=0.40)
    assert large.gain_ec_pct[0] < etroit.gain_ec_pct[0]
    assert large.gain_ec_pct[1] > etroit.gain_ec_pct[1]
    assert large.n_min < etroit.n_min
    assert large.erreur_max_pct > etroit.erreur_max_pct


def test_la_maille_de_reference_est_toujours_conforme_a_sa_fourchette() -> None:
    # Propriété centrale : la baseline est dans sa propre fourchette → seule une
    # vraie dérive du moteur peut faire échouer le cas.
    maille = _maille(n=3064, erreur=11.45, coef_dpe={"D": 0.0, "C": 0.12, "E": -0.15})
    cas = calibrer_cas(Profil.SOLIDE, maille, tolerance=0.25)
    assert verifier_cas(cas, maille).conforme is True


def test_profil_incoherent_avec_le_mode_leve_une_erreur() -> None:
    # Étiquette repli départemental sur une maille locale : incohérent.
    with pytest.raises(CasReferenceInvalide) as err:
        calibrer_cas(Profil.REPLI_DEPARTEMENTAL, _maille(mode=CoverageMode.LOCAL), tolerance=0.25)
    assert "incohérent" in str(err.value) and "31555" in str(err.value)


def test_erreur_mediane_absente_empeche_la_calibration() -> None:
    with pytest.raises(CasReferenceInvalide) as err:
        calibrer_cas(Profil.SOLIDE, _maille(erreur=None), tolerance=0.25)
    assert "erreur médiane" in str(err.value) and "31555" in str(err.value)


def test_classe_c_absente_empeche_la_calibration() -> None:
    with pytest.raises(CasReferenceInvalide) as err:
        calibrer_cas(Profil.SOLIDE, _maille(coef_dpe={"D": 0.0, "E": -0.10}), tolerance=0.25)
    assert "classe C" in str(err.value)


def test_classe_e_absente_empeche_la_calibration() -> None:
    with pytest.raises(CasReferenceInvalide) as err:
        calibrer_cas(Profil.SOLIDE, _maille(coef_dpe={"D": 0.0, "C": 0.10}), tolerance=0.25)
    assert "classe E" in str(err.value)


# --- sérialisation : aller-retour avec le chargeur ---


def test_serialiser_puis_charger_est_l_identite() -> None:
    import json

    cas = calibrer_cas(Profil.FRAGILE, _maille(palier=PalierFiabilite.DEGRADE), tolerance=0.25)
    texte = json.dumps([serialiser_cas(cas)])
    assert charger_cas_reference(io.StringIO(texte)) == [cas]

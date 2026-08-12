"""INV-2 (#120) — signe et ordre de grandeur des coefficients, par maille.

Reformulé pour le modèle **log-prix** : ``coef_dpe`` sont des effets log,
``coef_m2_bati``/``coef_m2_terrain`` des élasticités (pas des €/m²). On vérifie
donc des **signes et des ordres** (natifs au log) plus des **bornes de magnitude
en log** configurables, à la place des bornes € de l'issue :

- gain E→C strictement positif (``coef_C > coef_E``) ;
- gain E→D < gain E→C, effet de seuil (``coef_D < coef_C``) ;
- élasticité bâti > 0, élasticité terrain > 0, terrain < bâti ;
- gain E→C sous un plafond de vraisemblance ; élasticité bâti dans une plage.

Les contrôles DPE ne s'appliquent que si les classes concernées sont publiées.
Une maille en échec n'est pas publiée + alerte (comme INV-4).
"""

from __future__ import annotations

from dataclasses import replace

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.config import CalculConfig
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.invariants import VerdictPublication, evaluer_signe_ordre_grandeur


def _maille(
    coef_dpe: dict[str, float],
    *,
    coef_m2_bati: float = 0.90,
    coef_m2_terrain: float = 0.30,
) -> MailleCoefficient:
    return MailleCoefficient(
        code_insee="31555",
        millesime=2024,
        mode=CoverageMode.LOCAL,
        rayon_retenu_km=10,
        n_transactions=40,
        r2=0.5,
        erreur_mediane=15.0,
        coef_m2_bati=coef_m2_bati,
        coef_m2_terrain=coef_m2_terrain,
        coef_dpe=coef_dpe,
        ic_dpe={},
        alerte=None,
    )


def test_maille_saine_est_publiable() -> None:
    maille = _maille({"E": -0.10, "D": 0.0, "C": 0.10})

    verdict = evaluer_signe_ordre_grandeur(maille, CalculConfig())

    assert isinstance(verdict, VerdictPublication)
    assert verdict.publiable is True
    assert verdict.raisons == ()


def test_gain_e_vers_c_negatif_bloque() -> None:
    # C moins valorisée que E : le gain de rénovation E→C serait négatif.
    maille = _maille({"E": 0.10, "C": -0.10})

    verdict = evaluer_signe_ordre_grandeur(maille, CalculConfig())

    assert verdict.publiable is False
    assert any("E" in raison and "C" in raison for raison in verdict.raisons)


def test_gain_e_vers_d_pas_inferieur_a_e_vers_c_bloque() -> None:
    # Effet de seuil rompu : D vaut plus que C, donc gain E→D ≥ gain E→C.
    maille = _maille({"E": -0.20, "D": 0.15, "C": 0.10})

    verdict = evaluer_signe_ordre_grandeur(maille, CalculConfig())

    assert verdict.publiable is False


def test_elasticite_bati_negative_bloque() -> None:
    maille = _maille({"E": -0.10, "C": 0.10}, coef_m2_bati=-0.5)

    verdict = evaluer_signe_ordre_grandeur(maille, CalculConfig())

    assert verdict.publiable is False
    assert any("bâti" in raison for raison in verdict.raisons)


def test_elasticite_terrain_negative_bloque() -> None:
    maille = _maille({"E": -0.10, "C": 0.10}, coef_m2_terrain=-0.1)

    verdict = evaluer_signe_ordre_grandeur(maille, CalculConfig())

    assert verdict.publiable is False
    assert any("terrain" in raison for raison in verdict.raisons)


def test_terrain_superieur_au_bati_bloque() -> None:
    # Un m² de terrain ne peut pas peser plus qu'un m² bâti (élasticités).
    maille = _maille({"E": -0.10, "C": 0.10}, coef_m2_bati=0.90, coef_m2_terrain=1.00)

    verdict = evaluer_signe_ordre_grandeur(maille, CalculConfig())

    assert verdict.publiable is False
    assert any("terrain" in raison and "bâti" in raison for raison in verdict.raisons)


def test_gain_e_vers_c_hors_borne_haute_bloque() -> None:
    # Gain E→C = 1,0 en log (≈ +170 %) : au-delà du plafond de vraisemblance.
    maille = _maille({"E": -0.50, "C": 0.50})

    verdict = evaluer_signe_ordre_grandeur(maille, CalculConfig())

    assert verdict.publiable is False


def test_elasticite_bati_hors_plage_bloque() -> None:
    # Élasticité bâti de 2,0 : hors [0,30 ; 1,50], aberrante.
    maille = _maille({"E": -0.10, "C": 0.10}, coef_m2_bati=2.0)

    verdict = evaluer_signe_ordre_grandeur(maille, CalculConfig())

    assert verdict.publiable is False


def test_controles_dpe_ignores_si_classes_absentes() -> None:
    # Ni E ni C publiées : le gain E→C n'est pas calculable, on ne le juge pas.
    maille = _maille({"D": 0.0, "F": -0.20})

    verdict = evaluer_signe_ordre_grandeur(maille, CalculConfig())

    assert verdict.publiable is True


def test_les_bornes_proviennent_de_la_config() -> None:
    # Un gain E→C de 0,30 passe par défaut mais bloque si le plafond descend à 0,20.
    maille = _maille({"E": -0.15, "C": 0.15})  # gain E→C = 0,30
    strict = replace(CalculConfig(), gain_dpe_ec_max=0.20)

    assert evaluer_signe_ordre_grandeur(maille, CalculConfig()).publiable is True
    assert evaluer_signe_ordre_grandeur(maille, strict).publiable is False

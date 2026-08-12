"""INV-3 (#121) — cohérence géographique, par maille et en fin de batch.

Trois invariants :

1. Le **rayon retenu ne dépasse jamais** le plafond (50 km), sauf en repli
   départemental explicite (rayon indéfini, mode ``DEPARTEMENTAL``).
2. Le rayon retenu est **croissant quand l'effectif local décroît** (propriété
   du sélecteur adaptatif, testée sur ``adaptive_selection``).
3. Deux **communes limitrophes** ne présentent pas d'écart de coefficient
   supérieur à un facteur 2 (artefact de bord — testé en fin de batch).

Ce fichier couvre l'**invariant #1** : une maille dont le rayon dépasse le
plafond n'est pas publiée (même conséquence qu'INV-4). Le repli départemental
en est exempté : c'est précisément le cas où le plafond ne s'applique pas.
"""

from __future__ import annotations

from dataclasses import replace

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.config import CalculConfig
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.dvf import Mutation, aggregate_mutations
from valeur_verte_data.invariants import (
    VerdictPublication,
    evaluer_coherence_geographique,
)
from valeur_verte_data.radius import adaptive_selection


def _maille(
    *,
    mode: CoverageMode = CoverageMode.LOCAL,
    rayon_retenu_km: int | None = 10,
) -> MailleCoefficient:
    return MailleCoefficient(
        code_insee="31555",
        millesime=2024,
        mode=mode,
        rayon_retenu_km=rayon_retenu_km,
        n_transactions=40,
        r2=0.5,
        erreur_mediane=15.0,
        coef_m2_bati=1500.0,
        coef_m2_terrain=100.0,
        coef_dpe={"D": 0.0, "F": -0.20},
        ic_dpe={},
        alerte=None,
    )


def test_maille_locale_sous_le_plafond_est_publiable() -> None:
    verdict = evaluer_coherence_geographique(_maille(rayon_retenu_km=30), CalculConfig())

    assert isinstance(verdict, VerdictPublication)
    assert verdict.publiable is True
    assert verdict.raisons == ()


def test_rayon_au_plafond_est_publiable() -> None:
    # « Ne dépasse jamais 50 km » : la borne est inclusive, 50 pile passe.
    verdict = evaluer_coherence_geographique(_maille(rayon_retenu_km=50), CalculConfig())

    assert verdict.publiable is True


def test_rayon_au_dessus_du_plafond_bloque_la_publication() -> None:
    # Ne peut arriver que si les paliers sont mal configurés : garde défensive.
    verdict = evaluer_coherence_geographique(_maille(rayon_retenu_km=60), CalculConfig())

    assert verdict.publiable is False
    assert any("rayon" in raison.lower() for raison in verdict.raisons)


def test_repli_departemental_exempt_du_plafond() -> None:
    # Repli départemental : rayon indéfini, le plafond de 50 km ne s'applique pas.
    verdict = evaluer_coherence_geographique(
        _maille(mode=CoverageMode.DEPARTEMENTAL, rayon_retenu_km=None), CalculConfig()
    )

    assert verdict.publiable is True
    assert verdict.raisons == ()


def test_le_plafond_provient_de_la_config() -> None:
    # Le plafond est une constante configurable unique, pas codée en dur : un
    # rayon de 40 km passe par défaut mais bloque si le plafond est abaissé à 30.
    strict = replace(CalculConfig(), rayon_max_km=30)

    verdict = evaluer_coherence_geographique(_maille(rayon_retenu_km=40), strict)

    assert verdict.publiable is False
    assert any("rayon" in raison.lower() for raison in verdict.raisons)


# --- Invariant #2 : rayon croissant quand l'effectif local décroît -----------
#
# Interprétation fidèle et déterministe : **retirer des ventes ne réduit jamais
# le rayon retenu**. Le sélecteur prend le plus petit rayon atteignant
# ``min_sample`` ; enlever un point ne peut qu'éloigner (ou laisser identique) ce
# seuil. C'est la monotonie « effectif ↘ ⇒ rayon ↗ (au sens large) ».

_CENTRE_LAT, _CENTRE_LON = 43.6, 1.44


def _at(lat: float) -> Mutation:
    # Sur le méridien du centre, 1° de latitude ≈ 111 km : distance = |Δlat|·111.
    row = {
        "id_mutation": f"{lat}",
        "valeur_fonciere": "200000",
        "code_commune": "31555",
        "surface_reelle_bati": "80",
        "latitude": str(lat),
        "longitude": str(_CENTRE_LON),
    }
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


# Quatre ventes à ~0, ~5, ~9 et ~18 km du centre.
_P0, _P1, _P2, _P3 = _at(43.60), _at(43.645), _at(43.68), _at(43.76)


def _rayon(ventes: list[Mutation]) -> int:
    return adaptive_selection(_CENTRE_LAT, _CENTRE_LON, ventes, min_sample=3).rayon_km


def test_retirer_une_vente_proche_agrandit_le_rayon() -> None:
    # Effectif plein : trois ventes sous 10 km → rayon 10.
    plein = [_P0, _P1, _P2, _P3]
    # On retire une vente proche : il ne reste que deux ventes sous 10 km, il
    # faut aller jusqu'à 20 km pour retrouver trois ventes → le rayon grandit.
    ampute = [_P0, _P2, _P3]

    assert _rayon(plein) == 10
    assert _rayon(ampute) == 20
    assert _rayon(ampute) >= _rayon(plein)


def test_retirer_une_vente_hors_selection_ne_reduit_pas_le_rayon() -> None:
    # Retirer une vente lointaine (hors de l'échantillon retenu) fait décroître
    # l'effectif sans changer le rayon : la monotonie est « au sens large ».
    plein = [_P0, _P1, _P2, _P3]
    ampute = [_P0, _P1, _P2]

    assert _rayon(ampute) >= _rayon(plein)
    assert _rayon(ampute) == 10

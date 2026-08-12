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
    RuptureLimitrophe,
    VerdictPublication,
    alertes_de_bord,
    detecter_ruptures_limitrophes,
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


# --- Invariant #3 : facteur 2 entre communes limitrophes ---------------------
#
# Deux communes limitrophes dont le coef_m2_bati diffère de plus d'un facteur 2
# signalent un artefact de bord probable (§3.2). Détection pure : elle consomme
# les mailles calculées et l'adjacence (dérivée en base), sans décider encore de
# la conséquence (non-publication + alerte — tranche 5).


def _maille_bati(code_insee: str, coef_m2_bati: float) -> MailleCoefficient:
    return MailleCoefficient(
        code_insee=code_insee,
        millesime=2024,
        mode=CoverageMode.LOCAL,
        rayon_retenu_km=10,
        n_transactions=40,
        r2=0.5,
        erreur_mediane=15.0,
        coef_m2_bati=coef_m2_bati,
        coef_m2_terrain=100.0,
        coef_dpe={"D": 0.0, "F": -0.20},
        ic_dpe={},
        alerte=None,
    )


def test_ecart_sous_le_facteur_2_ne_signale_rien() -> None:
    mailles = [_maille_bati("31001", 1000.0), _maille_bati("31002", 1500.0)]
    adjacence = {"31001": ("31002",), "31002": ("31001",)}

    ruptures = detecter_ruptures_limitrophes(mailles, adjacence, CalculConfig())

    assert ruptures == ()


def test_ecart_egal_au_facteur_2_est_tolere() -> None:
    # « Supérieur à un facteur 2 » : à 2,0 pile, pas de rupture (borne stricte).
    mailles = [_maille_bati("31001", 1000.0), _maille_bati("31002", 2000.0)]
    adjacence = {"31001": ("31002",), "31002": ("31001",)}

    assert detecter_ruptures_limitrophes(mailles, adjacence, CalculConfig()) == ()


def test_ecart_au_dessus_du_facteur_2_est_signale() -> None:
    mailles = [_maille_bati("31001", 1000.0), _maille_bati("31002", 3000.0)]
    adjacence = {"31001": ("31002",), "31002": ("31001",)}

    ruptures = detecter_ruptures_limitrophes(mailles, adjacence, CalculConfig())

    assert len(ruptures) == 1
    rupture = ruptures[0]
    assert isinstance(rupture, RuptureLimitrophe)
    # Ordre canonique (a < b), quel que soit le sens de parcours de l'adjacence.
    assert (rupture.code_insee_a, rupture.code_insee_b) == ("31001", "31002")
    assert rupture.facteur == 3.0


def test_communes_non_limitrophes_ne_sont_pas_comparees() -> None:
    # Gros écart mais aucune arête commune : ce n'est pas un artefact de bord.
    mailles = [_maille_bati("31001", 1000.0), _maille_bati("31050", 5000.0)]

    assert detecter_ruptures_limitrophes(mailles, {}, CalculConfig()) == ()


def test_voisin_sans_maille_publiee_est_ignore() -> None:
    # La commune voisine est en refus (pas de maille) : la paire est incomparable,
    # pas une rupture — et surtout pas une KeyError.
    mailles = [_maille_bati("31001", 1000.0)]
    adjacence = {"31001": ("31002",), "31002": ("31001",)}

    assert detecter_ruptures_limitrophes(mailles, adjacence, CalculConfig()) == ()


def test_une_paire_n_est_signalee_qu_une_fois() -> None:
    # L'adjacence donne les deux sens ; la rupture ne doit pas être dédoublée.
    mailles = [_maille_bati("31001", 1000.0), _maille_bati("31002", 3000.0)]
    adjacence = {"31001": ("31002",), "31002": ("31001",)}

    assert len(detecter_ruptures_limitrophes(mailles, adjacence, CalculConfig())) == 1


def test_le_facteur_provient_de_la_config() -> None:
    # Seuil configurable unique : à 1,2, un écart de 1,5 devient une rupture.
    strict = replace(CalculConfig(), rupture_facteur_max=1.2)
    mailles = [_maille_bati("31001", 1000.0), _maille_bati("31002", 1500.0)]
    adjacence = {"31001": ("31002",), "31002": ("31001",)}

    assert len(detecter_ruptures_limitrophes(mailles, adjacence, strict)) == 1


def test_coef_bati_non_positif_est_hors_perimetre() -> None:
    # Un coef_m2_bati ≤ 0 est une anomalie de signe (INV-2), pas de bord : le
    # ratio n'a pas de sens, la paire est ignorée par INV-3.
    mailles = [_maille_bati("31001", 0.0), _maille_bati("31002", 3000.0)]
    adjacence = {"31001": ("31002",), "31002": ("31001",)}

    assert detecter_ruptures_limitrophes(mailles, adjacence, CalculConfig()) == ()


def test_ruptures_ordonnees_de_facon_deterministe() -> None:
    # INV-5 : l'ordre de sortie ne dépend pas de l'ordre d'itération des mailles.
    # Deux ruptures : 3000/1000 = 3 et 7000/3000 ≈ 2,33, toutes deux > 2.
    mailles = [
        _maille_bati("31003", 7000.0),
        _maille_bati("31001", 1000.0),
        _maille_bati("31002", 3000.0),
    ]
    adjacence = {
        "31001": ("31002",),
        "31002": ("31001", "31003"),
        "31003": ("31002",),
    }

    ruptures = detecter_ruptures_limitrophes(mailles, adjacence, CalculConfig())
    paires = [(r.code_insee_a, r.code_insee_b) for r in ruptures]

    assert paires == [("31001", "31002"), ("31002", "31003")]


# --- Alertes de bord : conséquence « publier avec alerte » (tranche 5) --------


def test_alertes_de_bord_marque_les_deux_communes() -> None:
    ruptures = (RuptureLimitrophe("31001", "31002", 1000.0, 3000.0, 3.0),)

    alertes = alertes_de_bord(ruptures)

    assert set(alertes) == {"31001", "31002"}
    assert "3.0" in alertes["31001"]
    assert "3.0" in alertes["31002"]


def test_alertes_de_bord_retient_le_pire_facteur() -> None:
    # Une commune au carrefour de deux ruptures porte le facteur le plus fort.
    ruptures = (
        RuptureLimitrophe("31001", "31002", 1000.0, 3000.0, 3.0),
        RuptureLimitrophe("31001", "31003", 1000.0, 5000.0, 5.0),
    )

    alertes = alertes_de_bord(ruptures)

    assert "5.0" in alertes["31001"]


def test_alertes_de_bord_vide_sans_rupture() -> None:
    assert alertes_de_bord(()) == {}

"""Tests de la régression pondérée (§5.2)."""

from __future__ import annotations

import math
from dataclasses import replace

import pytest

from valeur_verte_data.dvf import Mutation, aggregate_mutations
from valeur_verte_data.regression import (
    InsufficientSampleError,
    fit,
    gaussian_weight,
    is_pre_1989,
    poids_temporel,
)

_SURFACES = ((80, 200), (100, 300), (120, 500))
# Effets en logarithme : ≈ −18 % pour F, +10 % pour C.
_DPE_EFFECT = {"D": 0.0, "F": -0.20, "C": 0.10}
_ELASTICITE_BATI = 0.9
_ELASTICITE_TERRAIN = 0.05
_PRIX_BASE = 3000.0


def _house(
    dpe: str,
    bati: int,
    terrain: int,
    prix: int,
    *,
    insee: str = "31555",
    year: int = 2024,
    periode: str = "1948-1974",
    lat: float = 43.6,
    lon: float = 1.44,
) -> Mutation:
    (base,) = aggregate_mutations(
        [
            {
                "id_mutation": f"{insee}-{dpe}-{bati}-{terrain}-{prix}-{lat}",
                "valeur_fonciere": str(prix),
                "code_commune": insee,
                "surface_reelle_bati": str(bati),
                "surface_terrain": str(terrain),
                "code_nature_culture": "S",
                "id_parcelle": f"p-{bati}-{terrain}-{prix}-{lat}",
                "latitude": str(lat),
                "longitude": str(lon),
            }
        ],
        millesime=year,
    )
    return replace(base, etiquette_dpe=dpe, periode_construction=periode)


def _prix_theorique(bati: int, terrain: int, effet: float, facteur: float = 1.0) -> int:
    """Prix engendré par le modèle log-linéaire que la régression doit retrouver."""
    prix = (
        _PRIX_BASE
        * bati**_ELASTICITE_BATI
        * (1 + terrain) ** _ELASTICITE_TERRAIN
        * math.exp(effet)
        * facteur
    )
    return round(prix)


def _sample_connu(insee: str = "31555", facteur: float = 1.0) -> list[Mutation]:
    return [
        _house(dpe, bati, terrain, _prix_theorique(bati, terrain, effect, facteur), insee=insee)
        for dpe, effect in _DPE_EFFECT.items()
        for bati, terrain in _SURFACES
    ]


def test_gaussian_weight_maximal_a_distance_nulle() -> None:
    assert gaussian_weight(0.0) == 1.0


def test_gaussian_weight_decroit_avec_la_distance() -> None:
    assert gaussian_weight(10.0) < gaussian_weight(5.0) < 1.0


def test_gaussian_weight_a_un_sigma_vaut_exp_moins_un_demi() -> None:
    assert round(gaussian_weight(10.0, sigma_km=10.0), 6) == round(math.exp(-0.5), 6)


def test_poids_temporel_desactive_vaut_un() -> None:
    # τ absent (None) = pondération temporelle désactivée : poids égaux (#177),
    # non-régression exacte quel que soit l'âge de la vente (#208).
    assert poids_temporel(0.0, None) == 1.0
    assert poids_temporel(5.0, None) == 1.0


def test_poids_temporel_maximal_a_anciennete_nulle() -> None:
    # Le millésime de référence N (ancienneté 0) garde son poids plein.
    assert poids_temporel(0.0, 1.0) == 1.0


def test_poids_temporel_decroit_avec_anciennete() -> None:
    # Plus une vente est ancienne, moins elle pèse (biais de retard réduit, #208).
    assert poids_temporel(2.0, 1.0) < poids_temporel(1.0, 1.0) < 1.0


def test_poids_temporel_a_une_constante_de_temps_vaut_exp_moins_un() -> None:
    # À une ancienneté égale à τ, le poids vaut exp(-1) (définition de τ).
    assert round(poids_temporel(3.0, 3.0), 6) == round(math.exp(-1.0), 6)


def test_is_pre_1989_reconnait_le_bati_ancien() -> None:
    assert is_pre_1989("1948-1974")
    assert is_pre_1989("avant 1948")
    assert is_pre_1989("1983-1988")


def test_is_pre_1989_rejette_le_bati_recent() -> None:
    assert not is_pre_1989("1989-2000")
    assert not is_pre_1989("après 2012")


def test_is_pre_1989_rejette_l_inconnu() -> None:
    assert not is_pre_1989("")
    assert not is_pre_1989(None)
    assert not is_pre_1989("inconnu")


def test_fit_recupere_les_coefficients_connus() -> None:
    result = fit(_sample_connu(), 43.6, 1.44, reference_dpe="D")

    assert result.n == 9
    assert result.coef_dpe["D"] == 0.0
    assert round(result.coef_dpe["F"], 2) == -0.20
    assert round(result.coef_dpe["C"], 2) == 0.10
    assert round(result.coef_m2_bati, 2) == _ELASTICITE_BATI
    assert round(result.coef_m2_terrain, 2) == _ELASTICITE_TERRAIN
    assert result.r2 > 0.999


def test_fit_exclut_le_bati_recent() -> None:
    recent = _house("F", 100, 300, 999_999, periode="1990-2000")

    result = fit([*_sample_connu(), recent], 43.6, 1.44)

    assert result.n == 9


def test_fit_exclut_les_mutations_sans_etiquette() -> None:
    sans_dpe = replace(_house("D", 100, 300, 132_000), etiquette_dpe=None)

    result = fit([*_sample_connu(), sans_dpe], 43.6, 1.44)

    assert result.n == 9


def test_fit_echantillon_vide_apres_restriction_leve() -> None:
    with pytest.raises(InsufficientSampleError):
        fit([_house("D", 100, 300, 132_000, periode="2000-2010")], 43.6, 1.44)


def test_fit_effets_fixes_commune_isolent_le_coef_dpe() -> None:
    # Commune B 20 % plus chère : l'effet fixe commune doit l'absorber.
    sample = _sample_connu("31555") + _sample_connu("31300", facteur=1.2)

    result = fit(sample, 43.6, 1.44)

    assert round(result.coef_dpe["F"], 2) == -0.20
    assert round(result.coef_dpe["C"], 2) == 0.10


def _bati_series(dpe: str, effet: float, *, lat: float, lon: float) -> list[Mutation]:
    return [
        _house(dpe, bati, 0, _prix_theorique(bati, 0, effet), lat=lat, lon=lon)
        for bati in (80, 100, 120)
    ]


def test_is_pre_1989_borne_stricte_a_1989() -> None:
    assert not is_pre_1989("1983-1989")


def test_fit_pondere_par_la_distance() -> None:
    # Points proches (poids ~1) : F = −30 %. Points lointains ~50 km (poids ~0) : F = +10 %.
    near = _bati_series("D", 0.0, lat=43.6, lon=1.44) + _bati_series("F", -0.30, lat=43.6, lon=1.44)
    far = _bati_series("D", 0.0, lat=44.05, lon=1.44) + _bati_series("F", 0.10, lat=44.05, lon=1.44)

    result = fit(near + far, 43.6, 1.44)

    # Sans pondération par la distance, F ≈ −10 % (moyenne) ; avec, F ≈ −30 %.
    assert result.coef_dpe["F"] < -0.25


def test_fit_r2_borne_sur_ajustement_imparfait() -> None:
    sample = _sample_connu()
    sample[0] = replace(sample[0], prix=(sample[0].prix or 0.0) + 30000)

    result = fit(sample, 43.6, 1.44)

    # Une valeur figée dépendrait de l'échelle d'ajustement ; l'intention est
    # qu'un ajustement imparfait reste borné sous 1 sans s'effondrer.
    assert 0.9 < result.r2 < 1.0


def test_fit_exclut_tous_les_cas_meme_places_avant_les_valides() -> None:
    exclus = [
        _house("F", 100, 300, 999_999, periode="1990-2000"),
        replace(_house("D", 100, 300, 132_000), etiquette_dpe=None),
        replace(_house("D", 100, 300, 132_000), latitude=None, longitude=None),
        replace(_house("D", 100, 300, 132_000), longitude=None),
        replace(_house("D", 100, 300, 132_000), surface_bati=None),
        _house("D", 0, 300, 30_000),
    ]

    result = fit([*exclus, *_sample_connu()], 43.6, 1.44)

    assert result.n == 9


def test_fit_reference_dpe_absente_replie_sur_le_premier_niveau() -> None:
    sample = [
        _house(dpe, bati, 0, _prix_theorique(bati, 0, effet))
        for dpe, effet in {"C": 0.0, "F": -0.20}.items()
        for bati in (80, 100, 120)
    ]

    result = fit(sample, 43.6, 1.44, reference_dpe="A")

    assert result.coef_dpe["C"] == 0.0
    assert round(result.coef_dpe["F"], 2) == -0.20


def test_fit_effets_fixes_commune_corrigent_le_confondu() -> None:
    # F concentré dans la commune B, 50 % plus chère : sans effet fixe, F y paraîtrait cher.
    commune_a = [
        _house("D", b, 0, _prix_theorique(b, 0, 0.0), insee="31555") for b in (80, 100, 120)
    ]
    commune_a += [_house("F", 100, 0, _prix_theorique(100, 0, -0.20), insee="31555")]
    commune_b = [
        _house("F", b, 0, _prix_theorique(b, 0, -0.20, facteur=1.5), insee="31300")
        for b in (80, 100, 120)
    ]
    commune_b += [_house("D", 100, 0, _prix_theorique(100, 0, 0.0, facteur=1.5), insee="31300")]

    result = fit(commune_a + commune_b, 43.6, 1.44)

    assert round(result.coef_dpe["F"], 2) == -0.20


def test_fit_effets_fixes_annee_corrigent_le_confondu() -> None:
    # F concentré sur le millésime 2024, 50 % plus cher : l'effet fixe année doit l'absorber.
    an_2023 = [_house("D", b, 0, _prix_theorique(b, 0, 0.0), year=2023) for b in (80, 100, 120)]
    an_2023 += [_house("F", 100, 0, _prix_theorique(100, 0, -0.20), year=2023)]
    an_2024 = [
        _house("F", b, 0, _prix_theorique(b, 0, -0.20, facteur=1.5), year=2024)
        for b in (80, 100, 120)
    ]
    an_2024 += [_house("D", 100, 0, _prix_theorique(100, 0, 0.0, facteur=1.5), year=2024)]

    result = fit(an_2023 + an_2024, 43.6, 1.44)

    assert round(result.coef_dpe["F"], 2) == -0.20


def test_fit_r2_nul_si_prix_constant() -> None:
    # Prix tous égaux → variance nulle → R² défini à 0 (garde anti-division par zéro).
    sample = [_house(dpe, bati, 0, 100_000) for dpe in ("D", "F") for bati in (80, 100, 120)]

    result = fit(sample, 43.6, 1.44)

    assert result.r2 == 0.0


def _maison_log(dpe: str, bati: float, effet_dpe: float, *, lat: float = 43.6) -> Mutation:
    """Vente engendrée par un modèle multiplicatif : prix = 3000 · bati^0,9 · e^effet."""
    prix = 3000.0 * (bati**0.9) * math.exp(effet_dpe)
    return replace(
        _house(dpe, int(bati), 0, int(prix)),
        latitude=lat,
    )


def test_fit_estime_des_effets_relatifs_et_non_des_euros() -> None:
    """Les coefficients doivent être comparables d'une commune à l'autre (#115).

    En euros absolus, une même perte de valeur s'exprime par −2 114 à Toulouse
    et −65 820 à Muret : les deux nombres ne sont pas comparables, alors que le
    widget les présentera de la même façon. En logarithme, ce sont des taux.
    """
    effets = {"D": 0.0, "F": -0.20, "C": +0.10}
    echantillon = [
        _maison_log(dpe, 80 + 5 * i, effet) for dpe, effet in effets.items() for i in range(12)
    ]

    result = fit(echantillon, 43.6, 1.44, reference_dpe="D")

    assert result.coef_dpe["D"] == 0.0
    assert round(result.coef_dpe["F"], 2) == -0.20, "effet attendu en log, soit ≈ −20 %"
    assert round(result.coef_dpe["C"], 2) == +0.10


def test_fit_estime_une_elasticite_de_surface() -> None:
    """En log, le coefficient de surface est une élasticité, pas un prix au m²."""
    echantillon = [_maison_log("D", 60 + 10 * i, 0.0) for i in range(15)]

    result = fit(echantillon, 43.6, 1.44, reference_dpe="D")

    assert round(result.coef_m2_bati, 2) == 0.90


def test_fit_isole_le_dpe_de_la_periode_de_construction() -> None:
    """La classe DPE sert de proxy à l'ancienneté si on ne la contrôle pas (#115).

    Mesuré sur le 31 : 44 % des logements classés G sont d'avant 1948, contre
    2 % des A. Là où l'ancien est recherché — hypercentre toulousain —, la
    régression attribue alors à l'étiquette ce qui revient à l'époque du bâti.
    """
    # F est concentré dans l'ancien, lui-même 30 % plus cher : sans contrôle de
    # la période, F paraîtrait moins pénalisé qu'il ne l'est.
    recent = [
        _house("D", b, 0, _prix_theorique(b, 0, 0.0), periode="1975-1977") for b in (80, 100, 120)
    ]
    recent += [_house("F", 100, 0, _prix_theorique(100, 0, -0.20), periode="1975-1977")]
    ancien = [
        _house("F", b, 0, _prix_theorique(b, 0, -0.20, facteur=1.3), periode="avant 1948")
        for b in (80, 100, 120)
    ]
    ancien += [_house("D", 100, 0, _prix_theorique(100, 0, 0.0, facteur=1.3), periode="avant 1948")]

    result = fit(recent + ancien, 43.6, 1.44)

    assert round(result.coef_dpe["F"], 2) == -0.20


def test_fit_isole_le_dpe_de_l_emplacement_fin() -> None:
    """Le quartier explique le prix bien plus que l'étiquette (#115).

    À Toulouse, les logements mal classés sont concentrés dans l'hypercentre
    historique, le secteur le plus cher : sans contrôle de l'emplacement fin,
    la régression y attribue à l'étiquette ce qui revient à la rue. La
    pondération par distance au centroïde communal ne discrimine rien à
    l'intérieur d'une grande ville.
    """
    # Deux quartiers de la même commune, distants de ~2 km. Le quartier cher
    # (+40 %) concentre les F : sans indice local, F paraîtrait peu pénalisé.
    quartier_ordinaire = [
        _house("D", b, 0, _prix_theorique(b, 0, 0.0), lat=43.60, lon=1.44)
        for b in (80, 90, 100, 110, 120)
    ]
    quartier_ordinaire += [
        _house("F", b, 0, _prix_theorique(b, 0, -0.20), lat=43.60, lon=1.44) for b in (85, 95)
    ]
    quartier_cher = [
        _house("F", b, 0, _prix_theorique(b, 0, -0.20, facteur=1.4), lat=43.62, lon=1.44)
        for b in (80, 90, 100, 110, 120)
    ]
    quartier_cher += [
        _house("D", b, 0, _prix_theorique(b, 0, 0.0, facteur=1.4), lat=43.62, lon=1.44)
        for b in (85, 95)
    ]

    result = fit(quartier_ordinaire + quartier_cher, 43.61, 1.44)

    # Sans indice de quartier, F remonte vers 0 ; avec, il retrouve son effet propre.
    assert result.coef_dpe["F"] < -0.12

"""Tests de la régression pondérée (§5.2)."""

from __future__ import annotations

import math
from dataclasses import replace

import numpy as np
import pytest

from valeur_verte_data.dvf import Mutation, aggregate_mutations
from valeur_verte_data.regression import (
    InsufficientSampleError,
    _erreurs_types,
    _indice_local,
    _Row,
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


def _rows_spatiaux(n: int) -> list[_Row]:
    """Lignes réparties dans l'espace, prix/m² variés (indice local non trivial)."""
    return [
        _Row(
            prix=200000.0 + i * 1500,
            bati=80.0 + i,
            terrain=100.0,
            dpe="D",
            periode="1948-1974",
            insee="31555",
            annee="2024",
            lat=43.6 + 0.01 * i,
            lon=1.44 + 0.008 * i,
        )
        for i in range(n)
    ]


def test_indice_local_chunke_est_identique_sans_reference() -> None:
    # #218 : le chunking borne la mémoire (matrice par tranches) sans changer le
    # résultat — mêmes voisins, même médiane, exclusion diagonale préservée.
    rows = _rows_spatiaux(12)

    complet = _indice_local(rows, chunk=1000)
    morcele = _indice_local(rows, chunk=3)

    assert np.allclose(complet, morcele)


def test_indice_local_selectionne_le_plus_proche_en_excluant_soi() -> None:
    # Valeurs exactes : pin la formule de distance (somme lat²+lon², facteur 0,73),
    # l'exclusion de soi (diagonale) et la sélection du plus proche voisin.
    def _r(prix: float, lon: float) -> _Row:
        return _Row(
            prix=prix,
            bati=100.0,
            terrain=0.0,
            dpe="D",
            periode="",
            insee="x",
            annee="2024",
            lat=0.0,
            lon=lon,
        )

    rows = [_r(10000.0, 0.0), _r(20000.0, 0.01), _r(30000.0, 1.0)]  # prix/m² = 100, 200, 300

    indice = _indice_local(rows, voisins=1)

    # A(100) → plus proche B(200) ; B(200) → A(100) ; C(300) → B(200). Soi exclu.
    assert list(indice) == [200.0, 100.0, 200.0]


def test_indice_local_chunke_est_identique_avec_reference() -> None:
    apprentissage = _rows_spatiaux(15)
    test_rows = _rows_spatiaux(7)

    complet = _indice_local(test_rows, apprentissage, chunk=1000)
    morcele = _indice_local(test_rows, apprentissage, chunk=2)

    assert np.allclose(complet, morcele)


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


def _sample_premium_derivant() -> list[Mutation]:
    """Pool 2 millésimes où le **premium C dérive** : 2023 +0,05 → 2024 +0,15.

    Le niveau de prix de base est identique d'une année à l'autre (les effets
    fixes année restent neutres) ; seul l'écart C→D bouge. Un fit à poids égaux
    estime donc coef_C ≈ moyenne (~+0,10) ; la pondération temporelle vers 2024
    (#208) remonte l'estimation vers le premium récent (+0,15). Un INSEE distinct
    par année garde les ``id_mutation`` uniques (les fits ci-dessous désactivent
    les effets fixes commune pour ne pas confondre commune et année).
    """
    lignes: list[Mutation] = []
    for annee, premium_c, insee in ((2023, 0.05, "31555"), (2024, 0.15, "31556")):
        effets = {"D": 0.0, "F": -0.20, "C": premium_c}
        for dpe, effet in effets.items():
            for bati, terrain in _SURFACES:
                lignes.append(
                    _house(
                        dpe,
                        bati,
                        terrain,
                        _prix_theorique(bati, terrain, effet),
                        insee=insee,
                        year=annee,
                    )
                )
    return lignes


def test_fit_sans_ponderation_temporelle_est_non_regressif() -> None:
    # τ absent (défaut None) = comportement #177 exact : passer explicitement
    # decroissance_temporelle_tau=None ne change rien au résultat par défaut.
    sample = _sample_premium_derivant()
    reference = fit(sample, 43.6, 1.44, reference_dpe="D", effets_fixes_commune=False)
    explicite = fit(
        sample,
        43.6,
        1.44,
        reference_dpe="D",
        effets_fixes_commune=False,
        decroissance_temporelle_tau=None,
        annee_reference=2024,
    )
    assert explicite.coef_dpe == reference.coef_dpe


def test_fit_ponderation_temporelle_rapproche_du_premium_recent() -> None:
    # Avec décroissance vers 2024, coef_C se déplace de la moyenne (~+0,10) vers
    # le premium de l'année de référence (+0,15) : biais de retard réduit (#208).
    sample = _sample_premium_derivant()
    plat = fit(
        sample,
        43.6,
        1.44,
        reference_dpe="D",
        effets_fixes_commune=False,
        decroissance_temporelle_tau=None,
        annee_reference=2024,
    )
    recent = fit(
        sample,
        43.6,
        1.44,
        reference_dpe="D",
        effets_fixes_commune=False,
        decroissance_temporelle_tau=0.5,
        annee_reference=2024,
    )
    assert round(plat.coef_dpe["C"], 2) == 0.10
    assert recent.coef_dpe["C"] > plat.coef_dpe["C"]
    assert recent.coef_dpe["C"] > 0.12


def _sample_premium_derivant_bruite() -> list[Mutation]:
    """Comme ``_sample_premium_derivant`` mais avec un bruit multiplicatif ±8 %
    déterministe : le fit n'est plus parfait (r² < 1), donc le r² **dépend** de la
    pondération appliquée — permet de vérifier que le R² reflète bien le poids
    temporel (champ diagnostique retourné, #208).
    """
    lignes: list[Mutation] = []
    for annee, premium_c, insee in ((2023, 0.05, "31555"), (2024, 0.15, "31556")):
        effets = {"D": 0.0, "F": -0.20, "C": premium_c}
        for dpe, effet in effets.items():
            for i, (bati, terrain) in enumerate(_SURFACES):
                bruit = 1.08 if i % 2 == 0 else 0.92
                prix = round(_prix_theorique(bati, terrain, effet) * bruit)
                lignes.append(_house(dpe, bati, terrain, prix, insee=insee, year=annee))
    return lignes


def test_fit_ponderation_temporelle_influe_sur_le_r2() -> None:
    # Le r² est mesuré sur l'objectif pondéré ; il doit refléter le poids temporel
    # (sinon le diagnostic décrirait un autre estimateur que celui publié, #208).
    sample = _sample_premium_derivant_bruite()
    plat = fit(
        sample,
        43.6,
        1.44,
        reference_dpe="D",
        effets_fixes_commune=False,
        decroissance_temporelle_tau=None,
        annee_reference=2024,
    )
    recent = fit(
        sample,
        43.6,
        1.44,
        reference_dpe="D",
        effets_fixes_commune=False,
        decroissance_temporelle_tau=0.5,
        annee_reference=2024,
    )
    assert recent.r2 != plat.r2


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


# --- DAT/#249 lot 1 : écarts-types des coefficients (se_dpe) ---
#
# Le shrinkage hiérarchique (#249) pondère par la précision de chaque effet DPE :
# poids w = τ²/(τ²+SE²). Il faut donc exposer SE = √diag(σ̂²·(XᵀWX)⁻¹) depuis le fit.
# `_erreurs_types` est la brique pure (covariance MCO pondérés) ; on la teste à valeur
# connue, puis on vérifie que `fit` la remonte par classe dans `se_dpe`.


def _wls_beta(design: np.ndarray, y: np.ndarray, weights: np.ndarray) -> np.ndarray:
    """Solution MCO pondérés (design blanchi) — mêmes β̂ que `_fit_model`."""
    racine = np.sqrt(weights)
    beta, _, _, _ = np.linalg.lstsq(design * racine[:, None], y * racine, rcond=None)
    return beta


def test_erreurs_types_ols_simple_valeurs_connues() -> None:
    # Régression simple y = a + b·x, poids unitaires, résidus non nuls.
    # Calcul à la main : β̂=[0,8 ; 1,3], σ̂²=RSS/(n−p)=0,30/2=0,15,
    # (XᵀX)⁻¹ diag = [0,7 ; 0,2] ⇒ SE = √(0,15·[0,7 ; 0,2]) = [0,324037 ; 0,173205].
    design = np.array([[1.0, 0.0], [1.0, 1.0], [1.0, 2.0], [1.0, 3.0]])
    y = np.array([1.0, 2.0, 3.0, 5.0])
    weights = np.ones(4)
    beta = np.array([0.8, 1.3])

    se = _erreurs_types(design, y, weights, beta)

    assert se[0] == pytest.approx(math.sqrt(0.105))
    assert se[1] == pytest.approx(math.sqrt(0.03))


def test_erreurs_types_depend_des_poids() -> None:
    # Repondérer les observations change la covariance : l'estimateur pondéré et son
    # SE diffèrent du cas non pondéré (sinon le poids serait ignoré).
    design = np.array([[1.0, 0.0], [1.0, 1.0], [1.0, 2.0], [1.0, 3.0]])
    y = np.array([1.0, 2.0, 3.0, 5.0])
    plats = np.ones(4)
    pesants = np.array([1.0, 1.0, 1.0, 5.0])

    se_plats = _erreurs_types(design, y, plats, _wls_beta(design, y, plats))
    se_pesants = _erreurs_types(design, y, pesants, _wls_beta(design, y, pesants))

    assert not np.allclose(se_plats, se_pesants)


def test_erreurs_types_sans_degres_de_liberte_est_infini() -> None:
    # n ≤ p : aucun degré de liberté résiduel ⇒ σ̂² indéfini ⇒ SE = +∞. C'est le signal
    # « aucune information » que le shrinkage traduit en poids nul (tiré vers le prior).
    design = np.array([[1.0, 0.0], [1.0, 1.0]])  # n = p = 2
    y = np.array([1.0, 2.0])
    weights = np.ones(2)
    beta = np.array([1.0, 1.0])

    se = _erreurs_types(design, y, weights, beta)

    assert np.isinf(se).all()


def test_erreurs_types_gere_la_quasi_singularite() -> None:
    # Colonne colinéaire (XᵀWX singulière) : `pinv` (pas `inv`) ne lève pas et rend des
    # SE finis (pseudo-inverse de norme minimale). Robustesse petit-échantillon rural.
    design = np.array([[1.0, 2.0], [1.0, 2.0], [1.0, 2.0], [1.0, 2.0], [1.0, 2.0]])
    y = np.array([1.0, 2.0, 3.0, 2.0, 2.0])
    weights = np.ones(5)
    beta = _wls_beta(design, y, weights)

    se = _erreurs_types(design, y, weights, beta)

    assert np.all(np.isfinite(se))


def _echantillon_bruite(n_par_classe: int, insee: str = "31555") -> list[Mutation]:
    """Maisons D et F avec bruit **déterministe** (±8 %) → variance résiduelle > 0."""
    maisons: list[Mutation] = []
    for classe, effet in (("D", 0.0), ("F", -0.20)):
        for i in range(n_par_classe):
            bati = 80 + (i % 5) * 10
            terrain = 200 + (i % 4) * 50
            base = _prix_theorique(bati, terrain, effet)
            prix = round(base * (1 + 0.08 * ((i % 3) - 1)))
            maisons.append(
                _house(
                    classe,
                    bati,
                    terrain,
                    prix,
                    insee=insee,
                    lat=43.6 + 0.01 * i,
                    lon=1.44 + 0.008 * i,
                )
            )
    return maisons


def test_se_dpe_a_les_memes_classes_que_coef_dpe() -> None:
    # se_dpe est parallèle à coef_dpe (mêmes classes) ; la référence D est l'ancre
    # (effet 0, SE 0) — jamais shrinkée.
    result = fit(_echantillon_bruite(8), 43.6, 1.44, reference_dpe="D")

    assert set(result.se_dpe) == set(result.coef_dpe)
    assert result.se_dpe["D"] == 0.0


def test_se_dpe_fini_et_positif_sur_donnees_bruitees() -> None:
    # Avec des degrés de liberté et de la variance résiduelle, la SE d'une classe
    # estimée est un réel strictement positif.
    result = fit(_echantillon_bruite(8), 43.6, 1.44, reference_dpe="D")

    assert 0.0 < result.se_dpe["F"] < math.inf


def test_se_dpe_diminue_avec_plus_de_donnees() -> None:
    # La précision (⇒ SE plus petite) est le cœur du shrinkage : plus de ventes pour
    # une classe ⇒ effet mieux estimé ⇒ SE plus faible.
    petit = fit(_echantillon_bruite(6), 43.6, 1.44, reference_dpe="D")
    grand = fit(_echantillon_bruite(24), 43.6, 1.44, reference_dpe="D")

    assert grand.se_dpe["F"] < petit.se_dpe["F"]


def test_se_dpe_infini_sans_degres_de_liberte() -> None:
    # Échantillon minuscule (n ≤ nombre de colonnes du design) : SE = +∞, la référence
    # D restant à 0. Le shrinkage tirera alors entièrement la maille vers le prior.
    minuscule = [
        _house("D", 100, 300, 132_000),
        _house("F", 100, 300, 108_000),
    ]

    result = fit(minuscule, 43.6, 1.44, reference_dpe="D")

    assert result.se_dpe["D"] == 0.0
    assert math.isinf(result.se_dpe["F"])

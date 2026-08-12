"""Tests du pré-calcul par maille (compose rayon/régression/CV/mode) — §10.6."""

from __future__ import annotations

import math
from dataclasses import replace

import pytest

from valeur_verte_data.batch import MailleCoefficient, compute_communes, compute_maille
from valeur_verte_data.config import CalculConfig
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.dvf import Mutation, aggregate_mutations

# Effets en logarithme : le modèle est log-linéaire (#115).
_EFFECT = {"D": 0.0, "F": -0.20, "C": 0.10}


def _house(dpe: str, bati: int, prix: int, *, lat: float, lon: float) -> Mutation:
    (base,) = aggregate_mutations(
        [
            {
                "id_mutation": f"{dpe}-{bati}-{prix}-{lat}",
                "valeur_fonciere": str(prix),
                "code_commune": "31555",
                "surface_reelle_bati": str(bati),
                "latitude": str(lat),
                "longitude": str(lon),
            }
        ],
        millesime=2024,
    )
    return replace(base, etiquette_dpe=dpe, periode_construction="1948-1974")


def _cluster(count_per_class: int, *, lat: float, lon: float) -> list[Mutation]:
    houses: list[Mutation] = []
    for dpe, effect in _EFFECT.items():
        for i in range(count_per_class):
            bati = 80 + 5 * i
            prix = round(3000.0 * bati**0.9 * math.exp(effect))
            houses.append(_house(dpe, bati, prix, lat=lat, lon=lon))
    return houses


def test_le_bati_recent_entre_dans_le_calcul_si_la_restriction_est_levee() -> None:
    """S20 : sans la restriction < 1989, les ventes récentes alimentent la régression.

    Un E récent existe et doit compter ; le supprimer est une erreur. Le drapeau
    ``restreindre_bati_ancien`` de la config le pilote de bout en bout.
    """
    anciennes = _cluster(4, lat=43.6, lon=1.44)  # période 1948-1974
    recentes = [
        replace(vente, id_mutation=f"recent-{i}", periode_construction="2006-2012")
        for i, vente in enumerate(_cluster(4, lat=43.6, lon=1.44))
    ]
    pool = anciennes + recentes
    base = replace(CalculConfig(), min_local_sample=10, min_sample=10, min_class_sample=1)

    stricte = compute_maille("31555", 43.6, 1.44, 2024, pool, config=base)
    large = compute_maille(
        "31555", 43.6, 1.44, 2024, pool, config=replace(base, restreindre_bati_ancien=False)
    )

    assert stricte is not None and large is not None
    assert stricte.n_transactions == len(anciennes)
    assert large.n_transactions == len(anciennes) + len(recentes)


def _pool_etale() -> list[Mutation]:
    """Deux grappes à ~8 km : F fortement décoté au centre, faiblement au loin.

    L'écart entre les deux fait que σ (combien le lointain compte) change le
    coefficient F — condition pour que le σ adaptatif soit observable.
    """
    houses: list[Mutation] = []
    for i in range(6):
        bati = 90 + i
        houses.append(_house("D", bati, round(3000 * bati), lat=43.6, lon=1.44))
        houses.append(_house("F", bati, round(3000 * bati * math.exp(-0.30)), lat=43.6, lon=1.44))
        houses.append(_house("D", bati, round(3000 * bati), lat=43.672, lon=1.44))
        houses.append(_house("F", bati, round(3000 * bati * math.exp(-0.05)), lat=43.672, lon=1.44))
    return houses


def test_sigma_adaptatif_equivaut_a_sigma_fixe_rayon_sur_diviseur() -> None:
    """S23 : en mode local, σ adaptatif = rayon_retenu / diviseur.

    Preuve par équivalence : la maille calculée avec σ adaptatif (diviseur 2, rayon
    10 → σ=5) est identique à celle avec σ fixe = 5, et diffère du σ fixe par défaut.
    """
    pool = _pool_etale()  # rayon retenu = 10 km
    base = replace(CalculConfig(), min_local_sample=10, min_sample=10, min_class_sample=1)

    adaptatif = compute_maille(
        "31555",
        43.6,
        1.44,
        2024,
        pool,
        config=replace(base, sigma_adaptatif=True, sigma_diviseur=2.0),
    )
    fixe = compute_maille("31555", 43.6, 1.44, 2024, pool, config=replace(base, sigma_km=5.0))
    defaut = compute_maille("31555", 43.6, 1.44, 2024, pool, config=base)

    assert adaptatif is not None and fixe is not None and defaut is not None
    assert adaptatif.rayon_retenu_km == 10
    assert adaptatif.coef_dpe == fixe.coef_dpe  # σ = 10/2 = 5
    assert adaptatif.coef_dpe != defaut.coef_dpe  # ≠ σ fixe par défaut (10)


def test_compute_maille_lit_les_seuils_de_la_config() -> None:
    """La config descend jusqu'au calcul : durcir un seuil change le résultat.

    Preuve de câblage : avec ``min_class_sample=1`` les trois classes du cluster
    (4 ventes chacune) sont publiées ; à 5, aucune n'atteint le seuil et la maille
    est refusée. Le paramètre vient donc bien de la config, pas d'une constante.
    """
    pool = _cluster(4, lat=43.6, lon=1.44)  # 3 classes × 4 ventes
    base = replace(CalculConfig(), min_local_sample=10, min_sample=10)

    assert (
        compute_maille("31555", 43.6, 1.44, 2024, pool, config=replace(base, min_class_sample=1))
        is not None
    )
    assert (
        compute_maille("31555", 43.6, 1.44, 2024, pool, config=replace(base, min_class_sample=5))
        is None
    )


def test_compute_maille_mode_local() -> None:
    pool = _cluster(4, lat=43.6, lon=1.44)  # 12 ventes proches

    # min_class_sample=1 : ce test porte sur le mode/la pondération/l'IC, pas sur
    # le seuil de publication par classe (couvert par son propre test).
    maille = compute_maille(
        "31555",
        43.6,
        1.44,
        2024,
        pool,
        config=replace(CalculConfig(), min_local_sample=10, min_sample=10, min_class_sample=1),
    )

    assert isinstance(maille, MailleCoefficient)
    assert maille.mode is CoverageMode.LOCAL
    assert maille.code_insee == "31555"
    assert maille.millesime == 2024
    assert maille.rayon_retenu_km == 10
    assert maille.n_transactions == 12
    assert round(maille.coef_dpe["F"], 2) == -0.20
    assert round(maille.coef_m2_bati, 2) == 0.90  # élasticité, non prix au m²
    assert maille.coef_m2_terrain is not None
    assert maille.r2 > 0.99
    assert maille.erreur_mediane is not None
    assert maille.alerte is None
    # IC empirique calibré sur l'erreur médiane (§5.4).
    demi = abs(maille.coef_dpe["F"]) * maille.erreur_mediane / 100.0
    # Tolérance plutôt qu'égalité stricte : les deux membres suivent des chemins
    # de calcul flottant distincts et diffèrent au dernier bit selon la version
    # de Python. Ce qui est testé, c'est la calibration sur l'erreur médiane.
    assert maille.ic_dpe["F"] == pytest.approx(
        (maille.coef_dpe["F"] - demi, maille.coef_dpe["F"] + demi)
    )


def test_compute_maille_mode_departemental() -> None:
    proches = _cluster(2, lat=43.6, lon=1.44)  # 6 proches (< seuil)
    lointaines = _cluster(7, lat=44.6, lon=1.44)  # ~111 km, hors rayon mais dans le pool
    pool = proches + lointaines

    # min_class_sample=1 : ce test porte sur le mode/la pondération/l'IC, pas sur
    # le seuil de publication par classe (couvert par son propre test).
    maille = compute_maille(
        "31555",
        43.6,
        1.44,
        2024,
        pool,
        config=replace(CalculConfig(), min_local_sample=10, min_sample=10, min_class_sample=1),
    )

    assert maille is not None
    assert maille.mode is CoverageMode.DEPARTEMENTAL
    assert maille.rayon_retenu_km is None
    assert maille.alerte is not None


def test_le_repli_departemental_ignore_les_departements_limitrophes() -> None:
    """Le bandeau promet une moyenne départementale : elle doit en être une (#91).

    Depuis que le pool déborde sur les voisins (#18), ``sample = pool`` en mode
    dégradé servirait une moyenne sur sept départements sous un bandeau qui
    annonce le seul département — une commune du Comminges recevrait une valeur
    tirée de l'Aude et du Tarn. Le débordement est fait pour nourrir le calcul
    *local*, pas pour redéfinir ce qu'est un département.
    """
    proches = _cluster(2, lat=43.6, lon=1.44)  # 6 ventes : sous le seuil local
    lointaines_31 = _cluster(7, lat=44.6, lon=1.44)  # 21 ventes du 31, hors rayon
    voisines_09 = [
        replace(vente, id_mutation=f"09-{i}", code_insee="09122")
        for i, vente in enumerate(_cluster(7, lat=42.9, lon=1.6))
    ]

    maille = compute_maille(
        "31555",
        43.6,
        1.44,
        2024,
        proches + lointaines_31 + voisines_09,
        config=replace(CalculConfig(), min_local_sample=10, min_sample=10, min_class_sample=1),
    )

    assert maille is not None
    assert maille.mode is CoverageMode.DEPARTEMENTAL
    assert maille.n_transactions == len(proches) + len(lointaines_31), (
        "le repli doit compter les seules ventes du 31, "
        f"pas les {len(voisines_09)} ventes ariégeoises du pool"
    )


def test_compute_maille_refus_si_tout_insuffisant() -> None:
    pool = _cluster(2, lat=43.6, lon=1.44)  # 6 ventes, département aussi insuffisant

    assert (
        compute_maille(
            "31555",
            43.6,
            1.44,
            2024,
            pool,
            config=replace(CalculConfig(), min_local_sample=10, min_sample=10),
        )
        is None
    )


def test_compute_communes_produit_une_ligne_par_commune_calculable() -> None:
    pool = _cluster(4, lat=43.6, lon=1.44)

    # min_class_sample=1 : ce test porte sur la production d'une ligne par commune,
    # pas sur le seuil de publication par classe.
    results = list(
        compute_communes(
            [("31555", 43.6, 1.44)],
            pool,
            2024,
            config=replace(CalculConfig(), min_local_sample=10, min_sample=10, min_class_sample=1),
        )
    )

    assert len(results) == 1
    assert results[0].mode is CoverageMode.LOCAL
    assert results[0].code_insee == "31555"
    assert results[0].millesime == 2024
    assert results[0].rayon_retenu_km == 10


def test_compute_communes_saute_les_refus() -> None:
    pool = _cluster(2, lat=43.6, lon=1.44)  # 6 ventes < seuil → refus partout

    results = compute_communes(
        [("31555", 43.6, 1.44)],
        pool,
        2024,
        config=replace(CalculConfig(), min_local_sample=10, min_sample=10),
    )

    assert list(results) == []


def _cluster_effet(effet_f: int, *, lat: float, lon: float) -> list[Mutation]:
    houses: list[Mutation] = []
    for i in range(7):
        bati = 80 + 5 * i
        houses.append(_house("D", bati, 1000 * bati, lat=lat, lon=lon))
        houses.append(_house("F", bati, 1000 * bati + effet_f, lat=lat, lon=lon))
    return houses


def test_compute_maille_departemental_pondere_tout_le_pool() -> None:
    # Proches : F = -40 000. Lointaines (~111 km) : F = 0. Le mode départemental
    # (grand σ) doit compter les deux → F nettement > -40 000 (sinon = seul le local).
    proches = _cluster_effet(-40000, lat=43.6, lon=1.44)  # hors rayon ? non : dans le rayon
    lointaines = _cluster_effet(0, lat=44.6, lon=1.44)
    pool = proches[:6] + lointaines  # 6 proches (< seuil) + 14 lointaines

    # min_class_sample=1 : ce test porte sur le mode/la pondération/l'IC, pas sur
    # le seuil de publication par classe (couvert par son propre test).
    maille = compute_maille(
        "31555",
        43.6,
        1.44,
        2024,
        pool,
        config=replace(CalculConfig(), min_local_sample=10, min_sample=10, min_class_sample=1),
    )

    assert maille is not None
    assert maille.mode is CoverageMode.DEPARTEMENTAL
    assert maille.coef_dpe["F"] > -20000


def test_compute_maille_ic_calibre_sur_erreur_non_nulle() -> None:
    pool: list[Mutation] = []
    for dpe, effect in _EFFECT.items():
        for i in range(5):
            bati = 80 + 5 * i
            noise = 6000 if i % 2 == 0 else -6000
            pool.append(_house(dpe, bati, 1000 * bati + effect + noise, lat=43.6, lon=1.44))

    # min_class_sample=1 : ce test porte sur le mode/la pondération/l'IC, pas sur
    # le seuil de publication par classe (couvert par son propre test).
    maille = compute_maille(
        "31555",
        43.6,
        1.44,
        2024,
        pool,
        config=replace(CalculConfig(), min_local_sample=10, min_sample=10, min_class_sample=1),
    )

    assert maille is not None
    assert maille.erreur_mediane is not None and maille.erreur_mediane > 0
    demi = abs(maille.coef_dpe["F"]) * maille.erreur_mediane / 100.0
    # Tolérance plutôt qu'égalité stricte : les deux membres suivent des chemins
    # de calcul flottant distincts et diffèrent au dernier bit selon la version
    # de Python. Ce qui est testé, c'est la calibration sur l'erreur médiane.
    assert maille.ic_dpe["F"] == pytest.approx(
        (maille.coef_dpe["F"] - demi, maille.coef_dpe["F"] + demi)
    )


def _maison_classe(dpe: str, numero: int) -> Mutation:
    """Maison ancienne d'une classe donnée, prix cohérent avec l'effet attendu."""
    return _house(
        dpe, 80 + numero % 7, 200_000 + _EFFECT.get(dpe, 0) + 900 * numero, lat=43.6, lon=1.44
    )


def test_une_classe_trop_peu_observee_n_est_pas_publiee() -> None:
    """Seuil de publication par classe (#115) : 14 maisons classées A sur tout le 31.

    Une commune n'en observe souvent aucune ; le coefficient serait alors
    extrapolé sur deux ou trois ventes, sans que l'intervalle de confiance —
    calibré sur l'erreur médiane globale — ne le signale.
    """
    abondantes = [_maison_classe(dpe, i) for dpe in ("D", "C", "F") for i in range(20)]
    rares = [_maison_classe("A", i) for i in range(3)]

    maille = compute_maille("31555", 43.6, 1.44, 2024, [*abondantes, *rares])

    assert maille is not None
    assert "A" not in maille.coef_dpe, "une classe sous le seuil ne doit pas être publiée"
    assert "A" not in maille.ic_dpe, "son intervalle de confiance non plus"
    assert {"D", "C", "F"} <= set(maille.coef_dpe)


def test_les_ventes_des_classes_rares_alimentent_quand_meme_la_regression() -> None:
    """Elles restent utiles pour estimer les coefficients de surface."""
    abondantes = [_maison_classe(dpe, i) for dpe in ("D", "C", "F") for i in range(20)]
    rares = [_maison_classe("A", i) for i in range(3)]

    maille = compute_maille("31555", 43.6, 1.44, 2024, [*abondantes, *rares])

    assert maille is not None
    assert maille.n_transactions == len(abondantes) + len(rares)


def test_une_maille_sans_classe_exploitable_est_refusee() -> None:
    """Un coefficient qui ne porte aucune comparaison ne sert à rien (#115).

    Après application du seuil par classe, 101 mailles du département 31 ne
    publiaient plus aucune classe et 102 n'en publiaient qu'une : sans au moins
    la référence et une autre classe, aucun écart de valeur n'est calculable.
    Mieux vaut refuser, comme sous le seuil d'échantillon (§7).
    """
    # Assez de ventes pour dépasser le seuil global, mais dispersées sur des
    # classes trop peu observées pour être publiées.
    pool = [_maison_classe(dpe, i) for dpe in "ABCDEFG" for i in range(5)]

    maille = compute_maille("31555", 43.6, 1.44, 2024, pool)

    assert maille is None


def test_une_maille_avec_la_seule_reference_est_refusee() -> None:
    """La référence vaut 0 par construction : seule, elle ne dit rien."""
    pool = [_maison_classe("D", i) for i in range(40)]

    maille = compute_maille("31555", 43.6, 1.44, 2024, pool)

    assert maille is None

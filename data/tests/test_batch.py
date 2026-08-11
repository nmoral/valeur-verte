"""Tests du pré-calcul par maille (compose rayon/régression/CV/mode) — §10.6."""

from __future__ import annotations

from dataclasses import replace

from valeur_verte_data.batch import MailleCoefficient, compute_communes, compute_maille
from valeur_verte_data.degraded import CoverageMode
from valeur_verte_data.dvf import Mutation, aggregate_mutations

_EFFECT = {"D": 0, "F": -25000, "C": 15000}


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
            houses.append(_house(dpe, bati, 1000 * bati + effect, lat=lat, lon=lon))
    return houses


def test_compute_maille_mode_local() -> None:
    pool = _cluster(4, lat=43.6, lon=1.44)  # 12 ventes proches

    maille = compute_maille("31555", 43.6, 1.44, 2024, pool, seuil=10, min_sample=10)

    assert isinstance(maille, MailleCoefficient)
    assert maille.mode is CoverageMode.LOCAL
    assert maille.code_insee == "31555"
    assert maille.rayon_retenu_km == 10
    assert maille.n_transactions == 12
    assert round(maille.coef_dpe["F"]) == -25000
    assert maille.erreur_mediane is not None
    assert maille.alerte is None
    assert "F" in maille.ic_dpe


def test_compute_maille_mode_departemental() -> None:
    proches = _cluster(2, lat=43.6, lon=1.44)  # 6 proches (< seuil)
    lointaines = _cluster(7, lat=44.6, lon=1.44)  # ~111 km, hors rayon mais dans le pool
    pool = proches + lointaines

    maille = compute_maille("31555", 43.6, 1.44, 2024, pool, seuil=10, min_sample=10)

    assert maille is not None
    assert maille.mode is CoverageMode.DEPARTEMENTAL
    assert maille.rayon_retenu_km is None
    assert maille.alerte is not None


def test_compute_maille_refus_si_tout_insuffisant() -> None:
    pool = _cluster(2, lat=43.6, lon=1.44)  # 6 ventes, département aussi insuffisant

    assert compute_maille("31555", 43.6, 1.44, 2024, pool, seuil=10, min_sample=10) is None


def test_compute_communes_produit_une_ligne_par_commune_calculable() -> None:
    pool = _cluster(4, lat=43.6, lon=1.44)

    results = list(compute_communes([("31555", 43.6, 1.44)], pool, 2024, seuil=10, min_sample=10))

    assert len(results) == 1
    assert results[0].mode is CoverageMode.LOCAL


def test_compute_communes_saute_les_refus() -> None:
    pool = _cluster(2, lat=43.6, lon=1.44)  # 6 ventes < seuil → refus partout

    assert list(compute_communes([("31555", 43.6, 1.44)], pool, 2024, seuil=10, min_sample=10)) == []

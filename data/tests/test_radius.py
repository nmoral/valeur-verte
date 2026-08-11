"""Tests de la sélection par rayon adaptatif (§5.1, haversine, hors-ligne)."""

from __future__ import annotations

from valeur_verte_data.dvf import Mutation, aggregate_mutations
from valeur_verte_data.radius import adaptive_selection, haversine_km


def _at(lat: float, lon: float) -> Mutation:
    row = {
        "id_mutation": f"{lat}:{lon}",
        "valeur_fonciere": "200000",
        "code_commune": "31555",
        "surface_reelle_bati": "80",
        "latitude": str(lat),
        "longitude": str(lon),
    }
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


def _sans_coord() -> Mutation:
    row = {
        "id_mutation": "sans-coord",
        "valeur_fonciere": "200000",
        "code_commune": "31555",
        "surface_reelle_bati": "80",
    }
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


def _lat_seule(lat: float) -> Mutation:
    row = {
        "id_mutation": "lat-seule",
        "valeur_fonciere": "200000",
        "code_commune": "31555",
        "surface_reelle_bati": "80",
        "latitude": str(lat),
    }
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


def test_haversine_un_degre_de_latitude_vaut_environ_111km() -> None:
    assert round(haversine_km(0.0, 0.0, 1.0, 0.0)) == 111


def test_haversine_symetrique_et_nul_sur_place() -> None:
    assert haversine_km(43.6, 1.44, 43.6, 1.44) == 0.0


def test_haversine_un_degre_de_longitude_a_l_equateur() -> None:
    assert round(haversine_km(0.0, 0.0, 0.0, 1.0)) == 111


def test_haversine_longitude_a_45_degres_de_latitude() -> None:
    # 1° de longitude « rétréci » par le cos(latitude) ≈ 79 km à 45°.
    assert round(haversine_km(45.0, 0.0, 45.0, 1.0)) == 79


def test_adaptive_prend_le_plus_petit_rayon_suffisant() -> None:
    proches = [_at(43.6, 1.44), _at(43.61, 1.44)]  # 0 et ~1,1 km
    lointains = [_at(43.71, 1.44), _at(43.72, 1.44)]  # ~12-13 km

    selection = adaptive_selection(43.6, 1.44, proches + lointains, min_sample=2)

    assert selection.suffisant
    assert selection.rayon_km == 10
    assert len(selection.sample) == 2


def test_adaptive_elargit_le_rayon_si_besoin() -> None:
    points = [_at(43.6, 1.44), _at(43.605, 1.44), _at(43.71, 1.44)]  # 0, ~0,55, ~12,2 km

    selection = adaptive_selection(43.6, 1.44, points, min_sample=3)

    assert selection.suffisant
    assert selection.rayon_km == 15
    assert len(selection.sample) == 3


def test_adaptive_insuffisant_a_50km_signale_le_repli() -> None:
    selection = adaptive_selection(43.6, 1.44, [_at(43.6, 1.44)], min_sample=3)

    assert selection.suffisant is False
    assert selection.rayon_km == 50
    assert len(selection.sample) == 1


def test_adaptive_ignore_les_coordonnees_absentes() -> None:
    selection = adaptive_selection(43.6, 1.44, [_sans_coord()], min_sample=1)

    assert selection.sample == []
    assert selection.suffisant is False


def test_adaptive_ignore_une_coordonnee_partielle() -> None:
    selection = adaptive_selection(43.6, 1.44, [_lat_seule(43.6)], min_sample=1)

    assert selection.sample == []


def test_adaptive_continue_apres_une_coordonnee_absente() -> None:
    selection = adaptive_selection(43.6, 1.44, [_sans_coord(), _at(43.6, 1.44)], min_sample=1)

    assert selection.suffisant is True
    assert len(selection.sample) == 1

"""Tests du nettoyage des transactions (bornes §7, hors-ligne)."""

from __future__ import annotations

from valeur_verte_data.cleaning import clean_mutations, is_clean
from valeur_verte_data.dvf import Mutation, aggregate_mutations


def _mutation(prix: str = "200000", bati: str = "80", terrain: str | None = None) -> Mutation:
    row = {
        "id_mutation": "m",
        "valeur_fonciere": prix,
        "code_commune": "31555",
        "surface_reelle_bati": bati,
    }
    if terrain is not None:
        row["surface_terrain"] = terrain
        row["nature_culture"] = "S"
        row["id_parcelle"] = "p1"
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


def test_is_clean_accepte_dans_les_bornes() -> None:
    assert is_clean(_mutation(prix="200000", bati="80"))


def test_is_clean_rejette_prix_hors_bornes() -> None:
    assert not is_clean(_mutation(prix="20000"))
    assert not is_clean(_mutation(prix="1000000"))


def test_is_clean_rejette_surface_bati_hors_bornes() -> None:
    assert not is_clean(_mutation(bati="40"))
    assert not is_clean(_mutation(bati="300"))


def test_is_clean_terrain_hors_bornes() -> None:
    assert not is_clean(_mutation(terrain="10"))
    assert not is_clean(_mutation(terrain="6000"))
    assert is_clean(_mutation(terrain="500"))


def test_is_clean_terrain_absent_est_accepte() -> None:
    assert is_clean(_mutation(terrain=None))


def test_is_clean_rejette_valeurs_manquantes() -> None:
    assert not is_clean(_mutation(prix=""))
    assert not is_clean(_mutation(bati=""))


def test_clean_mutations_filtre_les_aberrantes() -> None:
    mutations = [_mutation(prix="200000"), _mutation(prix="10")]

    assert len(clean_mutations(mutations)) == 1

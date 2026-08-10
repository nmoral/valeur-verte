"""Tests de l'agrégation des lignes DVF en mutations (§7). Logique pure."""

from __future__ import annotations

from valeur_verte_data.dvf import Mutation, aggregate_mutations


def _row(**overrides: str) -> dict[str, str]:
    row = {
        "id_mutation": "2024-1",
        "date_mutation": "2024-03-01",
        "nature_mutation": "Vente",
        "valeur_fonciere": "300000",
        "code_commune": "31555",
        "code_postal": "31000",
        "nom_commune": "Toulouse",
        "type_local": "Maison",
        "nombre_pieces_principales": "4",
        "surface_reelle_bati": "80",
        "surface_terrain": "",
        "id_parcelle": "31555000AA0001",
        "nature_culture": "S",
        "adresse_numero": "12",
        "adresse_suffixe": "",
        "adresse_nom_voie": "RUE DES FLEURS",
        "longitude": "1.44",
        "latitude": "43.60",
    }
    row.update(overrides)
    return row


def test_agrege_les_lots_en_une_seule_mutation() -> None:
    rows = [
        _row(surface_reelle_bati="80"),
        _row(surface_reelle_bati="20", id_parcelle="31555000AA0002"),
    ]

    mutations = aggregate_mutations(rows, millesime=2024)

    assert len(mutations) == 1
    (mutation,) = mutations
    assert isinstance(mutation, Mutation)
    assert mutation.id_mutation == "2024-1"
    assert mutation.code_insee == "31555"
    assert mutation.millesime == 2024


def test_le_prix_de_la_mutation_est_compte_une_seule_fois() -> None:
    rows = [_row(valeur_fonciere="300000"), _row(valeur_fonciere="300000")]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.prix == 300000.0


def test_la_surface_batie_est_la_somme_des_lots() -> None:
    rows = [
        _row(surface_reelle_bati="80"),
        _row(surface_reelle_bati="35", id_parcelle="31555000AA0002"),
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_bati == 115.0


def test_les_champs_scalaires_viennent_du_plus_grand_local() -> None:
    rows = [
        _row(
            surface_reelle_bati="30",
            type_local="Appartement",
            longitude="9.9",
            latitude="0.0",
        ),
        _row(
            surface_reelle_bati="90",
            type_local="Maison",
            longitude="1.44",
            latitude="43.60",
            id_parcelle="31555000AA0002",
        ),
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.type_local == "Maison"
    assert mutation.longitude == 1.44
    assert mutation.latitude == 43.60


def test_les_mutations_distinctes_sont_separees() -> None:
    rows = [_row(id_mutation="2024-1"), _row(id_mutation="2024-2")]

    mutations = aggregate_mutations(rows, millesime=2024)

    assert {m.id_mutation for m in mutations} == {"2024-1", "2024-2"}


def test_le_terrain_somme_les_parcelles_distinctes() -> None:
    # surface_terrain est répétée sur chaque ligne d'une même parcelle.
    rows = [
        _row(id_parcelle="P1", surface_terrain="500", nature_culture="S"),
        _row(id_parcelle="P1", surface_terrain="500", nature_culture="S"),
        _row(id_parcelle="P2", surface_terrain="300", nature_culture="S"),
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_terrain == 800.0


def test_le_terrain_ignore_les_natures_non_sol() -> None:
    rows = [
        _row(id_parcelle="P1", surface_terrain="500", nature_culture="S"),
        _row(id_parcelle="P2", surface_terrain="1000", nature_culture="T"),
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_terrain == 500.0


def test_le_terrain_absent_vaut_none() -> None:
    rows = [_row(surface_terrain=""), _row(surface_terrain="")]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_terrain is None

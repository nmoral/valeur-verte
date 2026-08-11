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
        "code_nature_culture": "S",
        "nature_culture": "sols",
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
        _row(
            id_parcelle="P1", surface_terrain="500", code_nature_culture="S", nature_culture="sols"
        ),
        _row(
            id_parcelle="P1", surface_terrain="500", code_nature_culture="S", nature_culture="sols"
        ),
        _row(
            id_parcelle="P2", surface_terrain="300", code_nature_culture="S", nature_culture="sols"
        ),
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_terrain == 800.0


def test_le_terrain_ignore_les_natures_non_sol() -> None:
    rows = [
        _row(
            id_parcelle="P1", surface_terrain="500", code_nature_culture="S", nature_culture="sols"
        ),
        _row(
            id_parcelle="P2",
            surface_terrain="1000",
            code_nature_culture="T",
            nature_culture="terres",
        ),
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_terrain == 500.0


def test_le_terrain_absent_vaut_none() -> None:
    rows = [_row(surface_terrain=""), _row(surface_terrain="")]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_terrain is None


def test_mappe_tous_les_champs_d_un_lot_unique() -> None:
    rows = [
        _row(
            id_mutation="2024-99",
            date_mutation="2024-07-15",
            valeur_fonciere="250000",
            code_commune="31069",
            code_postal="31200",
            nom_commune="Blagnac",
            type_local="Maison",
            nombre_pieces_principales="5",
            surface_reelle_bati="100",
            surface_terrain="400",
            id_parcelle="31069000ZZ0009",
            code_nature_culture="S",
            nature_culture="sols",
            adresse_numero="7",
            adresse_suffixe="bis",
            adresse_nom_voie="RUE DU TEST",
            longitude="1.39",
            latitude="43.63",
        )
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation == Mutation(
        id_mutation="2024-99",
        date_mutation="2024-07-15",
        millesime=2024,
        code_insee="31069",
        code_postal="31200",
        nom_commune="Blagnac",
        type_local="Maison",
        nombre_pieces_principales=5,
        prix=250000.0,
        surface_bati=100.0,
        surface_terrain=400.0,
        prix_m2=2500.0,
        adresse_numero="7",
        adresse_suffixe="bis",
        adresse_nom_voie="RUE DU TEST",
        longitude=1.39,
        latitude=43.63,
    )


def test_prix_m2_vaut_prix_sur_surface_batie() -> None:
    rows = [_row(valeur_fonciere="300000", surface_reelle_bati="120")]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.prix_m2 == 2500.0


def test_prix_m2_est_none_sans_prix() -> None:
    rows = [_row(valeur_fonciere="", surface_reelle_bati="100")]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.prix is None
    assert mutation.prix_m2 is None


def test_prix_m2_est_none_sans_surface_batie() -> None:
    rows = [_row(surface_reelle_bati="")]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_bati is None
    assert mutation.prix_m2 is None


def test_valeur_fonciere_non_numerique_ignoree() -> None:
    rows = [_row(valeur_fonciere="abc")]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.prix is None


def test_les_valeurs_vides_deviennent_none() -> None:
    rows = [_row(nom_commune="", adresse_suffixe="")]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.nom_commune is None
    assert mutation.adresse_suffixe is None


def test_le_terrain_ignore_les_parcelles_sans_identifiant() -> None:
    rows = [
        _row(id_parcelle="", surface_terrain="500", code_nature_culture="S", nature_culture="sols")
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_terrain is None


def test_les_lignes_sans_id_mutation_sont_ignorees() -> None:
    rows = [_row(id_mutation=""), _row(id_mutation="2024-1")]

    mutations = aggregate_mutations(rows, millesime=2024)

    assert [m.id_mutation for m in mutations] == ["2024-1"]


def test_prix_m2_est_arrondi_a_deux_decimales() -> None:
    rows = [_row(valeur_fonciere="250000", surface_reelle_bati="120")]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.prix_m2 == 2083.33  # 250000 / 120 = 2083.3333…


def test_code_commune_absent_donne_un_code_insee_vide() -> None:
    row = _row()
    del row["code_commune"]

    (mutation,) = aggregate_mutations([row], millesime=2024)

    assert mutation.code_insee == ""


def test_le_plus_grand_local_l_emporte_meme_a_surface_nulle() -> None:
    rows = [
        _row(surface_reelle_bati="0", type_local="Maison"),
        _row(surface_reelle_bati="", type_local="Appartement", id_parcelle="P2"),
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.type_local == "Maison"


def test_le_terrain_continue_apres_une_parcelle_non_sol() -> None:
    rows = [
        _row(
            id_parcelle="P0",
            surface_terrain="1000",
            code_nature_culture="T",
            nature_culture="terres",
        ),
        _row(
            id_parcelle="P1", surface_terrain="300", code_nature_culture="S", nature_culture="sols"
        ),
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_terrain == 300.0


def test_le_terrain_continue_apres_une_parcelle_sans_surface() -> None:
    rows = [
        _row(id_parcelle="", surface_terrain="500", code_nature_culture="S", nature_culture="sols"),
        _row(
            id_parcelle="P1", surface_terrain="300", code_nature_culture="S", nature_culture="sols"
        ),
    ]

    (mutation,) = aggregate_mutations(rows, millesime=2024)

    assert mutation.surface_terrain == 300.0


def test_surface_terrain_lue_au_format_reel_du_csv() -> None:
    """Le CSV DVF porte le libellé dans `nature_culture` et le code ailleurs (#136).

    La colonne `nature_culture` contient « sols », « jardins », « terres »… ;
    le code `S` vit dans `code_nature_culture`. Filtrer sur le libellé attendu
    `"S"` ne matchait jamais, et toutes les surfaces de terrain étaient perdues.
    """
    lignes = [
        _row(
            id_parcelle="P1",
            surface_terrain="500",
            code_nature_culture="S",
            nature_culture="sols",
        ),
    ]

    (mutation,) = aggregate_mutations(lignes, millesime=2024)

    assert mutation.surface_terrain == 500.0


def test_les_terres_agricoles_restent_exclues() -> None:
    """Le cadrage §7 ne retient que les sols : une terre labourable n'est pas un jardin."""
    lignes = [
        _row(
            id_parcelle="P1",
            surface_terrain="500",
            code_nature_culture="S",
            nature_culture="sols",
        ),
        _row(
            id_parcelle="P2",
            surface_terrain="90000",
            code_nature_culture="T",
            nature_culture="terres",
        ),
    ]

    (mutation,) = aggregate_mutations(lignes, millesime=2024)

    assert mutation.surface_terrain == 500.0

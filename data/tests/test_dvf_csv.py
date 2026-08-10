"""Tests du parsing d'un CSV geo-dvf en lignes, et du chemin CSV → mutations."""

from __future__ import annotations

from valeur_verte_data.dvf import mutations_from_csv, parse_dvf_csv


def test_parse_lit_chaque_ligne_en_dict() -> None:
    text = "id_mutation,valeur_fonciere\n2024-1,300000\n2024-2,150000\n"

    rows = parse_dvf_csv(text)

    assert rows == [
        {"id_mutation": "2024-1", "valeur_fonciere": "300000"},
        {"id_mutation": "2024-2", "valeur_fonciere": "150000"},
    ]


def test_parse_texte_vide_donne_liste_vide() -> None:
    assert parse_dvf_csv("") == []


def test_parse_entete_seule_donne_liste_vide() -> None:
    assert parse_dvf_csv("id_mutation,valeur_fonciere\n") == []


def test_mutations_from_csv_agrege_les_lots() -> None:
    text = (
        "id_mutation,valeur_fonciere,code_commune,surface_reelle_bati,"
        "id_parcelle,nature_culture,surface_terrain\n"
        "2024-1,300000,31555,80,P1,S,500\n"
        "2024-1,300000,31555,20,P2,S,300\n"
    )

    mutations = mutations_from_csv(text, millesime=2024)

    assert len(mutations) == 1
    (mutation,) = mutations
    assert mutation.prix == 300000.0
    assert mutation.surface_bati == 100.0
    assert mutation.surface_terrain == 800.0
    assert mutation.code_insee == "31555"

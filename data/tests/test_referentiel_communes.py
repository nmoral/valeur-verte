"""Chargeur de référentiels communaux packagés (#341, Lot 1).

Les grandes listes de communes (loi Littoral, montagne…) sont **embarquées et
versionnées** en ressources du paquet (un code INSEE par ligne, ``#`` = commentaire
sourçant la donnée), lues via ``importlib.resources``. Le rattachement d'un code
INSEE à son département gère la métropole (2 caractères, Corse comprise) et
l'Outre-mer (3 caractères).
"""

from __future__ import annotations

from valeur_verte_data.referentiel_communes import (
    charger_codes_communes,
    departement_de,
    extraire_codes,
)


def test_extrait_les_codes_en_sautant_commentaires_et_lignes_vides() -> None:
    texte = "# entête sourçant la donnée\n29019\n\n  06088  \n# autre commentaire\n"

    assert extraire_codes(texte) == frozenset({"29019", "06088"})


def test_charge_les_codes_en_ignorant_les_commentaires() -> None:
    codes = charger_codes_communes("communes_loi_littoral.txt")

    # Aucune ligne de commentaire ni entête ne fuit dans le jeu de codes.
    assert all(not c.startswith("#") for c in codes)
    assert all(len(c) == 5 for c in codes)
    # Communes littoral connues présentes, commune intérieure absente.
    assert "29019" in codes  # Brest
    assert "06088" in codes  # Nice
    assert "31555" not in codes  # Toulouse


def test_departement_metropolitain_sur_deux_caracteres() -> None:
    assert departement_de("29019") == "29"
    assert departement_de("75056") == "75"


def test_departement_corse_sur_deux_caracteres() -> None:
    assert departement_de("2A004") == "2A"
    assert departement_de("2B033") == "2B"


def test_departement_outre_mer_sur_trois_caracteres() -> None:
    assert departement_de("97101") == "971"  # Guadeloupe
    assert departement_de("97411") == "974"  # La Réunion
    assert departement_de("98818") == "988"  # Nouvelle-Calédonie (préfixe 98)

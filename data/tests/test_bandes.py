"""Tests des bandes DPE adaptatives (#169).

Regroupe les classes DPE adjacentes sous-effectif en bandes atteignant un seuil,
en préservant la granularité maximale et en gardant la référence (D) comme
représentant de sa bande. Fonction pure : effectifs -> mapping classe → bande.
"""

from __future__ import annotations

from valeur_verte_data.bandes import bandes_adaptatives


def test_aucune_fusion_si_toutes_les_classes_atteignent_le_seuil() -> None:
    """Toutes les classes au seuil : chacune reste sa propre bande (identité)."""
    effectifs = {"C": 30, "D": 40, "E": 25}
    assert bandes_adaptatives(effectifs, 20) == {"C": "C", "D": "D", "E": "E"}


def test_fusionne_les_passoires_sous_effectif() -> None:
    """F et G trop rares fusionnent en une bande (représentant le plus proche de D)."""
    effectifs = {"D": 50, "E": 50, "F": 12, "G": 12}
    assert bandes_adaptatives(effectifs, 20) == {"D": "D", "E": "E", "F": "F", "G": "F"}


def test_fusionne_vers_la_reference_en_gardant_d() -> None:
    """A, B, C rares fusionnent vers C ; D et E, bien fournies, restent séparées."""
    effectifs = {"A": 5, "B": 5, "C": 15, "D": 50, "E": 50}
    assert bandes_adaptatives(effectifs, 20) == {
        "A": "C",
        "B": "C",
        "C": "C",
        "D": "D",
        "E": "E",
    }


def test_fusionne_avec_le_plus_petit_voisin() -> None:
    """Une classe sous-effectif fusionne avec son voisin le moins fourni (E, pas C)."""
    effectifs = {"C": 50, "D": 5, "E": 25}
    assert bandes_adaptatives(effectifs, 20) == {"C": "C", "D": "D", "E": "D"}


def test_preserve_une_classe_bien_fournie_en_fusionnant_les_rares() -> None:
    """C bien fournie reste sa bande ; D et E rares fusionnent vers leur plus petit voisin (F)."""
    effectifs = {"C": 40, "D": 5, "E": 5, "F": 20}
    assert bandes_adaptatives(effectifs, 20) == {"C": "C", "D": "D", "E": "D", "F": "D"}


def test_effectifs_vides() -> None:
    """Sans classe, aucune bande."""
    assert bandes_adaptatives({}, 20) == {}


def test_tout_sous_seuil_exclu_jamais_de_bande_unique() -> None:
    """Rien n'atteint le seuil : classes exclues, on ne fabrique PAS une bande unique débile."""
    assert bandes_adaptatives({"E": 5, "F": 5, "G": 5}, 20) == {}


def test_une_seule_bande_possible_renvoie_vide() -> None:
    """Une seule bande ≥ seuil est possible (D) : inutile (rien à comparer) → exclu, vide."""
    assert bandes_adaptatives({"D": 30, "E": 5, "F": 5}, 20) == {}

"""Tests des bandes DPE adaptatives (#169).

Regroupe les classes DPE adjacentes sous-effectif en bandes atteignant un seuil,
en préservant la granularité maximale et en gardant la référence (D) comme
représentant de sa bande. Fonction pure : effectifs -> mapping classe → bande.
"""

from __future__ import annotations

from valeur_verte_data.bandes import bandes_adaptatives, fusionner_inversions


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


# --- fusionner_inversions : fusion anti-inversion (une paire par appel) ---
# `bandes` = classe → représentant (banding courant) ; `coef` = représentant → coef.
# Bandes ordonnées A→G (mieux valorisées d'abord) : coef attendu décroissant.


def test_fusion_monotone_inchange() -> None:
    """Coefficients décroissants (monotones) : aucune fusion."""
    bandes = {"A": "C", "B": "C", "C": "C", "D": "D", "E": "E"}
    coef = {"C": 0.2, "D": 0.0, "E": -0.1}
    assert fusionner_inversions(bandes, coef) == bandes


def test_fusion_inversion_basse_ef() -> None:
    """F revalorisée au-dessus de E (coef E < coef F) : {E,F} fusionnent."""
    bandes = {"D": "D", "E": "E", "F": "F"}
    coef = {"D": 0.0, "E": -0.10, "F": -0.05}
    assert fusionner_inversions(bandes, coef) == {"D": "D", "E": "E", "F": "E"}


def test_fusion_inversion_haute_bc() -> None:
    """Inversion B<C (le cas des replis départementaux 70/09) : {B,C} fusionnent."""
    bandes = {"B": "B", "C": "C", "D": "D"}
    coef = {"B": 0.10, "C": 0.15, "D": 0.0}
    assert fusionner_inversions(bandes, coef) == {"B": "C", "C": "C", "D": "D"}


def test_fusion_ne_traite_que_la_premiere_inversion() -> None:
    """Plusieurs inversions : seule la première (plus proche de A) est fusionnée."""
    bandes = {"C": "C", "D": "D", "E": "E", "F": "F"}
    coef = {"C": 0.10, "D": 0.0, "E": 0.05, "F": -0.20}
    assert fusionner_inversions(bandes, coef) == {"C": "C", "D": "D", "E": "D", "F": "F"}


def test_fusion_entre_bandes_deja_groupees() -> None:
    """Fusionne deux bandes multi-classes ; le représentant reste le plus proche de D."""
    bandes = {"A": "C", "B": "C", "C": "C", "D": "D", "E": "E", "F": "E", "G": "E"}
    coef = {"C": 0.2, "D": 0.0, "E": 0.1}
    assert fusionner_inversions(bandes, coef) == {
        "A": "C",
        "B": "C",
        "C": "C",
        "D": "D",
        "E": "D",
        "F": "D",
        "G": "D",
    }


def test_fusion_coef_egaux_pas_une_inversion() -> None:
    """Coefficients égaux : monotonie non stricte respectée, aucune fusion."""
    bandes = {"D": "D", "E": "E"}
    coef = {"D": 0.0, "E": 0.0}
    assert fusionner_inversions(bandes, coef) == {"D": "D", "E": "E"}


def test_fusion_peut_reduire_a_une_bande() -> None:
    """Deux bandes qui inversent fusionnent en une (le refus <2 bandes est en aval)."""
    bandes = {"D": "D", "E": "E"}
    coef = {"D": 0.0, "E": 0.1}
    assert fusionner_inversions(bandes, coef) == {"D": "D", "E": "D"}

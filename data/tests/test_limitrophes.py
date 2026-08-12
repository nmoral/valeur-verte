"""Départements limitrophes, pour que le pool ne s'arrête pas à la frontière (#18, #91).

Une commune du bord du 31 n'a de voisines que d'un côté : son rayon adaptatif
balaie un demi-disque, et le moteur calcule sur un échantillon amputé sans
qu'aucun indicateur ne le signale — n, rayon et IC restent d'apparence normale.
Charger les départements limitrophes rend le disque entier.

La table est une **vérité géographique** : elle décrit les frontières réelles,
pas ce qui se trouve ingéré. Un voisin absent de la base ne contribue à rien et
ne gêne pas ; le jour où il est ingéré, il compte sans changement de code.
"""

from __future__ import annotations

from valeur_verte_data.limitrophes import LIMITROPHES, limitrophes


def test_la_haute_garonne_connait_ses_six_voisins() -> None:
    """Le 31 est le département pilote : ses frontières conditionnent le MVP."""
    assert limitrophes("31") == ("09", "11", "32", "65", "81", "82")


def test_l_adjacence_est_symetrique() -> None:
    """Une frontière déclarée d'un seul côté est une faute de frappe.

    C'est le seul garde-fou contre une table saisie à la main : sans lui, un
    voisin oublié se traduirait par un pool silencieusement amputé.
    """
    for departement, voisins in LIMITROPHES.items():
        for voisin in voisins:
            if voisin not in LIMITROPHES:
                continue  # hors périmètre ingéré : l'autre moitié n'est pas décrite
            assert departement in LIMITROPHES[voisin], (
                f"{departement} déclare {voisin} comme voisin, "
                f"mais {voisin} ne déclare pas {departement}"
            )


def test_un_departement_n_est_jamais_son_propre_voisin() -> None:
    """Il serait sinon chargé deux fois, et ses ventes compteraient double."""
    for departement, voisins in LIMITROPHES.items():
        assert departement not in voisins


def test_un_departement_hors_table_n_a_pas_de_voisin_connu() -> None:
    """Cas normal hors Occitanie : on calcule sans limitrophes, pas d'exception."""
    assert limitrophes("75") == ()

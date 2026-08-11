"""Départements limitrophes, pour que le pool ne s'arrête pas à la frontière (#18, #91).

Le rayon adaptatif prend les n ventes les plus proches sans rien savoir des
limites administratives. Si la base s'arrête au département, une commune du bord
ne voit que la moitié de son voisinage — et rien ne le dit : n, rayon et
intervalle de confiance gardent une apparence normale, seul l'échantillon est
amputé.

La table décrit les **frontières réelles**, indépendamment de ce qui est ingéré.
Un voisin absent de la base ne contribue à aucune ligne et ne gêne pas ; le jour
où il est chargé, il compte sans modification du code. La décrire en fonction du
contenu de la base la rendrait fausse à la première ingestion ailleurs.

Seuls les départements que l'on calcule y figurent comme clés : la liste
s'étendra avec la couverture (#19), sans quoi elle serait une carte de France à
maintenir pour rien.
"""

from __future__ import annotations

from collections.abc import Mapping

LIMITROPHES: Mapping[str, tuple[str, ...]] = {
    "09": ("11", "31", "66"),
    "11": ("09", "31", "34", "66", "81"),
    "31": ("09", "11", "32", "65", "81", "82"),
    "32": ("31", "40", "47", "64", "65", "82"),
    "65": ("31", "32", "64"),
    "81": ("11", "12", "31", "34", "82"),
    "82": ("31", "32", "46", "47", "81"),
}


def limitrophes(departement: str) -> tuple[str, ...]:
    """Départements frontaliers de celui-ci, vide si la frontière n'est pas décrite.

    L'absence n'est pas une erreur : on calcule alors sans débordement, ce qui
    reste le comportement d'avant #91.
    """
    return LIMITROPHES.get(departement, ())

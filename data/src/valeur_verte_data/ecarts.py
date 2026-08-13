"""Écarts entre millésimes — REG-1 (#129).

Après un batch, chaque coefficient est comparé à son millésime précédent. L'écart
**relatif** classe la maille : normal (publiée sans réserve), alerte (publiée mais
signalée pour revue manuelle), ou blocage (publication de la maille suspendue,
§REG-1). Décision **pure**, consommée par le batch de calcul.
"""

from __future__ import annotations

from enum import Enum


class ClasseEcart(Enum):
    """Ampleur de l'écart d'un coefficient d'un millésime au précédent (REG-1)."""

    NORMAL = "normal"
    ALERTE = "alerte"
    BLOCAGE = "blocage"


def classer_ecart(
    ancien: float, nouveau: float, *, seuil_alerte: float, seuil_blocage: float
) -> ClasseEcart:
    """Classe l'écart **relatif** entre l'ancien et le nouveau coefficient (§REG-1).

    Écart = ``|nouveau − ancien| / |ancien|`` (symétrique hausse/baisse, rapporté à
    ``|ancien|`` pour rester juste sur les coefficients log négatifs). Sous
    ``seuil_alerte`` c'est ``NORMAL`` ; jusqu'à ``seuil_blocage`` inclus c'est
    ``ALERTE`` ; au-delà ``BLOCAGE`` — bornes calées sur « < 10 % / 10-25 % / > 25 % ».

    Quand ``ancien`` est nul, l'écart relatif est indéfini : une valeur qui apparaît
    (``nouveau`` non nul) est un changement majeur → ``BLOCAGE`` ; deux valeurs nulles
    (ex. la référence D) n'ont aucun écart → ``NORMAL``.
    """
    if ancien == 0:
        return ClasseEcart.NORMAL if nouveau == 0 else ClasseEcart.BLOCAGE
    ecart = abs(nouveau - ancien) / abs(ancien)
    if ecart < seuil_alerte:
        return ClasseEcart.NORMAL
    if ecart <= seuil_blocage:
        return ClasseEcart.ALERTE
    return ClasseEcart.BLOCAGE

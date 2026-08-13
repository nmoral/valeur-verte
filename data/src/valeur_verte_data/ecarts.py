"""Écarts entre millésimes — REG-1 (#129).

Après un batch, chaque coefficient est comparé à son millésime précédent. L'écart
**relatif** classe la maille : normal (publiée sans réserve), alerte (publiée mais
signalée pour revue manuelle), ou blocage (publication de la maille suspendue,
§REG-1). Décision **pure**, consommée par le batch de calcul.
"""

from __future__ import annotations

import math
from enum import Enum
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from .batch import MailleCoefficient


class ClasseEcart(Enum):
    """Ampleur de l'écart d'un coefficient d'un millésime au précédent (REG-1)."""

    NORMAL = "normal"
    ALERTE = "alerte"
    BLOCAGE = "blocage"


# Gravité croissante : la maille retient le pire écart de ses coefficients.
_GRAVITE = (ClasseEcart.NORMAL, ClasseEcart.ALERTE, ClasseEcart.BLOCAGE)


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


def ecart_maille(
    ancien: MailleCoefficient,
    nouveau: MailleCoefficient,
    *,
    seuil_alerte: float,
    seuil_blocage: float,
) -> ClasseEcart:
    """Classe l'écart d'une maille à son millésime précédent, au **pire** coefficient.

    Chaque coefficient est comparé (§REG-1) :

    - **DPE** : sur le facteur de prix ``exp(coef)`` — un effet log peut bouger
      beaucoup sans que le prix bouge, seul l'effet exponentié est parlant produit.
      Seules les classes présentes **des deux côtés** sont comparées (la référence D,
      ``coef=0`` → ``exp=1``, reste stable par construction).
    - **Élasticités** ``coef_m2_bati`` / ``coef_m2_terrain`` : sur la valeur brute.

    Un **changement de l'ensemble des classes publiées** (rebanding entre millésimes,
    une classe qui apparaît ou disparaît) est un changement structurel à revoir → au
    moins ``ALERTE``, même si les coefficients partagés sont stables.
    """
    seuils = {"seuil_alerte": seuil_alerte, "seuil_blocage": seuil_blocage}
    classes = [
        classer_ecart(math.exp(ancien.coef_dpe[dpe]), math.exp(nouveau.coef_dpe[dpe]), **seuils)
        for dpe in ancien.coef_dpe.keys() & nouveau.coef_dpe.keys()
    ]
    classes.append(classer_ecart(ancien.coef_m2_bati, nouveau.coef_m2_bati, **seuils))
    classes.append(classer_ecart(ancien.coef_m2_terrain, nouveau.coef_m2_terrain, **seuils))
    if ancien.coef_dpe.keys() != nouveau.coef_dpe.keys():
        classes.append(ClasseEcart.ALERTE)
    return max(classes, key=_GRAVITE.index)

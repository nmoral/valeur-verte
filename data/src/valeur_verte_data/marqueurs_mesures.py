"""Marqueurs mesurés (#341, Lot 2) — dérivés de nos données (``coefficient_maille``).

Contrairement aux référentiels contextuels du Lot 1 (climat, marché…), ces
marqueurs sont des **faits** issus de notre modèle (``type_marqueur = 'mesure'``,
sans badge « hypothèse » côté restitution) :

- ``levier_renovation`` fort/moyen/faible — tertiles **nationaux** du gain E→C ;
- ``sensibilite_dpe`` forte/modérée/faible — tertiles **nationaux** de l'amplitude A↔G ;
- ``fiabilite`` solide/correct/fragile — effectif et rayon de la maille.

Logique **pure** : convertir un écart de coefficients DPE en pourcentage, classer
sur des seuils. La lecture de ``coefficient_maille`` vit dans
``db.coefficient_repository`` ; l'assemblage par département dans ``orchestration``.
"""

from __future__ import annotations

import math
from collections.abc import Mapping


def gain_ec(coef_dpe: Mapping[str, float]) -> int | None:
    """Gain de valeur d'une rénovation E→C, en pourcentage entier (fait mesuré).

    Même conversion que la restitution (``exp(coef_C − coef_E) − 1``) : les
    coefficients DPE sont un effet log en base D, l'écart devient un ratio de prix.
    ``None`` si l'une des deux classes n'est pas publiée sur la maille — sans les
    deux bornes, aucun gain n'est calculable.
    """
    return _ecart_pct(coef_dpe, depuis="E", vers="C")


def amplitude_ag(coef_dpe: Mapping[str, float]) -> int | None:
    """Amplitude de l'échelle DPE (écart A↔G), en pourcentage entier (fait mesuré).

    Mesure combien l'étiquette pèse sur cette maille : ``exp(coef_A − coef_G) − 1``.
    ``None`` si A ou G n'est pas publiée (échelle tronquée, pas de sensibilité mesurable).
    """
    return _ecart_pct(coef_dpe, depuis="G", vers="A")


def _ecart_pct(coef_dpe: Mapping[str, float], *, depuis: str, vers: str) -> int | None:
    """Écart de coefficients ``vers``−``depuis`` en pourcentage, ``None`` si incomplet."""
    if depuis not in coef_dpe or vers not in coef_dpe:
        return None
    return round((math.exp(coef_dpe[vers] - coef_dpe[depuis]) - 1) * 100)

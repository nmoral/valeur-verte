"""Couverture géographique progressive et règle des limitrophes (§3.2).

La couverture est un **état en base**, pas une constante : chaque département
passe ``en_attente`` → ``charge`` → (``en_attente_limitrophes``) → ``calcule``.
Règle de déclenchement : on ne calcule un département que si **tous ses
limitrophes terrestres sont chargés** (ou déjà calculés). Les frontières
naturelles (mer, frontière nationale) se modélisent par une **absence de
limitrophe**, ce qui évite tout blocage indéfini. Logique pure, hors-ligne.
"""

from __future__ import annotations

from collections.abc import Mapping, Set

EN_ATTENTE = "en_attente"
CHARGE = "charge"
EN_ATTENTE_LIMITROPHES = "en_attente_limitrophes"
CALCULE = "calcule"

OUT_OF_ZONE_MESSAGE = "région en cours de chargement, disponible prochainement"

# Statuts pour lesquels les données du département sont disponibles à autrui.
_READY = frozenset({CHARGE, CALCULE})


def can_compute(
    departement: str,
    neighbors: Mapping[str, Set[str]],
    statuses: Mapping[str, str],
) -> bool:
    """Vrai si le département et **tous ses limitrophes** sont chargés (§3.2)."""
    if statuses.get(departement) not in _READY:
        return False
    return all(statuses.get(voisin) in _READY for voisin in neighbors.get(departement, frozenset()))


def next_status(
    departement: str,
    neighbors: Mapping[str, Set[str]],
    statuses: Mapping[str, str],
) -> str:
    """Statut cible d'un département selon la règle des limitrophes."""
    if statuses.get(departement, EN_ATTENTE) == EN_ATTENTE:
        return EN_ATTENTE
    return CALCULE if can_compute(departement, neighbors, statuses) else EN_ATTENTE_LIMITROPHES


def is_covered(departement: str, statuses: Mapping[str, str]) -> bool:
    """Vrai si le département est calculé (interrogeable côté public)."""
    return statuses.get(departement) == CALCULE

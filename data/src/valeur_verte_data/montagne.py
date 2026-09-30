"""Référentiel montagne (#341, Lot 1) : communes classées en zone de montagne.

Marqueur **contextuel communal** ``montagne=oui`` — la commune figure dans la liste
officielle des communes classées en zone de montagne (règlement CE n°1257/1999).
L'absence vaut *non* : on n'écrit que la présence. Renseigné **par commune** pour
que deux communes voisines diffèrent (anti-duplicate #341).

Liste **embarquée et versionnée** (``referentiels/communes_montagne.txt``,
Ministère de l'Agriculture) — aucune dépendance réseau.
"""

from __future__ import annotations

from functools import cache

from .marqueurs import TYPE_CONTEXTUEL, Marqueur
from .referentiel_communes import charger_codes_communes, departement_de

SOURCE_MONTAGNE = (
    "Communes classées en zone de montagne (règlement CE n°1257/1999) — "
    "Ministère de l'Agriculture (data.gouv.fr)"
)
_FICHIER = "communes_montagne.txt"


@cache
def communes_montagne() -> frozenset[str]:
    """Codes INSEE des communes classées montagne (chargés une fois)."""
    return charger_codes_communes(_FICHIER)


def _marqueur(code_insee: str) -> Marqueur:
    return Marqueur(
        code_insee=code_insee,
        code_departement=None,
        nom="montagne",
        valeur="oui",
        type_marqueur=TYPE_CONTEXTUEL,
        source=SOURCE_MONTAGNE,
    )


def marqueurs_montagne_departement(departement: str) -> list[Marqueur]:
    """Les marqueurs montagne=oui des communes du département (triés, déterministes)."""
    return [
        _marqueur(code)
        for code in sorted(communes_montagne())
        if departement_de(code) == departement
    ]

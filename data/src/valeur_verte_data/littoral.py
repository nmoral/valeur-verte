"""Référentiel littoral (#341, Lot 1) : communes de la loi Littoral.

Marqueur **contextuel communal** ``littoral=oui`` — la commune figure dans la liste
officielle des communes soumises à la loi Littoral (1986). L'absence vaut *non* :
on n'écrit que la présence. Renseigné **par commune** (et non par département) pour
que deux communes voisines diffèrent (anti-duplicate #341).

Liste **embarquée et versionnée** (``referentiels/communes_loi_littoral.txt``,
COG 2022, DGALN/SIDAUH) — aucune dépendance réseau.
"""

from __future__ import annotations

from functools import cache

from .marqueurs import TYPE_CONTEXTUEL, Marqueur
from .referentiel_communes import charger_codes_communes, departement_de

SOURCE_LITTORAL = "Communes de la loi Littoral (1986), COG 2022 — DGALN/SIDAUH (data.gouv.fr)"
_FICHIER = "communes_loi_littoral.txt"


@cache
def communes_littoral() -> frozenset[str]:
    """Codes INSEE des communes de la loi Littoral (chargés une fois)."""
    return charger_codes_communes(_FICHIER)


def _marqueur(code_insee: str) -> Marqueur:
    return Marqueur(
        code_insee=code_insee,
        code_departement=None,
        nom="littoral",
        valeur="oui",
        type_marqueur=TYPE_CONTEXTUEL,
        source=SOURCE_LITTORAL,
    )


def marqueurs_littoral_departement(departement: str) -> list[Marqueur]:
    """Les marqueurs littoral=oui des communes du département (triés, déterministes)."""
    return [
        _marqueur(code)
        for code in sorted(communes_littoral())
        if departement_de(code) == departement
    ]

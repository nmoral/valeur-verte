"""Référentiel tourisme (#341, Lot 1) : forte présence touristique.

Marqueur **contextuel communal** ``tourisme=fort`` — la commune a une part élevée
de résidences secondaires (seuil INSEE, cf. ressource). L'absence vaut *faible* :
on n'écrit que la présence. Renseigné **par commune** pour que deux communes
voisines diffèrent (anti-duplicate #341).

Liste **embarquée et versionnée** (``referentiels/communes_tourisme.txt``, INSEE
recensement 2022) — aucun réseau.
"""

from __future__ import annotations

from functools import cache

from .marqueurs import TYPE_CONTEXTUEL, Marqueur
from .referentiel_communes import charger_codes_communes, departement_de

SOURCE_TOURISME = (
    "Part de résidences secondaires — INSEE, recensement 2022 (base communale Logement)"
)
_FICHIER = "communes_tourisme.txt"


@cache
def communes_touristiques() -> frozenset[str]:
    """Codes INSEE des communes à forte présence touristique (chargés une fois)."""
    return charger_codes_communes(_FICHIER)


def _marqueur(code_insee: str) -> Marqueur:
    return Marqueur(
        code_insee=code_insee,
        code_departement=None,
        nom="tourisme",
        valeur="fort",
        type_marqueur=TYPE_CONTEXTUEL,
        source=SOURCE_TOURISME,
    )


def marqueurs_tourisme_departement(departement: str) -> list[Marqueur]:
    """Les marqueurs tourisme=fort des communes du département (triés, déterministes)."""
    return [
        _marqueur(code)
        for code in sorted(communes_touristiques())
        if departement_de(code) == departement
    ]

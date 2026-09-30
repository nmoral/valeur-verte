"""Référentiel densité (#341, Lot 1) : grille communale de densité INSEE.

Marqueur **contextuel communal** ``densite`` ∈ {``urbain``, ``periurbain``,
``rural``}, dérivé du degré de densité INSEE à 3 postes :

- **1 (dense) → ``urbain``** ;
- **2 (intermédiaire) → ``periurbain``** ;
- **3 (peu dense) → ``rural``**.

La grille couvre **toute** commune : chaque commune porte une valeur explicite.
Liste **embarquée et versionnée** (``referentiels/communes_densite.txt``, INSEE) —
aucun réseau.
"""

from __future__ import annotations

from functools import cache

from .marqueurs import TYPE_CONTEXTUEL, Marqueur
from .referentiel_communes import charger_mapping_communes, departement_de

SOURCE_DENSITE = "Grille communale de densité (3 postes) — INSEE, grille de densité 2025"
_FICHIER = "communes_densite.txt"

_DENSITE_PAR_NIVEAU = {
    "1": "urbain",
    "2": "periurbain",
    "3": "rural",
}


def densite_de_niveau(niveau: str) -> str:
    """La densité lisible d'un degré INSEE à 3 postes."""
    return _DENSITE_PAR_NIVEAU[niveau]


@cache
def _niveaux() -> dict[str, str]:
    """Mapping code INSEE → degré de densité (chargé une fois)."""
    return charger_mapping_communes(_FICHIER)


def marqueurs_densite_departement(departement: str) -> list[Marqueur]:
    """Les marqueurs de densité des communes du département (triés, déterministes)."""
    return [
        Marqueur(
            code_insee=code,
            code_departement=None,
            nom="densite",
            valeur=densite_de_niveau(niveau),
            type_marqueur=TYPE_CONTEXTUEL,
            source=SOURCE_DENSITE,
        )
        for code, niveau in sorted(_niveaux().items())
        if departement_de(code) == departement
    ]

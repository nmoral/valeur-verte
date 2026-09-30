"""Référentiel marché (#341, Lot 1) : tension du marché du logement (zonage ABC).

Marqueur **contextuel communal** ``marche`` ∈ {``tendu``, ``intermediaire``,
``detendu``}, dérivé de la zone ABC officielle de la commune :

- **Abis, A, B1 → ``tendu``** (demande forte, offre contrainte) ;
- **B2 → ``intermediaire``** ;
- **C → ``detendu``** (offre ≥ demande).

Contrairement au littoral/montagne (présence), la zone ABC couvre **toute** commune :
chaque commune porte donc une valeur explicite. Liste **embarquée et versionnée**
(``referentiels/communes_zonage_abc.txt``, Ministère du Logement) — aucun réseau.
"""

from __future__ import annotations

from functools import cache

from .marqueurs import TYPE_CONTEXTUEL, Marqueur
from .referentiel_communes import charger_mapping_communes, departement_de

SOURCE_MARCHE = "Zonage ABC des communes (art. D304-1 CCH) — Ministère du Logement (data.gouv.fr)"
_FICHIER = "communes_zonage_abc.txt"

_TENSION_PAR_ZONE = {
    "Abis": "tendu",
    "A": "tendu",
    "B1": "tendu",
    "B2": "intermediaire",
    "C": "detendu",
}


def marche_de_zone(zone: str) -> str:
    """La tension de marché lisible d'une zone ABC."""
    return _TENSION_PAR_ZONE[zone]


@cache
def _zones() -> dict[str, str]:
    """Mapping code INSEE → zone ABC (chargé une fois)."""
    return charger_mapping_communes(_FICHIER)


def marqueurs_marche_departement(departement: str) -> list[Marqueur]:
    """Les marqueurs de tension des communes du département (triés, déterministes)."""
    return [
        Marqueur(
            code_insee=code,
            code_departement=None,
            nom="marche",
            valeur=marche_de_zone(zone),
            type_marqueur=TYPE_CONTEXTUEL,
            source=SOURCE_MARCHE,
        )
        for code, zone in sorted(_zones().items())
        if departement_de(code) == departement
    ]

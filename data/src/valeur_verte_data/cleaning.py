"""Nettoyage des transactions DVF avant régression (§7).

Un premier filtre grossier et transparent écarte les ventes non représentatives
(prix symboliques, biens d'exception, surfaces atypiques) qui déformeraient la
régression du moteur. Les bornes viennent du cadrage §7 et sont centralisées ici
pour rester ajustables. Gère aussi le remap des codes INSEE (millésime COG).
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from dataclasses import replace

from .dvf import Mutation

# Bornes de nettoyage (§7).
PRICE_MIN = 30_000.0
PRICE_MAX = 900_000.0
BATI_MIN = 50.0
BATI_MAX = 250.0
TERRAIN_MIN = 50.0
TERRAIN_MAX = 5_000.0


def _within(value: float | None, low: float, high: float) -> bool:
    return value is not None and low <= value <= high


def is_clean(mutation: Mutation) -> bool:
    """Vrai si la mutation respecte les bornes §7 (terrain vérifié si présent)."""
    if not _within(mutation.prix, PRICE_MIN, PRICE_MAX):
        return False
    if not _within(mutation.surface_bati, BATI_MIN, BATI_MAX):
        return False
    return not (
        mutation.surface_terrain is not None
        and not _within(mutation.surface_terrain, TERRAIN_MIN, TERRAIN_MAX)
    )


def clean_mutations(mutations: Iterable[Mutation]) -> list[Mutation]:
    """Ne conserve que les mutations dans les bornes §7."""
    return [mutation for mutation in mutations if is_clean(mutation)]


def remap_insee(code_insee: str, correspondence: Mapping[str, str]) -> str:
    """Applique la correspondance COG (ancien → nouveau code), sinon inchangé."""
    return correspondence.get(code_insee, code_insee)


def apply_cog(mutations: Iterable[Mutation], correspondence: Mapping[str, str]) -> list[Mutation]:
    """Remappe le ``code_insee`` de chaque mutation selon le millésime COG (§7)."""
    return [
        replace(mutation, code_insee=remap_insee(mutation.code_insee, correspondence))
        for mutation in mutations
    ]

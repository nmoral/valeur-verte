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

# Enveloppe géographique de la métropole (Corse comprise). Au-delà, la géoloc est
# **corrompue** (erreur de géocodage source — ex. les 60 ventes du 92 tombées à une
# latitude polaire ~83°N), pas un choix de modèle : contrairement aux bornes §7
# (désactivables, S12), ces coordonnées sont neutralisées **quoi qu'il arrive**.
FRANCE_LON_MIN = -5.5
FRANCE_LON_MAX = 9.8
FRANCE_LAT_MIN = 41.0
FRANCE_LAT_MAX = 51.5


def _within(value: float | None, low: float, high: float) -> bool:
    return value is not None and low <= value <= high


def is_clean(
    mutation: Mutation,
    *,
    price_min: float = PRICE_MIN,
    price_max: float = PRICE_MAX,
    bati_min: float = BATI_MIN,
    bati_max: float = BATI_MAX,
    terrain_min: float = TERRAIN_MIN,
    terrain_max: float = TERRAIN_MAX,
) -> bool:
    """Vrai si la mutation respecte les bornes §7 (terrain vérifié si présent)."""
    if not _within(mutation.prix, price_min, price_max):
        return False
    if not _within(mutation.surface_bati, bati_min, bati_max):
        return False
    return not (
        mutation.surface_terrain is not None
        and not _within(mutation.surface_terrain, terrain_min, terrain_max)
    )


def clean_mutations(
    mutations: Iterable[Mutation],
    *,
    price_min: float = PRICE_MIN,
    price_max: float = PRICE_MAX,
    bati_min: float = BATI_MIN,
    bati_max: float = BATI_MAX,
    terrain_min: float = TERRAIN_MIN,
    terrain_max: float = TERRAIN_MAX,
) -> list[Mutation]:
    """Ne conserve que les mutations dans les bornes §7."""
    return [
        mutation
        for mutation in mutations
        if is_clean(
            mutation,
            price_min=price_min,
            price_max=price_max,
            bati_min=bati_min,
            bati_max=bati_max,
            terrain_min=terrain_min,
            terrain_max=terrain_max,
        )
    ]


def coordonnees_plausibles(longitude: float | None, latitude: float | None) -> bool:
    """Vrai si le point est dans l'enveloppe métropolitaine (les deux axes requis)."""
    return (
        longitude is not None
        and latitude is not None
        and FRANCE_LON_MIN <= longitude <= FRANCE_LON_MAX
        and FRANCE_LAT_MIN <= latitude <= FRANCE_LAT_MAX
    )


def assainir_coordonnees(mutations: Iterable[Mutation]) -> list[Mutation]:
    """Neutralise (met à ``None``) les coordonnées hors métropole (géocodage corrompu).

    La mutation est **conservée** mais dégéolocalisée : elle sort de la sélection
    spatiale (``regression._eligible`` exige des coordonnées), exactement comme une
    vente jamais géolocalisée — au lieu de matérialiser un ``geom`` aberrant qui
    polluerait la base. Une vente déjà sans coordonnées passe inchangée.
    """
    return [
        mutation
        if mutation.longitude is None and mutation.latitude is None
        else _assainir(mutation)
        for mutation in mutations
    ]


def remap_insee(code_insee: str, correspondence: Mapping[str, str]) -> str:
    """Applique la correspondance COG (ancien → nouveau code), sinon inchangé."""
    return correspondence.get(code_insee, code_insee)


def apply_cog(mutations: Iterable[Mutation], correspondence: Mapping[str, str]) -> list[Mutation]:
    """Remappe le ``code_insee`` de chaque mutation selon le millésime COG (§7)."""
    return [
        replace(mutation, code_insee=remap_insee(mutation.code_insee, correspondence))
        for mutation in mutations
    ]


def _assainir(mutation: Mutation) -> Mutation:
    """Rend la mutation inchangée si sa géoloc est plausible, sinon coordonnées à ``None``."""
    if coordonnees_plausibles(mutation.longitude, mutation.latitude):
        return mutation
    return replace(mutation, longitude=None, latitude=None)

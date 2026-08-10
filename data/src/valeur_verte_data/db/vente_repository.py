"""Écriture des mutations DVF agrégées dans ``data.vente``.

La géométrie ``geom`` (geography Point/4326) est calculée en base à partir des
coordonnées via ``ST_MakePoint`` ; si longitude/latitude sont absentes, le point
vaut ``NULL`` (la mutation reste chargée, mais hors sélection spatiale). La clé
d'appariement DPE (``adresse_key``, §15) est normalisée à l'insertion.
"""

from __future__ import annotations

from collections.abc import Iterable
from typing import Any

from ..dvf import Mutation
from ..matching import address_key
from .runner import Connection

_INSERT = (
    "INSERT INTO data.vente ("
    "id_mutation, date_mutation, millesime, code_insee, code_postal, nom_commune, "
    "type_local, nombre_pieces_principales, prix, surface_bati, surface_terrain, "
    "prix_m2, adresse_numero, adresse_suffixe, adresse_nom_voie, adresse_key, "
    "etiquette_dpe, periode_construction, longitude, latitude, geom"
    ") VALUES ("
    "%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, "
    "ST_SetSRID(ST_MakePoint(%s, %s), 4326)::geography"
    ")"
)


def _params(mutation: Mutation) -> list[Any]:
    key = address_key(mutation.adresse_numero, mutation.adresse_nom_voie, mutation.adresse_suffixe)
    return [
        mutation.id_mutation,
        mutation.date_mutation,
        mutation.millesime,
        mutation.code_insee,
        mutation.code_postal,
        mutation.nom_commune,
        mutation.type_local,
        mutation.nombre_pieces_principales,
        mutation.prix,
        mutation.surface_bati,
        mutation.surface_terrain,
        mutation.prix_m2,
        mutation.adresse_numero,
        mutation.adresse_suffixe,
        mutation.adresse_nom_voie,
        key or None,
        mutation.etiquette_dpe,
        mutation.periode_construction,
        mutation.longitude,
        mutation.latitude,
        mutation.longitude,
        mutation.latitude,
    ]


def insert_mutations(conn: Connection, mutations: Iterable[Mutation]) -> int:
    """Insère les mutations dans ``data.vente`` ; retourne le nombre inséré."""
    inserted = 0
    for mutation in mutations:
        conn.execute(_INSERT, _params(mutation))
        inserted += 1
    return inserted

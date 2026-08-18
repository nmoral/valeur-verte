"""Écriture des mutations DVF agrégées dans ``data.vente``.

La géométrie ``geom`` (geography Point/4326) est calculée en base à partir des
coordonnées via ``ST_MakePoint`` ; si longitude/latitude sont absentes, le point
vaut ``NULL`` (la mutation reste chargée, mais hors sélection spatiale). La clé
d'appariement DPE (``adresse_key``, §15) est normalisée à l'insertion.
"""

from __future__ import annotations

from collections.abc import Iterable
from datetime import date
from typing import Any

from ..distributions import Distribution
from ..dvf import Mutation
from ..matching import address_key
from .runner import Connection

_INSERT = (
    "INSERT INTO data.vente ("
    "id_mutation, date_mutation, millesime, code_insee, code_postal, nom_commune, "
    "type_local, nombre_pieces_principales, prix, surface_bati, surface_terrain, "
    "prix_m2, adresse_numero, adresse_suffixe, adresse_nom_voie, adresse_key, "
    "etiquette_dpe, periode_construction, date_etablissement_dpe, version_dpe, "
    "longitude, latitude, geom"
    ") VALUES ("
    "%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, "
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
        mutation.date_etablissement_dpe,
        mutation.version_dpe,
        mutation.longitude,
        mutation.latitude,
        mutation.longitude,
        mutation.latitude,
    ]


_DELETE = "DELETE FROM data.vente WHERE code_insee = %s AND millesime = %s"


def delete_mutations(conn: Connection, code_insee: str, millesime: int) -> None:
    """Vide une commune pour un millésime, préalable à un ré-import."""
    conn.execute(_DELETE, [code_insee, millesime])


def insert_mutations(conn: Connection, mutations: Iterable[Mutation]) -> int:
    """Insère les mutations dans ``data.vente`` ; retourne le nombre inséré."""
    inserted = 0
    for mutation in mutations:
        conn.execute(_INSERT, _params(mutation))
        inserted += 1
    return inserted


# DAT-4 (#127) : distributions observées d'un département sur data.vente. La jointure
# à data.commune borne le département par code_departement (gère 2A/2B/DOM, pas de
# préfixe fragile). Les médianes sont calculées en base (percentile_cont, robuste sur
# la base brute S12). « Terrain aberrant » = terrain nul ou hors bornes §7 (passées).
_SELECT_DISTRIBUTION = (
    "SELECT "
    "percentile_cont(0.5) WITHIN GROUP (ORDER BY v.prix) "
    "FILTER (WHERE v.prix IS NOT NULL), "
    "percentile_cont(0.5) WITHIN GROUP (ORDER BY v.surface_bati) "
    "FILTER (WHERE v.surface_bati IS NOT NULL), "
    "count(*) FILTER (WHERE v.surface_terrain IS NULL "
    "OR v.surface_terrain < %s OR v.surface_terrain > %s), "
    "count(*) "
    "FROM data.vente v JOIN data.commune c ON c.code_insee = v.code_insee "
    "WHERE c.code_departement = %s AND v.millesime = %s"
)
_SELECT_HISTOGRAMME_DPE = (
    "SELECT v.etiquette_dpe, count(*) "
    "FROM data.vente v JOIN data.commune c ON c.code_insee = v.code_insee "
    "WHERE c.code_departement = %s AND v.millesime = %s AND v.etiquette_dpe IS NOT NULL "
    "GROUP BY v.etiquette_dpe"
)


def calculer_distribution(
    conn: Connection,
    departement: str,
    millesime: int,
    *,
    terrain_min: float,
    terrain_max: float,
) -> Distribution:
    """Mesure les distributions DAT-4 d'un (dept, millésime) sur ``data.vente``."""
    prix_median, bati_median, terrain_aberrant, effectif = conn.execute(
        _SELECT_DISTRIBUTION, [terrain_min, terrain_max, departement, millesime]
    ).fetchall()[0]
    histogramme = {
        classe: int(nombre)
        for classe, nombre in conn.execute(
            _SELECT_HISTOGRAMME_DPE, [departement, millesime]
        ).fetchall()
    }
    return Distribution(
        prix_median=None if prix_median is None else float(prix_median),
        surface_bati_median=None if bati_median is None else float(bati_median),
        terrain_aberrant=int(terrain_aberrant),
        effectif=int(effectif),
        effectifs_dpe=histogramme,
    )


# DAT-5 (#128) : date de mutation la plus récente d'un département sur data.vente.
# Jointe à data.commune pour borner le département (comme calculer_distribution).
_SELECT_DATE_MUTATION_MAX = (
    "SELECT max(v.date_mutation) "
    "FROM data.vente v JOIN data.commune c ON c.code_insee = v.code_insee "
    "WHERE c.code_departement = %s AND v.millesime = %s"
)


def date_mutation_max(conn: Connection, departement: str, millesime: int) -> date | None:
    """Date de mutation la plus récente d'un (dept, millésime) ; ``None`` si aucune vente.

    Sert la fraîcheur DAT-5 (confrontée à la date d'exécution) et est archivée comme
    source de vérité du millésime/fraîcheur.
    """
    lignes = conn.execute(_SELECT_DATE_MUTATION_MAX, [departement, millesime]).fetchall()
    if not lignes:
        return None
    valeur: date | None = lignes[0][0]
    return valeur

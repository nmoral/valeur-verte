"""Chargement en mémoire des ventes d'un département (§10.6, #91).

``batch.compute_communes`` attend un pool de ``Mutation`` chargé **une seule
fois** pour tout le département : le rayon adaptatif fait déborder chaque
sélection sur les communes voisines, donc recharger par commune multiplierait
les requêtes sans rien apporter.

Seules les colonnes utiles au moteur sont lues ; les autres (adresse, code
postal, nom de commune) n'interviennent ni dans la sélection ni dans la
régression.
"""

from __future__ import annotations

from ..dvf import Mutation
from .runner import Connection

_SELECT_POOL = (
    "SELECT id_mutation, date_mutation, millesime, code_insee, prix, surface_bati, "
    "surface_terrain, etiquette_dpe, periode_construction, longitude, latitude "
    "FROM data.vente "
    "WHERE code_insee LIKE %s AND millesime = %s"
)


def _mutation(ligne: tuple[object, ...]) -> Mutation:
    return Mutation(
        id_mutation=str(ligne[0]),
        date_mutation=None if ligne[1] is None else str(ligne[1]),
        millesime=int(str(ligne[2])),
        code_insee=str(ligne[3]),
        code_postal=None,
        nom_commune=None,
        type_local=None,
        nombre_pieces_principales=None,
        prix=None if ligne[4] is None else float(str(ligne[4])),
        surface_bati=None if ligne[5] is None else float(str(ligne[5])),
        surface_terrain=None if ligne[6] is None else float(str(ligne[6])),
        prix_m2=None,
        adresse_numero=None,
        adresse_suffixe=None,
        adresse_nom_voie=None,
        longitude=None if ligne[9] is None else float(str(ligne[9])),
        latitude=None if ligne[10] is None else float(str(ligne[10])),
        etiquette_dpe=None if ligne[7] is None else str(ligne[7]),
        periode_construction=None if ligne[8] is None else str(ligne[8]),
    )


def load_pool(conn: Connection, departement: str, millesime: int) -> list[Mutation]:
    """Ventes d'un département pour un millésime, prêtes pour le moteur.

    Le département est déduit du préfixe du code INSEE : c'est la clé de
    rattachement retenue (§7), le code postal ne l'est jamais.
    """
    lignes = conn.execute(_SELECT_POOL, [f"{departement}%", millesime]).fetchall()
    return [_mutation(tuple(ligne)) for ligne in lignes]

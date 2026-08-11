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

from collections.abc import Sequence

from ..dvf import Mutation
from .runner import Connection

# Segment retenu pour le MVP (#115). Sur les appartements, l'emplacement
# (étage, immeuble, quartier) écrase l'étiquette DPE : l'écart D→F y vaut
# +47 €/m² contre −676 pour les maisons, soit un effet de signe opposé.
SEGMENT_MVP = "Maison"

_SELECT_POOL = (
    "SELECT id_mutation, date_mutation, millesime, code_insee, prix, surface_bati, "
    "surface_terrain, etiquette_dpe, periode_construction, longitude, latitude "
    "FROM data.vente "
    "WHERE code_insee LIKE ANY(%s) AND millesime = %s AND type_local = %s"
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


def load_pool(
    conn: Connection,
    departement: str,
    millesime: int,
    *,
    limitrophes: Sequence[str] = (),
    type_local: str = SEGMENT_MVP,
) -> list[Mutation]:
    """Ventes d'un département pour un millésime, prêtes pour le moteur.

    Le département est déduit du préfixe du code INSEE : c'est la clé de
    rattachement retenue (§7), le code postal ne l'est jamais.

    ``limitrophes`` fait déborder le pool sur les départements voisins, pour que
    les communes du bord disposent d'un voisinage entier (#18). Le débordement
    est demandé par l'appelant plutôt que déduit ici : ce module charge des
    ventes, il ne connaît pas la géographie.

    Le segment est un paramètre explicite plutôt qu'une condition enfouie :
    étendre le calcul aux appartements suppose de contrôler l'emplacement
    infra-communal, et le point de bascule doit rester visible (#115).
    """
    prefixes = [f"{code}%" for code in (departement, *limitrophes)]
    lignes = conn.execute(_SELECT_POOL, [prefixes, millesime, type_local]).fetchall()
    return [_mutation(tuple(ligne)) for ligne in lignes]

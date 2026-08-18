"""Archivage du rapport de batch, une ligne par exécution (#131).

« La première chose à regarder six mois plus tard. » Chaque exécution de commande
insère sa propre ligne dans ``data.rapport_batch`` — jamais un UPDATE : la
comparaison entre millésimes (DAT-2, REG-1) suppose de garder le rapport précédent.
L'ingestion et le calcul écrivent des colonnes disjointes (la ``phase`` discrimine) ;
les métriques de l'autre phase restent NULL. Les communes en échec sont sérialisées
en JSONB ; les autres détails sont des compteurs dérivés des listes du rapport.
"""

from __future__ import annotations

import json
from datetime import datetime

from ..appariement import taux_depuis_comptes
from ..distributions import Distribution, distribution_depuis_dict, distribution_en_dict
from ..orchestration import RapportCalcul, RapportIngestion
from .runner import Connection

# DAT-3 (#126) : le taux d'appariement d'un (dept, millésime) est déjà archivé ici.
# La dernière exécution fait foi (jamais d'UPDATE) — d'où le tri décroissant + LIMIT 1.
_SELECT_TAUX = (
    "SELECT ventes_appariees, mutations_inserees FROM data.rapport_batch "
    "WHERE phase = 'ingestion' AND departement = %s AND millesime = %s "
    "ORDER BY debut DESC, id DESC LIMIT 1"
)
_SELECT_TAUX_PRECEDENT = (
    "SELECT ventes_appariees, mutations_inserees FROM data.rapport_batch "
    "WHERE phase = 'ingestion' AND departement = %s AND millesime < %s "
    "ORDER BY millesime DESC, debut DESC, id DESC LIMIT 1"
)

# DAT-2 (#125) : la volumétrie (mutations insérées) du millésime précédent, pour
# mesurer l'écart ± 30 %. La volumétrie courante, elle, est en main en fin
# d'ingestion (le RapportIngestion) — seul le précédent se relit ici.
_SELECT_VOLUMETRIE_PRECEDENT = (
    "SELECT mutations_inserees FROM data.rapport_batch "
    "WHERE phase = 'ingestion' AND departement = %s AND millesime < %s "
    "ORDER BY millesime DESC, debut DESC, id DESC LIMIT 1"
)

# DAT-4 (#127) : la distribution observée du (dept, millésime), archivée en JSONB pour
# relecture à la porte au calcul. Dernière exécution du millésime (jamais d'UPDATE).
_SELECT_DISTRIBUTION = (
    "SELECT distribution FROM data.rapport_batch "
    "WHERE phase = 'ingestion' AND departement = %s AND millesime = %s "
    "AND distribution IS NOT NULL "
    "ORDER BY debut DESC, id DESC LIMIT 1"
)
_SELECT_DISTRIBUTION_PRECEDENTE = (
    "SELECT distribution FROM data.rapport_batch "
    "WHERE phase = 'ingestion' AND departement = %s AND millesime < %s "
    "AND distribution IS NOT NULL "
    "ORDER BY millesime DESC, debut DESC, id DESC LIMIT 1"
)

_INSERT_INGESTION = (
    "INSERT INTO data.rapport_batch ("
    "phase, departement, millesime, debut, fin, "
    "communes_traitees, communes_referencees, mutations_inserees, ventes_appariees, "
    "contours_charges, epci_charges, sans_donnees, distribution, "
    "mutation_la_plus_recente, echecs"
    ") VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s::jsonb, %s, %s::jsonb)"
)

_INSERT_CALCUL = (
    "INSERT INTO data.rapport_batch ("
    "phase, departement, millesime, debut, fin, "
    "communes_calculees, refus, rejets_invariants, alertes_bord, inversions_dpe, "
    "ecarts_blocages, ecarts_alertes, ordre_canonique, echecs"
    ") VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s::jsonb)"
)


def enregistrer_rapport_ingestion(
    conn: Connection,
    rapport: RapportIngestion,
    *,
    debut: datetime,
    fin: datetime,
) -> None:
    """Archive les métriques d'une exécution d'ingestion (volumétrie, appariement)."""
    conn.execute(
        _INSERT_INGESTION,
        (
            "ingestion",
            rapport.departement,
            rapport.millesime,
            debut,
            fin,
            rapport.communes_traitees,
            rapport.communes_referencees,
            rapport.mutations_inserees,
            rapport.ventes_appariees,
            rapport.contours_charges,
            rapport.epci_charges,
            len(rapport.sans_donnees),
            _distribution_json(rapport.distribution),
            rapport.mutation_la_plus_recente,
            json.dumps(rapport.echecs),
        ),
    )


def enregistrer_rapport_calcul(
    conn: Connection,
    rapport: RapportCalcul,
    *,
    debut: datetime,
    fin: datetime,
) -> None:
    """Archive les métriques d'une exécution de calcul (invariants, écarts REG-1)."""
    conn.execute(
        _INSERT_CALCUL,
        (
            "calcul",
            rapport.departement,
            rapport.millesime,
            debut,
            fin,
            rapport.communes_calculees,
            len(rapport.refus),
            len(rapport.rejets_invariants),
            len(rapport.alertes_bord),
            len(rapport.inversions_dpe),
            len(rapport.ecarts.blocages),
            len(rapport.ecarts.alertes),
            rapport.ordre_canonique,
            json.dumps(rapport.echecs),
        ),
    )


def lire_taux_appariement(conn: Connection, departement: str, millesime: int) -> float | None:
    """Taux d'appariement DVF ↔ DPE d'un (dept, millésime), dernière exécution (DAT-3).

    ``None`` si aucune ingestion archivée pour ce millésime, ou si elle n'a inséré
    aucune vente (taux indéfini plutôt qu'un faux 0 %).
    """
    lignes = conn.execute(_SELECT_TAUX, [departement, millesime]).fetchall()
    if not lignes:
        return None
    appariees, mutations = lignes[0]
    return taux_depuis_comptes(appariees, mutations)


def lire_taux_appariement_precedent(
    conn: Connection, departement: str, millesime: int
) -> float | None:
    """Taux d'appariement du millésime le plus récent **strictement antérieur** à N.

    Sert à mesurer la chute (DAT-3) ; ``None`` s'il n'existe aucun millésime antérieur.
    """
    lignes = conn.execute(_SELECT_TAUX_PRECEDENT, [departement, millesime]).fetchall()
    if not lignes:
        return None
    appariees, mutations = lignes[0]
    return taux_depuis_comptes(appariees, mutations)


def lire_volumetrie_precedent(conn: Connection, departement: str, millesime: int) -> int | None:
    """Volumétrie (mutations insérées) du millésime le plus récent **antérieur** à N.

    Sert à mesurer l'écart ± 30 % (DAT-2) ; ``None`` s'il n'existe aucun millésime
    antérieur (premier chargement) — l'assertion est alors neutre.
    """
    lignes = conn.execute(_SELECT_VOLUMETRIE_PRECEDENT, [departement, millesime]).fetchall()
    if not lignes:
        return None
    return int(lignes[0][0])


def lire_distribution(conn: Connection, departement: str, millesime: int) -> Distribution | None:
    """Distribution archivée d'un (dept, millésime), dernière exécution (DAT-4).

    Sert la plausibilité (prix/surface médianes, terrain aberrant) ; ``None`` si aucune
    ingestion n'a archivé de distribution pour ce millésime (rien à juger).
    """
    lignes = conn.execute(_SELECT_DISTRIBUTION, [departement, millesime]).fetchall()
    if not lignes:
        return None
    return distribution_depuis_dict(lignes[0][0])


def lire_distribution_dpe_precedent(
    conn: Connection, departement: str, millesime: int
) -> dict[str, int] | None:
    """Histogramme DPE du millésime le plus récent **antérieur** à N (DAT-4).

    Sert la comparaison de répartition DPE (alerte) ; ``None`` s'il n'existe aucune
    distribution antérieure (premier chargement).
    """
    lignes = conn.execute(_SELECT_DISTRIBUTION_PRECEDENTE, [departement, millesime]).fetchall()
    if not lignes:
        return None
    return distribution_depuis_dict(lignes[0][0]).effectifs_dpe


def _distribution_json(distribution: Distribution | None) -> str | None:
    """Sérialise la distribution pour l'archivage, ou ``None`` (colonne NULL)."""
    if distribution is None:
        return None
    return json.dumps(distribution_en_dict(distribution))

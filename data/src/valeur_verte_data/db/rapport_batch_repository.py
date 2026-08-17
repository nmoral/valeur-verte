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

from ..orchestration import RapportCalcul, RapportIngestion
from .runner import Connection

_INSERT_INGESTION = (
    "INSERT INTO data.rapport_batch ("
    "phase, departement, millesime, debut, fin, "
    "communes_traitees, communes_referencees, mutations_inserees, ventes_appariees, "
    "contours_charges, epci_charges, sans_donnees, echecs"
    ") VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s::jsonb)"
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

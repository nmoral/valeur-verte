"""DAT-3 (#126) — la porte : décide si le calcul d'un département peut tourner.

L'ingestion et le calcul sont deux commandes séparées (conteneurs détachés, §5) :
la porte s'évalue donc au **calcul**, en relisant le taux d'appariement archivé à
l'ingestion (#131, ``data.rapport_batch``). Sans taux courant — aucune ingestion,
ou 0 mutation insérée — il n'y a rien à barrer. Sinon, le taux du millésime et
celui du précédent passent aux garde-fous (plancher, chute) de ``evaluer_appariement``.
"""

from __future__ import annotations

from .appariement import VerdictAppariement, evaluer_appariement
from .db.rapport_batch_repository import (
    lire_taux_appariement,
    lire_taux_appariement_precedent,
)
from .db.runner import Connection


def porte_dat3(
    conn: Connection,
    departement: str,
    millesime: int,
    *,
    seuil_min: float,
    chute_max: float,
) -> VerdictAppariement | None:
    """Verdict DAT-3 d'un département avant calcul, ou ``None`` s'il n'y a rien à barrer.

    Court-circuite dès l'absence de taux courant : inutile de lire le précédent quand
    il n'y a aucune ingestion à juger.
    """
    taux = lire_taux_appariement(conn, departement, millesime)
    if taux is None:
        return None
    precedent = lire_taux_appariement_precedent(conn, departement, millesime)
    return evaluer_appariement(
        departement, taux, precedent, seuil_min=seuil_min, chute_max=chute_max
    )

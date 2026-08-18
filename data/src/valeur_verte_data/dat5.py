"""DAT-5 (#128) — la porte : lire la date la plus récente puis juger la fraîcheur.

En fin d'ingestion, ``porte_dat5`` lit la date de mutation la plus récente du
département (``date_mutation_max`` sur ``data.vente``) et la confronte à la **date
d'exécution** du batch via ``evaluer_fraicheur``. Contrairement à DAT-3/DAT-4, aucune
archive n'est relue : le verdict se juge sur données en main (la date mesurée) et la
référence reçue. La porte rend **toujours** un verdict (elle observe, ne barre rien) ;
la date lue est transportée par le verdict pour être archivée par la CLI.
"""

from __future__ import annotations

from datetime import date

from .db.runner import Connection
from .db.vente_repository import date_mutation_max
from .fraicheur import VerdictFraicheur, evaluer_fraicheur


def porte_dat5(
    conn: Connection,
    departement: str,
    millesime: int,
    reference: date,
    *,
    max_mois: int,
) -> VerdictFraicheur:
    """Verdict de fraîcheur DAT-5 d'un département en fin d'ingestion (alerte).

    ``reference`` est la date d'exécution du batch ; ``max_mois`` le seuil d'ancienneté
    au-delà duquel la source est signalée périmée.
    """
    date_max = date_mutation_max(conn, departement, millesime)
    return evaluer_fraicheur(departement, date_max, reference, max_mois=max_mois)

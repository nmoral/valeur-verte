"""DAT-2 (#125) — la porte : lire la volumétrie précédente puis alerter (jamais bloquer).

À la différence de DAT-3, la volumétrie **courante** est en main en fin d'ingestion
(le ``RapportIngestion``, mutations réellement insérées) : ``porte_dat2`` ne relit
que celle du **millésime précédent** (archivée par #131 dans ``data.rapport_batch``)
avant de déléguer à ``evaluer_volumetrie``. Elle rend **toujours** un verdict — sans
précédent (premier chargement) le verdict est neutre, jamais ``None`` : DAT-2 ne
barre rien, elle observe.
"""

from __future__ import annotations

from .db.rapport_batch_repository import lire_volumetrie_precedent
from .db.runner import Connection
from .volumetrie import VerdictVolumetrie, evaluer_volumetrie


def porte_dat2(
    conn: Connection,
    departement: str,
    millesime: int,
    volumetrie: int,
    *,
    ecart_max: float,
) -> VerdictVolumetrie:
    """Verdict DAT-2 d'un département en fin d'ingestion (alerte non bloquante).

    ``volumetrie`` est la volumétrie du millésime courant, en main (pas relue) ; seul
    le précédent est lu, pour mesurer l'écart ± ``ecart_max``.
    """
    precedente = lire_volumetrie_precedent(conn, departement, millesime)
    return evaluer_volumetrie(departement, volumetrie, precedente, ecart_max=ecart_max)

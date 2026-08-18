"""DAT-4 (#127) — la porte : relire les distributions archivées puis décider.

L'ingestion et le calcul sont deux commandes séparées (§5) : la porte s'évalue au
**calcul**, en relisant les distributions mesurées et archivées à l'ingestion (#131,
``data.rapport_batch``). Sans distribution courante — ingestion antérieure à DAT-4, ou
département sans vente (effectif nul) — il n'y a **rien à juger** : on rend ``None``
sans lire le précédent. Sinon, la distribution courante et l'histogramme DPE du
millésime précédent passent aux garde-fous de ``evaluer_distribution`` (plausibilité
bloquante, répartition DPE en alerte seule).
"""

from __future__ import annotations

from .db.rapport_batch_repository import lire_distribution, lire_distribution_dpe_precedent
from .db.runner import Connection
from .distributions import VerdictDistribution, evaluer_distribution


def porte_dat4(
    conn: Connection,
    departement: str,
    millesime: int,
    *,
    prix_median_min: float,
    prix_median_max: float,
    bati_median_min: float,
    bati_median_max: float,
    terrain_aberrant_max: float,
    dpe_ecart_max: float,
) -> VerdictDistribution | None:
    """Verdict DAT-4 d'un département avant calcul, ou ``None`` s'il n'y a rien à juger.

    Court-circuite dès l'absence de distribution courante (ou un effectif nul) : inutile
    de lire le précédent quand aucune ingestion mesurable n'est à confronter.
    """
    distribution = lire_distribution(conn, departement, millesime)
    if distribution is None or distribution.effectif == 0:
        return None
    precedent = lire_distribution_dpe_precedent(conn, departement, millesime)
    return evaluer_distribution(
        departement,
        distribution,
        precedent,
        prix_median_min=prix_median_min,
        prix_median_max=prix_median_max,
        bati_median_min=bati_median_min,
        bati_median_max=bati_median_max,
        terrain_aberrant_max=terrain_aberrant_max,
        dpe_ecart_max=dpe_ecart_max,
    )

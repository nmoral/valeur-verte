"""Orchestration de l'ingestion DVF : récupération puis écriture en base.

Point de composition entre le client HTTP (``dvf_source``) et le writer
(``db.vente_repository``). La transaction est gérée par l'appelant (la connexion
est committée à la sortie du ``with psycopg.connect(...)``).
"""

from __future__ import annotations

from dataclasses import dataclass

from .cleaning import clean_mutations
from .config import DEFAULT_CONFIG, CalculConfig
from .db.runner import Connection
from .db.vente_repository import delete_mutations, insert_mutations
from .dpe import attach_dpe
from .dpe_source import dpe_lookup
from .dvf_source import fetch_mutations
from .http import Fetcher


@dataclass(frozen=True)
class ResultatIngestionCommune:
    """Ce qu'une commune a réellement chargé : ventes insérées et part appariée.

    L'appariement DPE (DAT-3, #131) est compté ici, où les mutations sont connues :
    sans étiquette, le moteur écarte la vente (`regression._eligible`), et le taux
    d'appariement du rapport de batch se lit sur ces deux nombres.
    """

    inserees: int
    appariees: int


def ingest_commune(
    fetcher: Fetcher,
    conn: Connection,
    code_insee: str,
    millesime: int,
    *,
    config: CalculConfig = DEFAULT_CONFIG,
) -> ResultatIngestionCommune:
    """Récupère les mutations DVF d'une commune, les apparie aux DPE et les écrit.

    Le contenu existant de la commune pour ce millésime est remplacé : sans
    contrainte d'unicité en base, un ré-import y ajouterait sinon une seconde
    copie de chaque transaction (#91).
    """
    mutations = clean_mutations(
        fetch_mutations(fetcher, code_insee, millesime),
        price_min=config.price_min,
        price_max=config.price_max,
        bati_min=config.bati_min,
        bati_max=config.bati_max,
        terrain_min=config.terrain_min,
        terrain_max=config.terrain_max,
    )
    # Sans étiquette DPE ni période de construction, le moteur écarte la vente
    # (`regression._eligible`) : une base chargée sans appariement est stérile.
    mutations = attach_dpe(mutations, dpe_lookup(fetcher, code_insee))
    delete_mutations(conn, code_insee, millesime)
    inserees = insert_mutations(conn, mutations)
    appariees = sum(1 for mutation in mutations if mutation.etiquette_dpe)
    return ResultatIngestionCommune(inserees=inserees, appariees=appariees)

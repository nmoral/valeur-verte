"""Orchestration de l'ingestion DVF : récupération puis écriture en base.

Point de composition entre le client HTTP (``dvf_source``) et le writer
(``db.vente_repository``). La transaction est gérée par l'appelant (la connexion
est committée à la sortie du ``with psycopg.connect(...)``).
"""

from __future__ import annotations

from .db.runner import Connection
from .db.vente_repository import insert_mutations
from .dvf_source import fetch_mutations
from .http import Fetcher


def ingest_commune(fetcher: Fetcher, conn: Connection, code_insee: str, millesime: int) -> int:
    """Récupère les mutations DVF d'une commune et les écrit dans ``data.vente``."""
    mutations = fetch_mutations(fetcher, code_insee, millesime)
    return insert_mutations(conn, mutations)

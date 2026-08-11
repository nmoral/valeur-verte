"""Orchestration de l'ingestion DVF : récupération puis écriture en base.

Point de composition entre le client HTTP (``dvf_source``) et le writer
(``db.vente_repository``). La transaction est gérée par l'appelant (la connexion
est committée à la sortie du ``with psycopg.connect(...)``).
"""

from __future__ import annotations

from .cleaning import clean_mutations
from .db.runner import Connection
from .db.vente_repository import delete_mutations, insert_mutations
from .dvf_source import fetch_mutations
from .http import Fetcher


def ingest_commune(fetcher: Fetcher, conn: Connection, code_insee: str, millesime: int) -> int:
    """Récupère les mutations DVF d'une commune, les nettoie (§7) et les écrit.

    Le contenu existant de la commune pour ce millésime est remplacé : sans
    contrainte d'unicité en base, un ré-import y ajouterait sinon une seconde
    copie de chaque transaction (#91).
    """
    mutations = clean_mutations(fetch_mutations(fetcher, code_insee, millesime))
    delete_mutations(conn, code_insee, millesime)
    return insert_mutations(conn, mutations)

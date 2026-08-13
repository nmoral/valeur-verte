"""Appartenance EPCI des communes (#169) : stockage et interrogation.

Le cran EPCI de la cascade de couverture regroupe les ventes des communes d'un
même EPCI quand le niveau communal ne suffit pas. Ce module ne porte que l'accès ;
l'appartenance vient du référentiel geo.api.gouv.fr (champ ``codeEpci``).
"""

from __future__ import annotations

from .runner import Connection

_UPSERT = (
    "INSERT INTO data.commune_epci (code_insee, code_epci) VALUES (%s, %s) "
    "ON CONFLICT (code_insee) DO UPDATE SET code_epci = EXCLUDED.code_epci"
)

# Communes partageant l'EPCI de la commune interrogée (elle incluse) ; ordre
# canonique pour un résultat reproductible (INV-5).
_COMMUNES_MEME_EPCI = (
    "SELECT code_insee FROM data.commune_epci WHERE code_epci = "
    "(SELECT code_epci FROM data.commune_epci WHERE code_insee = %s) "
    "ORDER BY code_insee"
)


def upsert_epci(conn: Connection, code_insee: str, code_epci: str) -> None:
    """Enregistre ou met à jour l'EPCI d'une commune (idempotent à la ré-ingestion)."""
    conn.execute(_UPSERT, [code_insee, code_epci])


def communes_de_meme_epci(conn: Connection, code_insee: str) -> tuple[str, ...]:
    """Codes INSEE des communes de l'EPCI de ``code_insee``, la commune incluse.

    Vide si la commune est inconnue (aucun EPCI enregistré).
    """
    rows = conn.execute(_COMMUNES_MEME_EPCI, [code_insee]).fetchall()
    return tuple(str(row[0]).strip() for row in rows)

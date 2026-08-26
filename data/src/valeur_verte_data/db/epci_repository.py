"""Appartenance EPCI des communes (#169) : stockage et interrogation.

Le cran EPCI de la cascade de couverture regroupe les ventes des communes d'un
même EPCI quand le niveau communal ne suffit pas. Ce module ne porte que l'accès ;
l'appartenance vient du référentiel geo.api.gouv.fr (champ ``codeEpci``).
"""

from __future__ import annotations

from collections.abc import Iterable

from ..decoupage import Commune
from .runner import Connection

_UPSERT = (
    "INSERT INTO data.commune_epci (code_insee, code_epci) VALUES (%s, %s) "
    "ON CONFLICT (code_insee) DO UPDATE SET code_epci = EXCLUDED.code_epci"
)

# Référentiel des noms d'EPCI (#366) : source du slug d'URL du hub EPCI côté app.
# Rechargé à chaque ingestion → idempotent, et un renommage (fusion) doit être repris.
_UPSERT_NOM = (
    "INSERT INTO data.epci (code_epci, nom) VALUES (%s, %s) "
    "ON CONFLICT (code_epci) DO UPDATE SET nom = EXCLUDED.nom, maj_le = now()"
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


def upsert_epci_noms(conn: Connection, communes: Iterable[Commune]) -> int:
    """Enregistre le nom de chaque EPCI porté par les communes. Rend le nombre
    d'EPCI écrits (distincts).

    Les communes d'un même EPCI portent le même nom : on dédoublonne par
    ``code_epci`` pour n'écrire chaque EPCI qu'une fois. Une entrée sans code ou
    sans nom d'EPCI est ignorée plutôt que de faire échouer l'ingestion — le nom
    d'EPCI est auxiliaire (comme les contours).
    """
    noms: dict[str, str] = {}
    for commune in communes:
        code_epci = commune.code_epci
        nom = (commune.nom_epci or "").strip()
        if code_epci is None or code_epci == "" or nom == "":
            continue
        noms.setdefault(code_epci, nom)

    for code_epci, nom in noms.items():
        conn.execute(_UPSERT_NOM, [code_epci, nom])

    return len(noms)


def communes_de_meme_epci(conn: Connection, code_insee: str) -> tuple[str, ...]:
    """Codes INSEE des communes de l'EPCI de ``code_insee``, la commune incluse.

    Vide si la commune est inconnue (aucun EPCI enregistré).
    """
    rows = conn.execute(_COMMUNES_MEME_EPCI, [code_insee]).fetchall()
    return tuple(str(row[0]).strip() for row in rows)

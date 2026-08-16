"""Référentiel des communes (#220) : nom lisible à partir d'un code INSEE.

Alimenté à l'ingestion, qui récupère déjà la liste complète des communes du
département — noms compris — pour piloter sa boucle. Lu par l'app en SELECT seul
(décision D6) pour afficher autre chose que des codes bruts.
"""

from __future__ import annotations

from collections.abc import Iterable

from ..decoupage import Commune
from .runner import Connection

# Le référentiel est rechargé à chaque ingestion : l'écriture doit être idempotente,
# et reprendre un éventuel renommage de commune (les fusions sont fréquentes).
_UPSERT = (
    "INSERT INTO data.commune (code_insee, nom, code_departement) "
    "VALUES (%s, %s, %s) "
    "ON CONFLICT (code_insee) DO UPDATE SET "
    "nom = EXCLUDED.nom, code_departement = EXCLUDED.code_departement, maj_le = now()"
)


def upsert_communes(conn: Connection, communes: Iterable[Commune]) -> int:
    """Enregistre le nom de chaque commune. Rend le nombre de lignes écrites.

    Une entrée incomplète du référentiel est ignorée plutôt que de faire échouer
    toute l'ingestion : mieux vaut un nom manquant qu'un département non chargé.
    """
    ecrites = 0
    for commune in communes:
        nom = commune.nom.strip()
        departement = commune.code_departement
        if nom == "" or None is departement:
            continue

        conn.execute(_UPSERT, [commune.code_insee, nom, departement])
        ecrites += 1

    return ecrites

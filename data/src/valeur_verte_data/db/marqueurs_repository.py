"""Écriture des marqueurs territoriaux (#341) dans ``data.marqueurs``.

Alimenté au chargement des référentiels contextuels (et, plus tard, au batch de
calcul pour les marqueurs mesurés). L'écriture est **idempotente** : un référentiel
figé peut être rechargé sans dupliquer ni périmer (ON CONFLICT sur la cible).
Lu par l'app en SELECT seul (décision D6) pour le bloc « Pourquoi ici ? ».
"""

from __future__ import annotations

from collections.abc import Iterable

from ..marqueurs import Marqueur
from .runner import Connection

# NULLS NOT DISTINCT (migration 021) : la clé nulle de la portée absente compte
# comme une valeur, donc un même (cible, nom) ne peut coexister en double — le
# rechargement d'un référentiel met à jour la valeur au lieu d'insérer.
_UPSERT = (
    "INSERT INTO data.marqueurs "
    "(code_insee, code_departement, nom, valeur, type_marqueur, source) "
    "VALUES (%s, %s, %s, %s, %s, %s) "
    "ON CONFLICT (code_insee, code_departement, nom) DO UPDATE SET "
    "valeur = EXCLUDED.valeur, type_marqueur = EXCLUDED.type_marqueur, "
    "source = EXCLUDED.source, maj_le = now()"
)


def upsert_marqueurs(conn: Connection, marqueurs: Iterable[Marqueur]) -> int:
    """Enregistre chaque marqueur. Rend le nombre de lignes écrites.

    Une entrée incomplète ou de portée ambiguë est ignorée (voir
    :meth:`Marqueur.est_ecrivable`) : mieux vaut un marqueur manquant qu'un
    département entier non chargé.
    """
    ecrites = 0
    for marqueur in marqueurs:
        if not marqueur.est_ecrivable():
            continue

        conn.execute(
            _UPSERT,
            [
                marqueur.code_insee,
                marqueur.code_departement,
                marqueur.nom,
                marqueur.valeur,
                marqueur.type_marqueur,
                marqueur.source,
            ],
        )
        ecrites += 1

    return ecrites

"""Publication d'un millésime — REG-2 (#130).

Publier ne recalcule rien : c'est une **projection** de
``data.coefficient_maille_historique`` vers la table vive ``data.coefficient_maille``,
décidée explicitement depuis l'admin interne et tracée.

La logique vit dans des fonctions SQL (migration 012) plutôt qu'ici : l'app Symfony
est en lecture seule sur le schéma ``data`` (décision D6) et ne reçoit que le droit
de les invoquer. Ce module est le pendant Python, pour la CLI et les tests.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .runner import Connection

_PUBLIER_MILLESIME = "SELECT data.publier_millesime(%s::smallint, %s)"
_ABANDONNER_MILLESIME = "SELECT data.abandonner_millesime(%s::smallint, %s)"
_PUBLIER_MAILLE = "SELECT data.publier_maille(%s::char(5), %s::smallint, %s)"

# Un millésime « calculé » n'a pas de ligne de décision : l'état est alors NULL.
_LIRE_MILLESIMES = (
    "SELECT h.millesime, p.etat, p.decide_par, p.decide_le, "
    "COUNT(*) AS calculees, "
    "COUNT(*) FILTER (WHERE h.classe_ecart = 'blocage') AS bloquees, "
    "COUNT(*) FILTER (WHERE h.classe_ecart = 'alerte') AS en_alerte "
    "FROM data.coefficient_maille_historique h "
    "LEFT JOIN data.publication_millesime p ON p.millesime = h.millesime "
    "GROUP BY h.millesime, p.etat, p.decide_par, p.decide_le "
    "ORDER BY h.millesime DESC"
)

_LIRE_MAILLES_BLOQUEES = (
    "SELECT code_insee, ecart, ecart_coefficient "
    "FROM data.coefficient_maille_historique "
    "WHERE millesime = %s AND classe_ecart = 'blocage' "
    "ORDER BY ecart DESC"
)


def _compte(rows: list[tuple[Any, ...]]) -> int:
    """Premier entier rendu par une fonction SQL, ou 0 si elle n'a rien rendu."""
    return int(rows[0][0]) if rows and rows[0] else 0


@dataclass(frozen=True)
class EtatMillesime:
    """Ce qu'il faut savoir d'un millésime pour décider de le publier."""

    millesime: int
    etat: str | None
    decide_par: str | None
    decide_le: object | None
    mailles_calculees: int
    mailles_bloquees: int
    mailles_en_alerte: int


@dataclass(frozen=True)
class MailleBloquee:
    """Maille écartée par REG-1, candidate à un déblocage après examen."""

    code_insee: str
    ecart: float
    coefficient: str


def publier_millesime(conn: Connection, millesime: int, auteur: str) -> int:
    """Projette le millésime vers la table vive. Rend le nombre de mailles servies.

    Les mailles en écart de blocage (REG-1) sont écartées : elles gardent la valeur
    du millésime précédent jusqu'à un déblocage explicite.
    """
    return _compte(conn.execute(_PUBLIER_MILLESIME, [millesime, auteur]).fetchall())


def abandonner_millesime(conn: Connection, millesime: int, auteur: str) -> int:
    """Écarte un millésime sans effet de bord : l'archive reste, la table vive aussi.

    C'est une décision tracée, pas une absence de décision — sans elle le millésime
    resterait indéfiniment proposé à la publication.
    """
    return _compte(conn.execute(_ABANDONNER_MILLESIME, [millesime, auteur]).fetchall())


def publier_maille(conn: Connection, code_insee: str, millesime: int, auteur: str) -> int:
    """Débloque une maille écartée par REG-1, après examen.

    Refusé tant que le millésime lui-même n'est pas publié (garde portée par la
    fonction SQL) : une commune seule en N au milieu d'un parc en N-1 serait
    exactement l'état mixte que REG-2 cherche à éviter.
    """
    return _compte(conn.execute(_PUBLIER_MAILLE, [code_insee, millesime, auteur]).fetchall())


def lire_millesimes(conn: Connection) -> list[EtatMillesime]:
    """Millésimes archivés, du plus récent au plus ancien, avec leur décision."""
    return [
        EtatMillesime(
            millesime=int(row[0]),
            etat=row[1],
            decide_par=row[2],
            decide_le=row[3],
            mailles_calculees=int(row[4]),
            mailles_bloquees=int(row[5]),
            mailles_en_alerte=int(row[6]),
        )
        for row in conn.execute(_LIRE_MILLESIMES).fetchall()
    ]


def lire_mailles_bloquees(conn: Connection, millesime: int) -> list[MailleBloquee]:
    """Mailles écartées par REG-1 pour ce millésime, écart le plus fort d'abord.

    L'écart chiffré et le coefficient fautif accompagnent chaque ligne : sans eux
    l'opérateur arbitrerait à l'aveugle.
    """
    return [
        MailleBloquee(code_insee=row[0], ecart=float(row[1]), coefficient=row[2])
        for row in conn.execute(_LIRE_MAILLES_BLOQUEES, [millesime]).fetchall()
    ]

"""Scan de ``data.vente`` pour l'audit de validité (read-only).

Alimente le cœur pur (``controle_qualite``) : projette les ventes en
``LigneVente`` et les passe à ``controler_ligne`` (par département, volume borné),
puis remonte les groupes de doublons via une requête d'agrégation dédiée. Ne
modifie **jamais** le schéma ``data`` (contrat D6, lecture seule).

Le filtre département s'appuie sur le préfixe du ``code_insee`` (``LIKE '31%'``) —
même unité de travail que le reste du pipeline (#91). Les doublons sont cherchés
sur toutes les copies de la mutation dupliquée : un chevauchement
inter-départements n'apparaît en entier qu'avec un audit **global** (``--tous``).
"""

from __future__ import annotations

from typing import Any

from ..controle_qualite import (
    DEFAULT_CONFIG,
    Anomalie,
    ControleConfig,
    LigneVente,
    RapportControle,
    construire_rapport,
    controler_ligne,
    departements_de,
    detecter_doublons,
)
from .runner import Connection

_COLONNES = (
    "id, id_mutation, millesime, code_insee, date_mutation, prix, surface_bati, "
    "surface_terrain, prix_m2, etiquette_dpe, longitude, latitude, geom IS NOT NULL"
)


def auditer(
    conn: Connection,
    *,
    departement: str | None = None,
    millesime: int | None = None,
    config: ControleConfig = DEFAULT_CONFIG,
) -> RapportControle:
    """Audite ``data.vente`` (filtré au besoin) et rend un rapport de validité.

    Chaque ligne passe aux contrôles par ligne ; les doublons sont ajoutés à part.
    L'orphelinage n'est jugé que dans les **départements couverts** par le référentiel
    (dérivés une fois de ``data.commune``) : un département non chargé n'est pas peuplé
    d'orphelins, et un référentiel vide rend le contrôle totalement muet.
    """
    where, params = _filtre(departement, millesime)
    communes_connues = charger_communes_connues(conn) or None
    departements_references = (
        departements_de(communes_connues) if communes_connues is not None else None
    )

    rows = conn.execute(f"SELECT {_COLONNES} FROM data.vente{where}", params).fetchall()
    anomalies: list[Anomalie] = []
    for row in rows:
        ligne = _vers_ligne(row)
        anomalies.extend(
            controler_ligne(
                ligne,
                communes_connues=communes_connues,
                departements_references=departements_references,
                config=config,
            )
        )
    anomalies.extend(_doublons(conn, where, params))

    return construire_rapport(anomalies, lignes_controlees=len(rows))


def charger_communes_connues(conn: Connection) -> frozenset[str]:
    """Codes INSEE du référentiel ``data.commune`` (pour le contrôle d'orphelinage)."""
    rows = conn.execute("SELECT code_insee FROM data.commune").fetchall()
    return frozenset(_texte(row[0]) for row in rows if row[0] is not None)


def _filtre(departement: str | None, millesime: int | None) -> tuple[str, list[Any]]:
    """Construit la clause ``WHERE`` et ses paramètres selon les filtres fournis."""
    clauses: list[str] = []
    params: list[Any] = []
    if departement is not None:
        clauses.append("code_insee LIKE %s")
        params.append(f"{departement}%")
    if millesime is not None:
        clauses.append("millesime = %s")
        params.append(millesime)
    where = f" WHERE {' AND '.join(clauses)}" if clauses else ""
    return where, params


def _doublons(conn: Connection, where: str, params: list[Any]) -> tuple[Anomalie, ...]:
    """Anomalies de doublon : charge les lignes des groupes ``(id_mutation, millesime)``
    de cardinal > 1 (dans le périmètre filtré) et délègue la qualification au cœur pur.
    """
    sous_requete = (
        f"SELECT id_mutation, millesime FROM data.vente{where} "
        "GROUP BY id_mutation, millesime HAVING count(*) > 1"
    )
    rows = conn.execute(
        f"SELECT {_COLONNES} FROM data.vente WHERE (id_mutation, millesime) IN ({sous_requete})",
        params,
    ).fetchall()
    return detecter_doublons(_vers_ligne(row) for row in rows)


def _vers_ligne(row: tuple[Any, ...]) -> LigneVente:
    """Projette une ligne SQL en ``LigneVente`` (Decimal → float, date → ISO)."""
    return LigneVente(
        id=row[0],
        id_mutation=row[1],
        millesime=row[2],
        code_insee=_texte(row[3]),
        date_mutation=row[4].isoformat() if row[4] is not None else None,
        prix=_reel(row[5]),
        surface_bati=_reel(row[6]),
        surface_terrain=_reel(row[7]),
        prix_m2=_reel(row[8]),
        etiquette_dpe=_texte(row[9]) if row[9] is not None else None,
        longitude=row[10],
        latitude=row[11],
        a_geom=bool(row[12]),
    )


def _reel(valeur: Any) -> float | None:
    """Convertit un ``NUMERIC`` (Decimal) en ``float``, en préservant ``None``."""
    return None if valeur is None else float(valeur)


def _texte(valeur: Any) -> str:
    """Normalise un ``CHAR(n)`` (potentiellement complété d'espaces) en texte nu."""
    return str(valeur).strip()

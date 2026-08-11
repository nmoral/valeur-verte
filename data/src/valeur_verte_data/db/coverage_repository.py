"""Lecture/écriture de l'état de couverture et journal des requêtes hors zone.

S'appuie sur les tables de la migration 005 (§3.2). L'orchestration passe par la
``Connection`` structurelle de ``runner`` : testable hors-ligne, exécutée en CI
contre un vrai PostGIS.
"""

from __future__ import annotations

from .runner import Connection

_SELECT_STATUSES = "SELECT code_departement, statut FROM data.couverture_departement"
_UPSERT_STATUS = (
    "INSERT INTO data.couverture_departement (code_departement, statut) VALUES (%s, %s) "
    "ON CONFLICT (code_departement) DO UPDATE SET statut = EXCLUDED.statut, maj_le = now()"
)
_LOG_HORS_ZONE = "INSERT INTO data.requete_hors_zone (code_insee, code_postal) VALUES (%s, %s)"


def department_statuses(conn: Connection) -> dict[str, str]:
    """Statut de couverture par code département."""
    rows = conn.execute(_SELECT_STATUSES).fetchall()
    return {str(row[0]).strip(): str(row[1]) for row in rows}


def set_department_status(conn: Connection, code_departement: str, statut: str) -> None:
    """Crée ou met à jour le statut de couverture d'un département."""
    conn.execute(_UPSERT_STATUS, [code_departement, statut])


def log_out_of_zone(conn: Connection, code_insee: str, code_postal: str | None = None) -> None:
    """Enregistre une requête sur une zone non couverte (signal de priorisation)."""
    conn.execute(_LOG_HORS_ZONE, [code_insee, code_postal])

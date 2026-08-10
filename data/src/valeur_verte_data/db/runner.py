"""Application idempotente des migrations SQL du schéma `data`.

Les versions déjà appliquées sont suivies dans ``public.schema_migrations`` : à
chaque exécution, seules les migrations manquantes sont rejouées, dans l'ordre.
L'orchestration ne dépend pas de ``psycopg`` (elle travaille sur une petite
``Connection`` structurelle), ce qui la rend testable hors-ligne ; la connexion
réelle est ouverte par la CLI (``python -m valeur_verte_data.db.migrate``).
"""

from __future__ import annotations

from collections.abc import Collection, Mapping, Sequence
from typing import Any, Protocol

from .migrations import Migration, load_migrations

_MIGRATIONS_TABLE = (
    "CREATE TABLE IF NOT EXISTS public.schema_migrations ("
    "version integer PRIMARY KEY, "
    "name text NOT NULL, "
    "applied_at timestamptz NOT NULL DEFAULT now())"
)
_SELECT_APPLIED = "SELECT version FROM public.schema_migrations"
_INSERT_APPLIED = "INSERT INTO public.schema_migrations (version, name) VALUES (%s, %s)"


class _Cursor(Protocol):
    def fetchall(self) -> list[tuple[Any, ...]]: ...


class Connection(Protocol):
    """Sous-ensemble de l'API psycopg utilisé par le runner."""

    def execute(self, query: str, params: Sequence[Any] | None = ...) -> _Cursor: ...


def pending_migrations(
    migrations: Sequence[Migration], applied: Collection[int]
) -> list[Migration]:
    """Migrations non encore appliquées, dans l'ordre reçu."""
    return [migration for migration in migrations if migration.version not in applied]


def applied_versions(conn: Connection) -> set[int]:
    """Versions déjà appliquées, lues dans ``public.schema_migrations``."""
    rows = conn.execute(_SELECT_APPLIED).fetchall()
    return {int(row[0]) for row in rows}


def apply_migrations(conn: Connection, migrations: Sequence[Migration] | None = None) -> list[int]:
    """Applique les migrations manquantes et retourne les versions appliquées."""
    to_consider = load_migrations() if migrations is None else migrations
    conn.execute(_MIGRATIONS_TABLE)
    pending = pending_migrations(to_consider, applied_versions(conn))
    for migration in pending:
        conn.execute(migration.sql)
        conn.execute(_INSERT_APPLIED, [migration.version, migration.name])
    return [migration.version for migration in pending]


def resolve_dsn(env: Mapping[str, str]) -> str:
    """Résout la DSN PostgreSQL depuis ``DATABASE_URL``, sinon lève."""
    dsn = env.get("DATABASE_URL")
    if not dsn:
        raise RuntimeError("DATABASE_URL est requis pour appliquer les migrations.")
    return dsn

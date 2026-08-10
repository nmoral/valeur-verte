"""CLI d'application des migrations : ``python -m valeur_verte_data.db.migrate``.

Ouvre une connexion PostgreSQL (DSN dans ``DATABASE_URL``) et applique les
migrations manquantes. Le connecteur est injectable pour permettre de tester la
glue hors-ligne ; par défaut il s'appuie sur ``psycopg`` (importé paresseusement).
"""

from __future__ import annotations

import os
from collections.abc import Callable, Mapping
from contextlib import AbstractContextManager

from .runner import Connection, apply_migrations, resolve_dsn

Connector = Callable[[str], AbstractContextManager[Connection]]


def _psycopg_connect(dsn: str) -> AbstractContextManager[Connection]:
    import psycopg

    return psycopg.connect(dsn)


def run(env: Mapping[str, str], connect: Connector = _psycopg_connect) -> list[int]:
    """Résout la DSN, ouvre la connexion et applique les migrations manquantes."""
    with connect(resolve_dsn(env)) as conn:
        return apply_migrations(conn)


def main(env: Mapping[str, str] | None = None, connect: Connector = _psycopg_connect) -> int:
    """Point d'entrée CLI : applique les migrations et rend compte."""
    applied = run(os.environ if env is None else env, connect)
    if applied:
        print(f"Migrations appliquées : {', '.join(str(version) for version in applied)}")
    else:
        print("Base déjà à jour.")
    return 0


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())

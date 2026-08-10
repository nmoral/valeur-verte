"""Chargement des migrations SQL du schéma `data`.

Les migrations sont des fichiers ``NNN_slug.sql`` (ex. ``001_create_data_schema.sql``)
rangés dans le dossier ``migrations/`` du paquet. Le schéma `data` est la propriété
du pipeline Python ; l'application Symfony n'y accède qu'en **lecture seule** (§10.2, D6).
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path

MIGRATIONS_DIR = Path(__file__).resolve().parent / "migrations"

_FILENAME_RE = re.compile(r"^(\d+)_([a-z0-9_]+)\.sql$")


class MigrationError(Exception):
    """Migration mal nommée, vide, ou séquence de versions incohérente."""


@dataclass(frozen=True)
class Migration:
    """Une migration SQL versionnée."""

    version: int
    name: str
    sql: str


def _parse(path: Path) -> Migration:
    match = _FILENAME_RE.match(path.name)
    if match is None:
        raise MigrationError(f"Nom de migration invalide : {path.name!r}")
    sql = path.read_text(encoding="utf-8")
    if not sql.strip():
        raise MigrationError(f"Migration vide : {path.name!r}")
    return Migration(version=int(match.group(1)), name=match.group(2), sql=sql)


def _validate_sequence(migrations: list[Migration]) -> None:
    """Vérifie l'unicité et la contiguïté (depuis 1) des versions."""
    versions = [migration.version for migration in migrations]
    if len(set(versions)) != len(versions):
        raise MigrationError(f"Versions de migration dupliquées : {versions}")
    if versions and versions != list(range(1, len(versions) + 1)):
        raise MigrationError(f"Séquence de migrations non contiguë : {versions}")


def load_migrations(directory: Path | None = None) -> list[Migration]:
    """Charge les migrations d'un dossier, triées par version et validées.

    Valide que les versions sont **uniques** et forment une **séquence contiguë
    à partir de 1**. Un dossier sans fichier ``.sql`` renvoie une liste vide.
    """
    base = MIGRATIONS_DIR if directory is None else directory
    migrations = sorted(
        (_parse(path) for path in base.glob("*.sql")),
        key=lambda migration: migration.version,
    )
    _validate_sequence(migrations)
    return migrations

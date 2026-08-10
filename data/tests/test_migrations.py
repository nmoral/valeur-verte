"""Tests du chargeur de migrations SQL — logique pure, sans base de données."""

from __future__ import annotations

from pathlib import Path

import pytest

from valeur_verte_data.db.migrations import (
    Migration,
    MigrationError,
    load_migrations,
)


def _write(directory: Path, filename: str, sql: str = "SELECT 1;") -> None:
    (directory / filename).write_text(sql, encoding="utf-8")


def test_charge_les_migrations_triees_par_version(tmp_path: Path) -> None:
    _write(tmp_path, "002_second.sql", "SELECT 2;")
    _write(tmp_path, "001_premier.sql", "SELECT 1;")

    migrations = load_migrations(tmp_path)

    assert [m.version for m in migrations] == [1, 2]
    assert [m.name for m in migrations] == ["premier", "second"]


def test_expose_version_nom_et_sql(tmp_path: Path) -> None:
    _write(tmp_path, "001_create_data_schema.sql", "CREATE SCHEMA data;")

    (migration,) = load_migrations(tmp_path)

    assert migration == Migration(version=1, name="create_data_schema", sql="CREATE SCHEMA data;")


def test_ignore_les_fichiers_non_sql(tmp_path: Path) -> None:
    _write(tmp_path, "001_ok.sql")
    (tmp_path / "README.md").write_text("pas une migration", encoding="utf-8")
    (tmp_path / "001_ok.sql.bak").write_text("SELECT 1;", encoding="utf-8")

    migrations = load_migrations(tmp_path)

    assert [m.version for m in migrations] == [1]


def test_rejette_un_nom_de_fichier_invalide(tmp_path: Path) -> None:
    _write(tmp_path, "abc_sans_version.sql")

    with pytest.raises(MigrationError):
        load_migrations(tmp_path)


def test_rejette_les_versions_dupliquees(tmp_path: Path) -> None:
    _write(tmp_path, "001_a.sql")
    _write(tmp_path, "001_b.sql")

    with pytest.raises(MigrationError):
        load_migrations(tmp_path)


def test_rejette_une_sequence_non_contigue(tmp_path: Path) -> None:
    _write(tmp_path, "001_a.sql")
    _write(tmp_path, "003_c.sql")  # 002 manquant

    with pytest.raises(MigrationError):
        load_migrations(tmp_path)


def test_rejette_une_sequence_ne_commencant_pas_a_un(tmp_path: Path) -> None:
    _write(tmp_path, "002_a.sql")

    with pytest.raises(MigrationError):
        load_migrations(tmp_path)


def test_rejette_une_migration_vide(tmp_path: Path) -> None:
    _write(tmp_path, "001_vide.sql", "   \n  ")

    with pytest.raises(MigrationError):
        load_migrations(tmp_path)


def test_repertoire_sans_migration_renvoie_liste_vide(tmp_path: Path) -> None:
    assert load_migrations(tmp_path) == []

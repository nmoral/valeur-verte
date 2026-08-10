"""Contrat du schéma `data` : invariants non négociables du MVP sur le DDL.

Ces tests vérifient le contenu des migrations SQL réelles du paquet (pas une base
de données live). Ils protègent les décisions du cadrage : PostGIS, table
``coefficient_maille`` (§10.6), table ``vente`` géolocalisée (§5.1) et contrat de
**lecture seule** pour l'application (§10.2, D6).
"""

from __future__ import annotations

import re

from valeur_verte_data.db.migrations import load_migrations


def _norm(sql: str) -> str:
    return re.sub(r"\s+", " ", sql.lower())


def _all_sql() -> str:
    return _norm(" ".join(m.sql for m in load_migrations()))


def _table_sql(needle: str) -> str:
    for migration in load_migrations():
        normalized = _norm(migration.sql)
        if needle in normalized:
            return normalized
    raise AssertionError(f"Aucune migration ne contient {needle!r}")


def test_migrations_reelles_chargeables() -> None:
    migrations = load_migrations()
    assert migrations
    assert [m.version for m in migrations] == list(range(1, len(migrations) + 1))


def test_cree_le_schema_data_et_active_postgis() -> None:
    sql = _all_sql()
    assert re.search(r"create schema (if not exists )?data", sql)
    assert re.search(r"create extension (if not exists )?postgis", sql)


def test_table_coefficient_maille_complete() -> None:
    sql = _table_sql("coefficient_maille")
    assert "create table" in sql
    for col in (
        "code_insee",
        "millesime",
        "rayon_retenu_km",
        "n_transactions",
        "r2",
        "erreur_mediane",
        "coef_m2_bati",
        "coef_m2_terrain",
        "coef_dpe",
        "ic_dpe",
        "calcule_le",
    ):
        assert col in sql, f"colonne manquante dans coefficient_maille : {col}"
    assert "coef_dpe jsonb" in sql
    assert "ic_dpe jsonb" in sql


def test_table_vente_geospatiale() -> None:
    sql = _table_sql("data.vente")
    # Jointure sur code_insee, jamais le code postal (§7).
    assert "code_insee" in sql
    assert "geography(point,4326)" in sql
    assert re.search(r"using gist \(geom\)", sql)


def test_contrat_lecture_seule_pour_l_application() -> None:
    sql = _all_sql()
    assert "valeur_verte_app" in sql
    assert re.search(r"grant usage on schema data to valeur_verte_app", sql)
    assert re.search(r"grant select on all tables in schema data to valeur_verte_app", sql)
    # Aucune permission d'écriture accordée à l'application.
    assert re.search(r"grant\s+(insert|update|delete|all)[^;]*valeur_verte_app", sql) is None

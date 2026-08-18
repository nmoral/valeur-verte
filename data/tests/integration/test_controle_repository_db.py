"""Test d'intégration de l'audit de validité : scan réel de ``data.vente``.

Le cœur pur (``controle_qualite``) est couvert hors-ligne ; ici on vérifie le
**câblage SQL** : que le repo projette bien les colonnes en ``LigneVente``, filtre
par département, remonte les doublons ``(id_mutation, millesime)`` et lit le
référentiel commune. Joué en CI contre PostGIS ; ignoré sans ``DATABASE_URL``.
"""

from __future__ import annotations

import os

import psycopg
import pytest

from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.controle_repository import auditer
from valeur_verte_data.db.jetable import exiger_base_jetable

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]


def _base_vide() -> psycopg.Connection:
    cli.run(os.environ)
    conn = psycopg.connect(DSN)
    exiger_base_jetable(os.environ)
    conn.execute("TRUNCATE data.vente")
    conn.execute("TRUNCATE data.commune")
    conn.commit()
    return conn


def _inserer_vente(
    conn: psycopg.Connection,
    *,
    id_mutation: str = "2024-000001",
    millesime: int = 2024,
    code_insee: str = "31555",
    date_mutation: str = "2024-05-01",
    prix: float | None = 250_000.0,
    surface_bati: float | None = 100.0,
    surface_terrain: float | None = 400.0,
    prix_m2: float | None = 2_500.0,
    etiquette_dpe: str | None = "D",
    longitude: float | None = 1.44,
    latitude: float | None = 43.6,
    avec_geom: bool = True,
) -> None:
    """Insère une ligne ``data.vente`` ; ``avec_geom=False`` laisse ``geom`` NULL."""
    geom = "ST_SetSRID(ST_MakePoint(%(lon)s, %(lat)s), 4326)::geography" if avec_geom else "NULL"
    conn.execute(
        "INSERT INTO data.vente ("
        "id_mutation, millesime, code_insee, date_mutation, prix, surface_bati, "
        "surface_terrain, prix_m2, etiquette_dpe, longitude, latitude, geom"
        ") VALUES ("
        "%(idm)s, %(mill)s, %(insee)s, %(date)s, %(prix)s, %(bati)s, %(terrain)s, "
        f"%(pm2)s, %(dpe)s, %(lon)s, %(lat)s, {geom})",
        {
            "idm": id_mutation,
            "mill": millesime,
            "insee": code_insee,
            "date": date_mutation,
            "prix": prix,
            "bati": surface_bati,
            "terrain": surface_terrain,
            "pm2": prix_m2,
            "dpe": etiquette_dpe,
            "lon": longitude,
            "lat": latitude,
        },
    )


def _inserer_commune(conn: psycopg.Connection, code_insee: str = "31555") -> None:
    conn.execute(
        "INSERT INTO data.commune (code_insee, nom, code_departement) VALUES (%s, %s, %s)",
        [code_insee, "Toulouse", code_insee[:2]],
    )


def _codes(rapport: object) -> set[str]:
    return {a.code for a in rapport.anomalies}  # type: ignore[attr-defined]


def test_ligne_saine_donne_un_rapport_valide() -> None:
    with _base_vide() as conn:
        _inserer_commune(conn)
        _inserer_vente(conn)
        conn.commit()
        rapport = auditer(conn, departement="31", millesime=2024)
        assert rapport.lignes_controlees == 1
        assert rapport.valide is True
        assert rapport.anomalies == ()


def test_detecte_prix_non_positif() -> None:
    with _base_vide() as conn:
        _inserer_commune(conn)
        _inserer_vente(conn, prix=0.0, prix_m2=None)
        conn.commit()
        rapport = auditer(conn, departement="31", millesime=2024)
        assert "prix_non_positif" in _codes(rapport)
        assert rapport.valide is False


def test_detecte_geom_manquant_quand_coordonnees_presentes() -> None:
    with _base_vide() as conn:
        _inserer_commune(conn)
        _inserer_vente(conn, avec_geom=False)
        conn.commit()
        rapport = auditer(conn, departement="31", millesime=2024)
        assert "geom_manquant" in _codes(rapport)


def test_detecte_doublon_de_mutation() -> None:
    # Deux lignes pour la même (id_mutation, millesime) : ré-ingestion / chevauchement.
    with _base_vide() as conn:
        _inserer_commune(conn)
        _inserer_vente(conn, id_mutation="2024-000042")
        _inserer_vente(conn, id_mutation="2024-000042")
        conn.commit()
        rapport = auditer(conn, departement="31", millesime=2024)
        assert "doublon" in _codes(rapport)


def test_commune_orpheline_du_referentiel() -> None:
    with _base_vide() as conn:
        _inserer_commune(conn, code_insee="31555")
        _inserer_vente(conn, code_insee="31999")  # absente du référentiel
        conn.commit()
        rapport = auditer(conn, departement="31", millesime=2024)
        assert "commune_orpheline" in _codes(rapport)


def test_referentiel_vide_ne_declenche_pas_l_orphelinage() -> None:
    # Aucune commune chargée : on ne peut juger l'appartenance → contrôle muet
    # (sinon toutes les ventes seraient signalées orphelines à tort).
    with _base_vide() as conn:
        _inserer_vente(conn)
        conn.commit()
        rapport = auditer(conn, departement="31", millesime=2024)
        assert "commune_orpheline" not in _codes(rapport)


def test_filtre_par_departement() -> None:
    with _base_vide() as conn:
        _inserer_commune(conn, code_insee="31555")
        _inserer_commune(conn, code_insee="09122")
        _inserer_vente(conn, id_mutation="2024-0031", code_insee="31555")
        _inserer_vente(conn, id_mutation="2024-0009", code_insee="09122")
        conn.commit()
        rapport = auditer(conn, departement="31", millesime=2024)
        assert rapport.lignes_controlees == 1

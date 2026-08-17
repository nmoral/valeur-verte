"""Tests de l'orchestration d'ingestion DVF (fetch → écriture, hors-ligne)."""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any

from valeur_verte_data.ingest import ingest_commune

_CSV = (
    "id_mutation,valeur_fonciere,code_commune,code_postal,surface_reelle_bati,"
    "adresse_numero,adresse_nom_voie,longitude,latitude\n"
    "2024-1,200000,31555,31000,80,12,RUE DES FLEURS,1.44,43.6\n"
)


class _FakeFetcher:
    def __init__(self, text: str) -> None:
        self.text = text

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        return self.text

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return {"results": []}  # commune sans DPE publié


class _FakeCursor:
    def fetchall(self) -> list[tuple[Any, ...]]:
        return []


class _FakeConnection:
    def __init__(self) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor()

    def inserts(self) -> list[tuple[str, Sequence[Any] | None]]:
        """Écritures seules : l'ingestion vide d'abord la commune (#91)."""
        return [appel for appel in self.calls if appel[0].lstrip().upper().startswith("INSERT")]

    def deletes(self) -> list[tuple[str, Sequence[Any] | None]]:
        return [appel for appel in self.calls if appel[0].lstrip().upper().startswith("DELETE")]


def test_ingest_commune_enchaine_fetch_et_ecriture() -> None:
    conn = _FakeConnection()

    resultat = ingest_commune(_FakeFetcher(_CSV), conn, "31555", 2024)

    assert resultat.inserees == 1
    assert len(conn.deletes()) == 1  # la commune est vidée avant d'être réécrite
    ecritures = conn.inserts()
    assert len(ecritures) == 1
    assert "data.vente" in ecritures[0][0]
    assert ecritures[0][1] is not None
    assert ecritures[0][1][2] == 2024  # millésime transmis


def test_ingest_commune_csv_vide_n_ecrit_rien() -> None:
    conn = _FakeConnection()

    resultat = ingest_commune(_FakeFetcher("id_mutation,valeur_fonciere\n"), conn, "31555", 2024)

    assert resultat.inserees == 0
    assert conn.inserts() == []
    # La suppression a bien lieu : un millésime redevenu vide doit vider la commune.
    assert len(conn.deletes()) == 1


_CSV_MIXTE = (
    "id_mutation,valeur_fonciere,code_commune,code_postal,surface_reelle_bati,"
    "adresse_numero,adresse_nom_voie,longitude,latitude\n"
    "propre,200000,31555,31000,80,12,RUE DES FLEURS,1.44,43.6\n"
    "aberrante,10,31555,31000,80,14,RUE DES FLEURS,1.44,43.6\n"
)


def test_ingest_commune_ecarte_les_transactions_aberrantes() -> None:
    conn = _FakeConnection()

    resultat = ingest_commune(_FakeFetcher(_CSV_MIXTE), conn, "31555", 2024)

    assert resultat.inserees == 1
    assert len(conn.inserts()) == 1


_DPE_ADEME = {
    "results": [
        {
            "code_postal_ban": "31000",
            "adresse_ban": "12 RUE DES FLEURS 31000 TOULOUSE",
            "etiquette_dpe": "F",
            "periode_construction": "avant 1948",
        }
    ]
}


class _FetcherAvecDpe:
    """Sert le CSV DVF puis les DPE ADEME de la commune."""

    def __init__(self, csv: str) -> None:
        self.csv = csv

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        return self.csv

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return _DPE_ADEME


_CSV_DPE_PARTIEL = (
    "id_mutation,valeur_fonciere,code_commune,code_postal,surface_reelle_bati,"
    "adresse_numero,adresse_nom_voie,longitude,latitude\n"
    "avec-dpe,200000,31555,31000,80,12,RUE DES FLEURS,1.44,43.6\n"
    "sans-dpe,200000,31555,31000,80,5,AVENUE DU PARC,1.45,43.61\n"
)


def test_ingest_commune_compte_les_ventes_inserees_et_appariees() -> None:
    """DAT-3 (#131) : le rapport doit distinguer ventes insérées et ventes appariées.

    Le fichier ADEME ne couvre que « 12 RUE DES FLEURS » : la seconde vente est
    insérée mais sans étiquette DPE. Le taux d'appariement se lit sur ces deux
    comptes.
    """
    conn = _FakeConnection()

    resultat = ingest_commune(_FetcherAvecDpe(_CSV_DPE_PARTIEL), conn, "31555", 2024)

    assert resultat.inserees == 2
    assert resultat.appariees == 1


def test_ingest_commune_apparie_les_dpe() -> None:
    """Sans étiquette DPE, le moteur écarte la vente : l'ingestion serait stérile.

    `_eligible` (regression) exige une étiquette et une période de construction ;
    une base chargée sans appariement produit zéro coefficient.
    """
    conn = _FakeConnection()

    ingest_commune(_FetcherAvecDpe(_CSV), conn, "31555", 2024)

    ecritures = conn.inserts()
    assert len(ecritures) == 1
    params = ecritures[0][1]
    assert params is not None
    assert "F" in list(params), "l'étiquette DPE doit être écrite avec la vente"

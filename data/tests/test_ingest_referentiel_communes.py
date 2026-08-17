"""L'ingestion alimente la table de référence des communes (#220).

C'est le moment naturel : `ingest_departement` récupère déjà la liste complète des
communes du département, noms compris, pour piloter la boucle d'ingestion.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any

import pytest

from valeur_verte_data import orchestration
from valeur_verte_data.decoupage import Commune
from valeur_verte_data.ingest import ResultatIngestionCommune
from valeur_verte_data.schema_source import SchemaSourceError

_COMMUNES = [
    Commune(
        code_insee="31555",
        nom="Toulouse",
        code_epci=None,
        codes_postaux=(),
        centre_longitude=1.44,
        centre_latitude=43.6,
        code_departement="31",
    ),
    Commune(
        code_insee="31149",
        nom="Colomiers",
        code_epci=None,
        codes_postaux=(),
        centre_longitude=1.33,
        centre_latitude=43.61,
        code_departement="31",
    ),
]


class _FauxFetcher:
    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return []

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        raise AssertionError("non utilisé")


class _Curseur:
    def fetchall(self) -> list[tuple[Any, ...]]:
        return []


class _FausseConnexion:
    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        return _Curseur()

    def commit(self) -> None:
        pass

    def rollback(self) -> None:
        pass


def test_l_ingestion_persiste_le_referentiel_des_communes(monkeypatch: pytest.MonkeyPatch) -> None:
    persistees: list[list[Commune]] = []
    _brancher(monkeypatch)
    monkeypatch.setattr(
        orchestration, "upsert_communes", lambda conn, communes: persistees.append(list(communes))
    )

    orchestration.ingest_departement(_FauxFetcher(), _FausseConnexion(), "31", 2024)

    assert [c.code_insee for c in persistees[0]] == ["31555", "31149"]
    assert [c.nom for c in persistees[0]] == ["Toulouse", "Colomiers"]


def test_le_rapport_compte_les_communes_referencees(monkeypatch: pytest.MonkeyPatch) -> None:
    _brancher(monkeypatch)
    monkeypatch.setattr(
        orchestration, "upsert_communes", lambda conn, communes: len(list(communes))
    )

    rapport = orchestration.ingest_departement(_FauxFetcher(), _FausseConnexion(), "31", 2024)

    assert rapport.communes_referencees == 2
    assert "2 commune(s) référencée(s)" in rapport.resume()


def test_le_referentiel_est_ecrit_avant_la_boucle_d_ingestion(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Une ingestion interrompue en cours de route doit quand même laisser les noms
    en base : ils ne dépendent pas des ventes, et servent aux écrans dès la première
    maille calculée."""
    ordre: list[str] = []
    _brancher(monkeypatch)
    monkeypatch.setattr(
        orchestration, "upsert_communes", lambda conn, communes: ordre.append("referentiel")
    )
    monkeypatch.setattr(
        orchestration,
        "ingest_commune",
        lambda *a, **k: (ordre.append("vente"), ResultatIngestionCommune(0, 0))[1],
    )

    orchestration.ingest_departement(_FauxFetcher(), _FausseConnexion(), "31", 2024)

    assert ordre[0] == "referentiel"


def test_une_erreur_de_schema_interrompt_tout_le_departement(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """DAT-1 (#124) : une colonne source renommée est **systémique** (tout le
    département), pas une panne d'une commune. L'erreur de schéma ne doit pas être
    avalée par le try/except par-commune ni comptée comme un simple échec : elle
    interrompt l'ingestion pour que le calcul ne tourne pas sur des données muettes.
    """
    _brancher(monkeypatch)
    monkeypatch.setattr(orchestration, "upsert_communes", lambda conn, communes: 0)

    def _casse(*a: Any, **k: Any) -> ResultatIngestionCommune:
        raise SchemaSourceError("DVF", frozenset({"valeur_fonciere"}))

    monkeypatch.setattr(orchestration, "ingest_commune", _casse)

    with pytest.raises(SchemaSourceError):
        orchestration.ingest_departement(_FauxFetcher(), _FausseConnexion(), "31", 2024)


def _brancher(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(
        orchestration, "fetch_communes_by_departement", lambda fetcher, dep: list(_COMMUNES)
    )
    monkeypatch.setattr(orchestration, "fetch_contours_by_departement", lambda fetcher, dep: [])
    monkeypatch.setattr(
        orchestration, "ingest_commune", lambda *a, **k: ResultatIngestionCommune(0, 0)
    )
    monkeypatch.setattr(orchestration, "upsert_epci", lambda conn, entries: 0)

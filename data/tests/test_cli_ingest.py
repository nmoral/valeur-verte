"""CLI d'ingestion par département (#91), testée hors-ligne.

Un département compte plusieurs centaines de communes, récupérées une par une
sur un service public. Deux exigences dominent l'orchestration :

- une commune en échec (absente de DVF, réseau instable) ne doit pas emporter
  les autres — sinon un import de 586 communes s'arrête à la première anomalie ;
- chaque commune est validée séparément, pour qu'une interruption laisse le
  travail déjà fait en base et qu'un rejeu reprenne sans tout refaire.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from datetime import datetime
from typing import Any

import httpx
import pytest

from valeur_verte_data import cli, orchestration
from valeur_verte_data.decoupage import Commune, CommuneContour

_COMMUNES = [
    {"code": "31555", "nom": "Toulouse", "codeDepartement": "31"},
    {"code": "31149", "nom": "Colomiers", "codeDepartement": "31"},
]

_CONTOURS = [
    CommuneContour(
        "31555", "31", '{"type":"Polygon","coordinates":[[[1,43],[1,44],[2,44],[1,43]]]}'
    ),
    CommuneContour(
        "31149", "31", '{"type":"Polygon","coordinates":[[[1,43],[1,44],[0,44],[1,43]]]}'
    ),
]

_CSV = (
    "id_mutation,valeur_fonciere,code_commune,code_postal,surface_reelle_bati,"
    "adresse_numero,adresse_nom_voie,longitude,latitude\n"
    "m1,200000,31555,31000,80,12,RUE DES FLEURS,1.44,43.6\n"
)


class _FauxFetcher:
    """Sert le référentiel des communes, puis un CSV DVF par commune."""

    def __init__(self, echecs: Sequence[str] = (), absentes: Sequence[str] = ()) -> None:
        self.echecs = set(echecs)
        self.absentes = set(absentes)
        self.communes_demandees: list[str] = []

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return _COMMUNES

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        code = url.rsplit("/", 1)[-1].removesuffix(".csv")
        self.communes_demandees.append(code)
        if code in self.absentes:
            requete = httpx.Request("GET", url)
            raise httpx.HTTPStatusError(
                "not found", request=requete, response=httpx.Response(404, request=requete)
            )
        if code in self.echecs:
            raise RuntimeError(f"panne réseau sur {code}")
        return _CSV


class _FausseConnexion:
    def __init__(self) -> None:
        self.requetes: list[str] = []
        self.commits = 0
        self.rollbacks = 0

    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        self.requetes.append(query)
        return None

    def commit(self) -> None:
        self.commits += 1

    def rollback(self) -> None:
        self.rollbacks += 1


@pytest.fixture
def connexion() -> _FausseConnexion:
    return _FausseConnexion()


def test_toutes_les_communes_du_departement_sont_traitees(
    connexion: _FausseConnexion,
) -> None:
    """Le département est déplié via le référentiel, pas saisi à la main."""
    fetcher = _FauxFetcher()

    rapport = orchestration.ingest_departement(fetcher, connexion, "31", 2024)

    assert fetcher.communes_demandees == ["31555", "31149"]
    assert rapport.communes_traitees == 2
    assert rapport.mutations_inserees == 2


def test_une_commune_en_echec_n_interrompt_pas_les_suivantes(
    connexion: _FausseConnexion,
) -> None:
    """Sinon une seule commune absente de DVF ferait échouer tout le département."""
    fetcher = _FauxFetcher(echecs=["31555"])

    rapport = orchestration.ingest_departement(fetcher, connexion, "31", 2024)

    assert fetcher.communes_demandees == ["31555", "31149"]
    assert rapport.communes_traitees == 1
    assert rapport.echecs == ["31555"]


def test_chaque_commune_est_validee_separement(connexion: _FausseConnexion) -> None:
    """Une interruption doit laisser en base le travail déjà accompli.

    Deux communes, donc deux validations — plus celle du référentiel des noms
    (#220), validé avant la boucle pour survivre lui aussi à une interruption.
    """
    orchestration.ingest_departement(_FauxFetcher(), connexion, "31", 2024)

    assert connexion.commits == 1 + 2


def test_une_commune_en_echec_est_annulee_sans_toucher_aux_autres(
    connexion: _FausseConnexion,
) -> None:
    """Sans annulation, la suppression déjà émise laisserait la commune vidée."""
    orchestration.ingest_departement(_FauxFetcher(echecs=["31555"]), connexion, "31", 2024)

    assert connexion.rollbacks == 1
    # Une seule commune aboutit, plus le référentiel des noms (#220).
    assert connexion.commits == 1 + 1


def test_la_commande_ingest_exige_departement_et_millesime() -> None:
    """Des arguments implicites conduiraient à écrire le mauvais millésime."""
    with pytest.raises(SystemExit):
        cli.main(["ingest", "--departement", "31"])


class _ConnexionContexte:
    """Fausse connexion psycopg : sert de gestionnaire de contexte et de connexion."""

    def __enter__(self) -> _ConnexionContexte:
        return self

    def __exit__(self, *_: Any) -> bool:
        return False

    def execute(self, query: str, params: Sequence[Any] | None = None) -> None:
        return None

    def commit(self) -> None:
        return None


def test_l_ingest_archive_le_rapport_de_batch(monkeypatch: pytest.MonkeyPatch) -> None:
    """#131 : chaque exécution d'ingestion archive son rapport (avec durée mesurée).

    La CLI possède l'horloge : elle borne l'exécution (debut/fin) autour de
    ``ingest_departement`` et confie l'archivage au repository.
    """
    import psycopg

    monkeypatch.setenv("DATABASE_URL", "postgresql://x")
    monkeypatch.setattr(psycopg, "connect", lambda *a, **k: _ConnexionContexte())
    rapport = orchestration.RapportIngestion(departement="31", millesime=2024)
    rapport.communes_traitees = 1
    monkeypatch.setattr(cli, "ingest_departement", lambda *a, **k: rapport)

    captures: dict[str, Any] = {}
    monkeypatch.setattr(
        cli,
        "enregistrer_rapport_ingestion",
        lambda conn, r, *, debut, fin: captures.update(rapport=r, debut=debut, fin=fin),
        raising=False,
    )

    cli.main(["ingest", "--departement", "31", "--millesime", "2024"])

    assert captures["rapport"] is rapport
    assert isinstance(captures["debut"], datetime)
    assert captures["debut"] <= captures["fin"]


def test_un_departement_entierement_en_echec_sort_en_erreur(
    connexion: _FausseConnexion,
) -> None:
    """Un import qui n'a rien chargé ne doit pas passer pour un succès."""
    rapport = orchestration.ingest_departement(
        _FauxFetcher(echecs=["31555", "31149"]), connexion, "31", 2024
    )

    assert rapport.communes_traitees == 0
    assert rapport.est_en_echec


def test_une_commune_absente_de_dvf_n_est_pas_un_echec(connexion: _FausseConnexion) -> None:
    """Une petite commune sans transaction rend un 404 : c'est une absence, pas une panne.

    Les confondre noierait un incident réel au milieu de dizaines d'absences
    normales — sur le seul département 31, elles sont 35.
    """
    rapport = orchestration.ingest_departement(
        _FauxFetcher(absentes=["31555"]), connexion, "31", 2024
    )

    assert rapport.sans_donnees == ["31555"]
    assert rapport.echecs == []
    assert rapport.communes_traitees == 1


def test_un_departement_sans_aucune_donnee_n_est_pas_un_succes(
    connexion: _FausseConnexion,
) -> None:
    """Aucune commune chargée reste un échec, même si toutes rendent un 404."""
    rapport = orchestration.ingest_departement(
        _FauxFetcher(absentes=["31555", "31149"]), connexion, "31", 2024
    )

    assert rapport.est_en_echec


def test_le_rapport_d_ingestion_porte_le_departement_et_le_millesime(
    connexion: _FausseConnexion,
) -> None:
    """Le rapport identifie sans ambiguïté le batch qu'il décrit."""
    rapport = orchestration.ingest_departement(_FauxFetcher(), connexion, "31", 2024)

    assert rapport.departement == "31"
    assert rapport.millesime == 2024


def test_ingest_departement_stocke_les_contours(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """INV-3 (#121) : l'ingestion charge aussi les contours, pour que la
    contiguïté soit calculable dès que le département et ses voisins sont là."""
    stockes: list[str] = []
    derivations: list[str] = []
    monkeypatch.setattr(orchestration, "fetch_contours_by_departement", lambda f, d: _CONTOURS)
    monkeypatch.setattr(
        orchestration,
        "upsert_contour",
        lambda conn, code_insee, code_departement, contour: stockes.append(code_insee),
    )
    monkeypatch.setattr(orchestration, "derive_limitrophes", lambda conn: derivations.append("x"))

    rapport = orchestration.ingest_departement(_FauxFetcher(), connexion, "31", 2024)

    assert stockes == ["31555", "31149"]
    assert rapport.contours_charges == 2
    # La contiguïté est dérivée une fois les contours stockés (INV-3 #3).
    assert derivations == ["x"]


def test_un_echec_de_contour_n_interrompt_pas_l_ingestion(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Les contours sont auxiliaires aux ventes DVF : leur échec ne doit pas
    faire capoter une ingestion de plusieurs heures (§5)."""

    def _boom(fetcher: Any, departement: str) -> Any:
        raise RuntimeError("geo.api indisponible")

    monkeypatch.setattr(orchestration, "fetch_contours_by_departement", _boom)

    rapport = orchestration.ingest_departement(_FauxFetcher(), connexion, "31", 2024)

    # Les ventes sont bien ingérées malgré l'échec des contours.
    assert rapport.communes_traitees == 2
    assert rapport.contours_charges == 0


def test_ingest_departement_charge_l_epci(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """L'ingestion enregistre aussi l'appartenance EPCI des communes (#169)."""
    communes = [
        Commune("31555", "Toulouse", "200001", (), 1.44, 43.6, "31"),
        Commune("31149", "Colomiers", "200001", (), 1.44, 43.6, "31"),
    ]
    enregistres: list[str] = []
    monkeypatch.setattr(orchestration, "fetch_communes_by_departement", lambda f, d: communes)
    monkeypatch.setattr(
        orchestration,
        "upsert_epci",
        lambda conn, code_insee, code_epci: enregistres.append(code_insee),
    )

    rapport = orchestration.ingest_departement(_FauxFetcher(), connexion, "31", 2024)

    assert enregistres == ["31555", "31149"]
    assert rapport.epci_charges == 2


def test_un_echec_epci_n_interrompt_pas_l_ingestion(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """L'EPCI est auxiliaire aux ventes : son échec ne fait pas capoter l'ingestion (§5)."""

    def _boom(conn: Any, code_insee: str, code_epci: str) -> None:
        raise RuntimeError("écriture EPCI impossible")

    communes = [Commune("31555", "Toulouse", "200001", (), 1.44, 43.6, "31")]
    monkeypatch.setattr(orchestration, "fetch_communes_by_departement", lambda f, d: communes)
    monkeypatch.setattr(orchestration, "upsert_epci", _boom)

    rapport = orchestration.ingest_departement(_FauxFetcher(), connexion, "31", 2024)

    assert rapport.communes_traitees == 1
    assert rapport.epci_charges == 0

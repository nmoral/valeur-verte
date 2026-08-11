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
from typing import Any

import httpx
import pytest

from valeur_verte_data import cli

_COMMUNES = [
    {"code": "31555", "nom": "Toulouse", "codeDepartement": "31"},
    {"code": "31149", "nom": "Colomiers", "codeDepartement": "31"},
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

    rapport = cli.ingest_departement(fetcher, connexion, "31", 2024)

    assert fetcher.communes_demandees == ["31555", "31149"]
    assert rapport.communes_traitees == 2
    assert rapport.mutations_inserees == 2


def test_une_commune_en_echec_n_interrompt_pas_les_suivantes(
    connexion: _FausseConnexion,
) -> None:
    """Sinon une seule commune absente de DVF ferait échouer tout le département."""
    fetcher = _FauxFetcher(echecs=["31555"])

    rapport = cli.ingest_departement(fetcher, connexion, "31", 2024)

    assert fetcher.communes_demandees == ["31555", "31149"]
    assert rapport.communes_traitees == 1
    assert rapport.echecs == ["31555"]


def test_chaque_commune_est_validee_separement(connexion: _FausseConnexion) -> None:
    """Une interruption doit laisser en base le travail déjà accompli."""
    cli.ingest_departement(_FauxFetcher(), connexion, "31", 2024)

    assert connexion.commits == 2


def test_une_commune_en_echec_est_annulee_sans_toucher_aux_autres(
    connexion: _FausseConnexion,
) -> None:
    """Sans annulation, la suppression déjà émise laisserait la commune vidée."""
    cli.ingest_departement(_FauxFetcher(echecs=["31555"]), connexion, "31", 2024)

    assert connexion.rollbacks == 1
    assert connexion.commits == 1


def test_la_commande_ingest_exige_departement_et_millesime() -> None:
    """Des arguments implicites conduiraient à écrire le mauvais millésime."""
    with pytest.raises(SystemExit):
        cli.main(["ingest", "--departement", "31"])


def test_un_departement_entierement_en_echec_sort_en_erreur(
    connexion: _FausseConnexion,
) -> None:
    """Un import qui n'a rien chargé ne doit pas passer pour un succès."""
    rapport = cli.ingest_departement(_FauxFetcher(echecs=["31555", "31149"]), connexion, "31", 2024)

    assert rapport.communes_traitees == 0
    assert rapport.est_en_echec


def test_une_commune_absente_de_dvf_n_est_pas_un_echec(connexion: _FausseConnexion) -> None:
    """Une petite commune sans transaction rend un 404 : c'est une absence, pas une panne.

    Les confondre noierait un incident réel au milieu de dizaines d'absences
    normales — sur le seul département 31, elles sont 35.
    """
    rapport = cli.ingest_departement(_FauxFetcher(absentes=["31555"]), connexion, "31", 2024)

    assert rapport.sans_donnees == ["31555"]
    assert rapport.echecs == []
    assert rapport.communes_traitees == 1


def test_un_departement_sans_aucune_donnee_n_est_pas_un_succes(
    connexion: _FausseConnexion,
) -> None:
    """Aucune commune chargée reste un échec, même si toutes rendent un 404."""
    rapport = cli.ingest_departement(
        _FauxFetcher(absentes=["31555", "31149"]), connexion, "31", 2024
    )

    assert rapport.est_en_echec

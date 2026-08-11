"""CLI de calcul des coefficients par département (#91, §10.6).

Le pool du département est chargé une seule fois, puis chaque commune est
calculée par le moteur et écrite dans ``coefficient_maille``. Une commune sous
le seuil est **refusée** : c'est un résultat normal du cadrage (§7), pas une
erreur, et le rapport doit les distinguer pour rester lisible.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import replace
from typing import Any

import pytest

from valeur_verte_data import cli
from valeur_verte_data.dvf import Mutation

_COMMUNES = [
    {
        "code": "31555",
        "nom": "Toulouse",
        "codeDepartement": "31",
        "centre": {"type": "Point", "coordinates": [1.44, 43.6]},
    },
    {
        "code": "31149",
        "nom": "Colomiers",
        "codeDepartement": "31",
        "centre": {"type": "Point", "coordinates": [1.33, 43.61]},
    },
]


def _vente(numero: int, dpe: str, prix: float) -> Mutation:
    return Mutation(
        id_mutation=f"m{numero}",
        date_mutation="2024-01-01",
        millesime=2024,
        code_insee="31555",
        code_postal=None,
        nom_commune=None,
        type_local="Maison",
        nombre_pieces_principales=None,
        prix=prix,
        surface_bati=80.0,
        surface_terrain=200.0,
        prix_m2=prix / 80.0,
        adresse_numero=None,
        adresse_suffixe=None,
        adresse_nom_voie=None,
        longitude=1.44,
        latitude=43.6,
        etiquette_dpe=dpe,
        periode_construction="1948-1974",
    )


def _vente_ariegeoise() -> Mutation:
    """Vente d'un département limitrophe, présente dans le pool mais non publiable."""
    vente = _vente(999, "D", 180000.0)
    return replace(vente, id_mutation="m-09", code_insee="09122")


# Assez de ventes pour dépasser le seuil local et permettre une régression.
_POOL = [_vente(i, "ABCDEFG"[i % 7], 200000 + i * 900) for i in range(120)]


class _FauxFetcher:
    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return _COMMUNES

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        raise AssertionError("le calcul ne récupère aucun fichier")


class _FausseConnexion:
    def __init__(self, pool: Sequence[Mutation]) -> None:
        self.pool = list(pool)
        self.requetes: list[str] = []
        self.ecritures: list[tuple[str, Sequence[Any] | None]] = []
        self.commits = 0
        self.rollbacks = 0

    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        self.requetes.append(query)
        if "INSERT" in query.upper():
            self.ecritures.append((query, params))
        return _Curseur([])

    def commit(self) -> None:
        self.commits += 1

    def rollback(self) -> None:
        self.rollbacks += 1


class _Curseur:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


@pytest.fixture
def connexion() -> _FausseConnexion:
    return _FausseConnexion(_POOL)


def test_le_pool_du_departement_n_est_charge_qu_une_fois(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Recharger par commune multiplierait les requêtes sans rien apporter (§10.6)."""
    chargements: list[str] = []

    def _charger(conn: Any, departement: str, millesime: int, **_: Any) -> list[Mutation]:
        chargements.append(departement)
        return _POOL

    monkeypatch.setattr(cli, "load_pool", _charger)

    cli.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert chargements == ["31"]


def test_le_pool_deborde_sur_les_departements_limitrophes(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Les communes du bord doivent voir les ventes d'en face (#18)."""
    appels: list[tuple[str, tuple[str, ...]]] = []

    def _charger(
        conn: Any, departement: str, millesime: int, *, limitrophes: tuple[str, ...] = ()
    ) -> list[Mutation]:
        appels.append((departement, limitrophes))
        return _POOL

    monkeypatch.setattr(cli, "load_pool", _charger)

    cli.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert appels == [("31", ("09", "11", "32", "65", "81", "82"))]


def test_seules_les_communes_du_departement_recoivent_un_coefficient(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Élargir le pool ne doit pas élargir la publication.

    Une commune limitrophe est dans le pool pour *nourrir* le calcul du 31,
    pas pour être calculée : son propre disque déborderait sur des
    départements non chargés, et son coefficient reposerait sur un échantillon
    amputé. La liste des communes vient du découpage administratif du
    département demandé, jamais du pool.
    """
    monkeypatch.setattr(cli, "load_pool", lambda *_, **__: _POOL + [_vente_ariegeoise()])

    cli.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    ecrits = [str(params[0]) for _, params in connexion.ecritures if params]
    assert ecrits, "le test ne prouve rien si rien n'est écrit"
    assert all(code.startswith("31") for code in ecrits), ecrits


def test_les_communes_calculees_sont_ecrites(
    connexion: _FausseConnexion, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Un coefficient calculé mais non écrit ne servirait à rien au widget."""
    monkeypatch.setattr(cli, "load_pool", lambda *_, **__: _POOL)

    rapport = cli.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert rapport.communes_calculees > 0
    assert len(connexion.ecritures) == rapport.communes_calculees
    assert connexion.commits >= 1


def test_une_commune_sous_le_seuil_est_refusee_sans_erreur(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Le refus sous seuil est un résultat du cadrage (§7), pas une panne."""
    connexion = _FausseConnexion([])
    monkeypatch.setattr(cli, "load_pool", lambda *_, **__: [])

    rapport = cli.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert rapport.communes_calculees == 0
    assert rapport.refus == ["31555", "31149"]
    assert rapport.echecs == []


def test_un_departement_sans_aucun_coefficient_sort_en_erreur(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Rien de calculé signale un pool vide ou un millésime erroné."""
    connexion = _FausseConnexion([])
    monkeypatch.setattr(cli, "load_pool", lambda *_, **__: [])

    rapport = cli.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert rapport.est_en_echec


def test_la_commande_compute_exige_departement_et_millesime() -> None:
    with pytest.raises(SystemExit):
        cli.main(["compute", "--departement", "31"])


def test_une_commune_devenue_non_calculable_perd_son_coefficient(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Sinon un coefficient périmé continue d'être servi (#115).

    Constaté sur le 31 : après durcissement du seuil, 203 mailles sont
    refusées mais la table en comptait toujours 586 — l'upsert ne supprime
    rien, donc les anciennes valeurs survivaient au recalcul qui les invalide.
    """
    connexion = _FausseConnexion([])
    monkeypatch.setattr(cli, "load_pool", lambda *_, **__: [])

    cli.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    suppressions = [r for r in connexion.requetes if r.strip().upper().startswith("DELETE")]
    assert len(suppressions) == 2, "chaque commune refusée doit voir son coefficient effacé"

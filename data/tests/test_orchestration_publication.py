"""REG-2 (#130) : le batch calcule et archive, il ne publie plus.

Un millésime n'est servi qu'après activation explicite depuis l'admin interne. Le
batch ne doit donc plus toucher la table vive ``coefficient_maille`` — ni pour
écrire une maille retenue, ni pour effacer une maille refusée. Sinon un millésime
calculé puis abandonné aurait déjà changé ce que voient les clients.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any

import pytest

from valeur_verte_data import orchestration
from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.degraded import CoverageMode, PalierFiabilite
from valeur_verte_data.dvf import Mutation
from valeur_verte_data.ecarts import ClasseEcart


class _FauxFetcher:
    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return [
            {
                "code": "31555",
                "nom": "Toulouse",
                "codeDepartement": "31",
                "codeEpci": "243100518",
                "codesPostaux": ["31000"],
                "centre": {"coordinates": [1.44, 43.60]},
            }
        ]

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        raise AssertionError("le calcul ne récupère aucun fichier")


class _Curseur:
    def fetchall(self) -> list[tuple[Any, ...]]:
        return []


class _FausseConnexion:
    def __init__(self) -> None:
        self.requetes: list[str] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> Any:
        self.requetes.append(query)
        return _Curseur()

    def commit(self) -> None:
        pass

    def rollback(self) -> None:
        pass


@pytest.fixture
def connexion() -> _FausseConnexion:
    return _FausseConnexion()


def _maille(
    code_insee: str,
    coef_m2_bati: float = 0.5,
    coef_dpe: dict[str, float] | None = None,
) -> MailleCoefficient:
    return MailleCoefficient(
        code_insee=code_insee,
        millesime=2024,
        palier=PalierFiabilite.STANDARD,
        mode=CoverageMode.LOCAL,
        rayon_retenu_km=10,
        n_transactions=40,
        r2=0.5,
        erreur_mediane=15.0,
        coef_m2_bati=coef_m2_bati,
        coef_m2_terrain=0.30,
        coef_dpe=coef_dpe if coef_dpe is not None else {"D": 0.0, "F": -0.20},
        ic_dpe={},
        alerte=None,
    )


def _vente_native(code_insee: str) -> Mutation:
    """Une vente propre au département, pour que le pool ne soit pas vide (#255)."""
    return Mutation(
        id_mutation="m1",
        date_mutation="2024-01-01",
        millesime=2024,
        code_insee=code_insee,
        code_postal="31000",
        nom_commune="Toulouse",
        type_local="Maison",
        nombre_pieces_principales=4,
        prix=200000.0,
        surface_bati=100.0,
        surface_terrain=500.0,
        prix_m2=2000.0,
        adresse_numero="1",
        adresse_suffixe=None,
        adresse_nom_voie="RUE",
        longitude=1.44,
        latitude=43.60,
        etiquette_dpe="D",
    )


def _touche_la_table_vive(requetes: list[str]) -> list[str]:
    """Requêtes visant ``coefficient_maille`` — l'historique porte un autre nom."""
    return [
        r
        for r in requetes
        if "data.coefficient_maille " in r.replace("(", " (")
        and "coefficient_maille_historique" not in r
    ]


def test_le_batch_n_ecrit_plus_la_table_vive(
    connexion: Any, monkeypatch: pytest.MonkeyPatch
) -> None:
    _brancher(monkeypatch, [_maille("31555")])

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert _touche_la_table_vive(connexion.requetes) == [], (
        "Le batch ne doit plus publier : REG-2 exige une activation explicite."
    )


def test_le_batch_n_efface_plus_la_table_vive(
    connexion: Any, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Une maille refusée ne disparaît pas du service tant que rien n'est publié.

    Sinon un millésime abandonné aurait quand même retiré des communes du service.
    """
    _brancher(monkeypatch, [])

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert _touche_la_table_vive(connexion.requetes) == []


def test_une_maille_refusee_est_enregistree_pour_la_publication(
    connexion: Any, monkeypatch: pytest.MonkeyPatch
) -> None:
    """Le refus est différé, pas perdu : c'est la publication qui retirera la
    commune du service, sans quoi une commune devenue non calculable continuerait
    d'être servie indéfiniment."""
    refus: list[tuple[str, int]] = []
    _brancher(monkeypatch, [])
    monkeypatch.setattr(
        orchestration,
        "enregistrer_refus",
        lambda conn, code, mil, motif: refus.append((code, mil)),
    )

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert refus == [("31555", 2024)]


def test_le_batch_archive_les_mailles_retenues(
    connexion: Any, monkeypatch: pytest.MonkeyPatch
) -> None:
    archivees: list[tuple[MailleCoefficient, ClasseEcart | None]] = []
    _brancher(monkeypatch, [_maille("31555")])
    monkeypatch.setattr(
        orchestration,
        "archiver_coefficient",
        lambda conn, maille, classe_ecart=None, ecart=None, ecart_coefficient=None: (
            archivees.append((maille, classe_ecart))
        ),
    )

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert [m.code_insee for m, _ in archivees] == ["31555"]


def test_l_archive_porte_la_classe_d_ecart(connexion: Any, monkeypatch: pytest.MonkeyPatch) -> None:
    """La publication doit pouvoir écarter les mailles bloquées par REG-1.

    Elles sont archivées comme les autres : sans classe d'écart portée sur
    l'archive, projeter « toutes les lignes du millésime » les publierait.
    """
    archivees: list[tuple[MailleCoefficient, ClasseEcart | None]] = []
    # Seul le DPE bloque (REG-1, #176) : l'effet F passe de exp(-0,20)=0,819 à
    # exp(-0,55)=0,577, soit ~30 % — au-delà du seuil de blocage (25 %). Les
    # coefficients restent dans les bornes INV-2, sinon la maille serait rejetée
    # avant d'être archivée.
    precedent = _maille("31555", coef_dpe={"D": 0.0, "F": -0.20})
    _brancher(monkeypatch, [_maille("31555", coef_dpe={"D": 0.0, "F": -0.55})])
    monkeypatch.setattr(
        orchestration, "lire_coefficient_precedent", lambda conn, code, mil: precedent
    )
    monkeypatch.setattr(
        orchestration,
        "archiver_coefficient",
        lambda conn, maille, classe_ecart=None, ecart=None, ecart_coefficient=None: (
            archivees.append((maille, classe_ecart))
        ),
    )

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert [classe for _, classe in archivees] == [ClasseEcart.BLOCAGE]


def test_une_maille_sans_precedent_est_archivee_sans_classe(
    connexion: Any, monkeypatch: pytest.MonkeyPatch
) -> None:
    archivees: list[tuple[MailleCoefficient, ClasseEcart | None]] = []
    _brancher(monkeypatch, [_maille("31555")])
    monkeypatch.setattr(orchestration, "lire_coefficient_precedent", lambda conn, code, mil: None)
    monkeypatch.setattr(
        orchestration,
        "archiver_coefficient",
        lambda conn, maille, classe_ecart=None, ecart=None, ecart_coefficient=None: (
            archivees.append((maille, classe_ecart))
        ),
    )

    orchestration.compute_departement(_FauxFetcher(), connexion, "31", 2024)

    assert [classe for _, classe in archivees] == [None]


def _brancher(monkeypatch: pytest.MonkeyPatch, mailles: list[MailleCoefficient]) -> None:
    # #255 : le pool doit porter au moins une vente native (dept 31), sinon
    # `compute_departement` court-circuite (aucune vente propre ⇒ aucune maille) et
    # ces tests — qui visent le plumbing d'archivage/refus — ne calculeraient rien.
    monkeypatch.setattr(orchestration, "load_pool", lambda *_, **__: [_vente_native("31555")])
    monkeypatch.setattr(orchestration, "compute_communes", lambda *a, **k: list(mailles))
    monkeypatch.setattr(orchestration, "read_adjacence", lambda conn, dep: {})

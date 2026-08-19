"""#255 : un département sans aucune vente native ne produit aucune maille.

Le pool déborde sur les départements limitrophes (#18) : les voisins **nourrissent**
le calcul sans être calculés. Mais un département hors périmètre DVF (Alsace-Moselle,
Paris sans maison) n'a **aucune vente propre** — ses communes de bord capteraient
malgré tout les ventes voisines et produiraient des mailles « locales » fantômes.

C'est nocif : au millésime N+1, si le département reçoit de vraies ventes, ses mailles
« apparaîtraient » alors qu'elles existaient déjà (fantômes) → REG-1 lirait un faux
changement. On court-circuite : **0 vente native ⇒ rapport vide, rien d'archivé,
`compute_communes` pas appelé**. Un département avec ≥ 1 vente native est inchangé.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any

import pytest

from valeur_verte_data import orchestration
from valeur_verte_data.dvf import Mutation


class _FauxFetcher:
    def __init__(self, code_departement: str, code_insee: str) -> None:
        self._departement = code_departement
        self._code_insee = code_insee

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return [
            {
                "code": self._code_insee,
                "nom": "Commune",
                "codeDepartement": self._departement,
                "codeEpci": "200000000",
                "codesPostaux": ["00000"],
                "centre": {"coordinates": [1.0, 43.0]},
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


def _vente(code_insee: str, id_mutation: str) -> Mutation:
    """Une vente minimale, seul ``code_insee`` importe pour le rattachement natif."""
    return Mutation(
        id_mutation=id_mutation,
        date_mutation="2025-01-01",
        millesime=2025,
        code_insee=code_insee,
        code_postal="00000",
        nom_commune="Commune",
        type_local="Maison",
        nombre_pieces_principales=4,
        prix=200000.0,
        surface_bati=100.0,
        surface_terrain=500.0,
        prix_m2=2000.0,
        adresse_numero="1",
        adresse_suffixe=None,
        adresse_nom_voie="RUE",
        longitude=1.0,
        latitude=43.0,
        etiquette_dpe="D",
    )


def test_departement_sans_vente_native_ne_produit_aucune_maille(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """57 (0 vente propre) : le pool n'est nourri que par le limitrophe 54."""
    appels_compute: list[int] = []
    archivees: list[int] = []
    monkeypatch.setattr(
        orchestration,
        "load_pool",
        lambda *_, **__: [_vente("54395", "m1"), _vente("54396", "m2")],
    )
    monkeypatch.setattr(
        orchestration, "compute_communes", lambda *a, **k: appels_compute.append(1) or []
    )
    monkeypatch.setattr(orchestration, "read_adjacence", lambda conn, dep: {})
    monkeypatch.setattr(orchestration, "archiver_coefficient", lambda *a, **k: archivees.append(1))

    rapport = orchestration.compute_departement(
        _FauxFetcher("57", "57463"), _FausseConnexion(), "57", 2025
    )

    assert appels_compute == [], "compute_communes ne doit pas tourner sans vente native"
    assert archivees == [], "aucune maille ne doit être archivée pour un dept sans vente native"
    assert rapport.communes_calculees == 0


def test_departement_avec_une_vente_native_calcule_normalement(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Non-régression : une seule vente native suffit à lancer le calcul."""
    appels_compute: list[int] = []
    monkeypatch.setattr(
        orchestration,
        "load_pool",
        lambda *_, **__: [_vente("57463", "m1"), _vente("54395", "m2")],
    )
    monkeypatch.setattr(
        orchestration, "compute_communes", lambda *a, **k: appels_compute.append(1) or []
    )
    monkeypatch.setattr(orchestration, "read_adjacence", lambda conn, dep: {})

    orchestration.compute_departement(_FauxFetcher("57", "57463"), _FausseConnexion(), "57", 2025)

    assert appels_compute == [1], "avec une vente native, le calcul doit tourner"

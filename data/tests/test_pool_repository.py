"""Chargement du pool de ventes d'un département, en mémoire (#91, §10.6).

Le moteur (`batch.compute_communes`) travaille sur une liste de `Mutation`
chargée **une seule fois** pour tout le département : recharger par commune
multiplierait les requêtes par le nombre de communes, alors que le rayon
adaptatif fait de toute façon déborder chaque sélection sur ses voisines.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any

from valeur_verte_data.db.pool_repository import load_pool


class _FakeCursor:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self._rows = rows

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows


class _FakeConnection:
    def __init__(self, rows: list[tuple[Any, ...]]) -> None:
        self.rows = rows
        self.calls: list[tuple[str, Sequence[Any] | None]] = []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor(self.rows)


def _ligne(code_insee: str = "31555", etiquette: str = "F") -> tuple[Any, ...]:
    # Ordre des colonnes attendu par le repository.
    return (
        "m1",
        "2024-01-01",
        2024,
        code_insee,
        250000.0,
        80.0,
        200.0,
        etiquette,
        "1948-1974",
        1.44,
        43.6,
    )


def test_le_pool_est_charge_pour_un_departement_et_un_millesime() -> None:
    """Mélanger des millésimes fausserait la régression, calibrée par année."""
    conn = _FakeConnection([_ligne()])

    load_pool(conn, "31", 2024)

    assert len(conn.calls) == 1
    requete, params = conn.calls[0]
    assert "data.vente" in requete
    assert params is not None
    assert list(params) == [["31%"], 2024, "Maison"]


def test_les_lignes_deviennent_des_mutations_exploitables_par_le_moteur() -> None:
    """Sans étiquette ni coordonnées, la vente est écartée par la régression."""
    conn = _FakeConnection([_ligne()])

    pool = load_pool(conn, "31", 2024)

    assert len(pool) == 1
    mutation = pool[0]
    assert mutation.code_insee == "31555"
    assert mutation.prix == 250000.0
    assert mutation.surface_bati == 80.0
    assert mutation.etiquette_dpe == "F"
    assert mutation.periode_construction == "1948-1974"
    assert mutation.latitude == 43.6
    assert mutation.longitude == 1.44


def test_un_departement_vide_rend_un_pool_vide() -> None:
    """Cas normal d'un département non encore ingéré : pas d'exception."""
    assert load_pool(_FakeConnection([]), "09", 2024) == []


def test_seules_les_maisons_alimentent_le_moteur() -> None:
    """Décision MVP (#115) : le signal DPE n'est exploitable que sur les maisons.

    Sur les appartements, l'emplacement (étage, immeuble, quartier) écrase
    l'étiquette : mesuré sur le 31, l'écart entre D et F y est de +47 €/m²,
    contre −676 pour les maisons. Calculer sur un pool mixte reviendrait à
    publier un chiffre au signe faux en zone urbaine dense.
    """
    conn = _FakeConnection([_ligne()])

    load_pool(conn, "31", 2024)

    requete, params = conn.calls[0]
    assert "type_local" in requete, "le pool doit filtrer sur le type de bien"
    assert params is not None
    assert "Maison" in list(params)


def test_le_pool_peut_deborder_sur_les_departements_limitrophes() -> None:
    """Sans eux, une commune du bord calcule sur un demi-disque (#18).

    Le rayon adaptatif ne connaît pas les frontières administratives : il prend
    les n ventes les plus proches. Si la base s'arrête au département, celles
    d'en face n'existent pas et l'échantillon est amputé sans que n, rayon ou
    IC ne le laissent voir.
    """
    conn = _FakeConnection([_ligne()])

    load_pool(conn, "31", 2024, limitrophes=("09", "81"))

    _, params = conn.calls[0]
    assert params is not None
    assert list(params)[0] == ["31%", "09%", "81%"]


def test_sans_limitrophes_le_pool_reste_celui_du_departement() -> None:
    """Le débordement est une décision de l'appelant, jamais un effet de bord."""
    conn = _FakeConnection([_ligne()])

    load_pool(conn, "31", 2024)

    _, params = conn.calls[0]
    assert params is not None
    assert list(params)[0] == ["31%"]


def test_le_segment_reste_explicite_et_modifiable() -> None:
    """Le jour où les appartements reviennent, le point de bascule doit être visible."""
    conn = _FakeConnection([_ligne()])

    load_pool(conn, "31", 2024, type_local="Appartement")

    assert "Appartement" in list(conn.calls[0][1] or [])

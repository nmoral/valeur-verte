"""REG-2 (#130) : projection d'un millésime archivé vers la table vive.

Publier ne recalcule rien : c'est une **projection** de ``coefficient_maille_historique``
vers ``coefficient_maille``. La logique vit côté ``data`` — l'app, en lecture seule
sur ce schéma (décision D6), ne fait qu'invoquer la fonction SQL exposée.
"""

from __future__ import annotations

from collections.abc import Sequence
from datetime import datetime
from typing import Any

from valeur_verte_data.db.publication_repository import (
    abandonner_millesime,
    lire_mailles_bloquees,
    lire_millesimes,
    publier_maille,
    publier_millesime,
)


class _FakeCursor:
    def __init__(self, rows: list[tuple[Any, ...]] | None = None) -> None:
        self._rows = rows or []

    def fetchall(self) -> list[tuple[Any, ...]]:
        return self._rows

    def fetchone(self) -> tuple[Any, ...] | None:
        return self._rows[0] if self._rows else None


class _FakeConnection:
    def __init__(self, rows: list[tuple[Any, ...]] | None = None) -> None:
        self.calls: list[tuple[str, Sequence[Any] | None]] = []
        self._rows = rows or []

    def execute(self, query: str, params: Sequence[Any] | None = None) -> _FakeCursor:
        self.calls.append((query, params))
        return _FakeCursor(self._rows)


def test_publier_invoque_la_fonction_sql_avec_le_millesime_et_l_auteur() -> None:
    conn = _FakeConnection([(1240,)])

    publier_millesime(conn, 2024, "admin@vv.test")

    requete, params = conn.calls[0]
    assert "data.publier_millesime" in requete
    assert params == [2024, "admin@vv.test"]


def test_publier_rend_le_nombre_de_mailles_projetees() -> None:
    conn = _FakeConnection([(1240,)])

    assert publier_millesime(conn, 2024, "admin@vv.test") == 1240


def test_une_fonction_qui_ne_rend_rien_compte_zero_maille() -> None:
    """Ne jamais deviner : sans ligne rendue, aucune maille n'a ete projetee."""
    conn = _FakeConnection([])

    assert publier_millesime(conn, 2024, "admin@vv.test") == 0


def test_une_ligne_vide_compte_zero_maille() -> None:
    conn = _FakeConnection([()])

    assert publier_millesime(conn, 2024, "admin@vv.test") == 0


def test_abandonner_invoque_la_fonction_sql_dediee() -> None:
    """Abandonner est une décision tracée, pas une absence de décision.

    Sans elle, un millésime écarté resterait indéfiniment proposé à la publication.
    """
    conn = _FakeConnection([(0,)])

    abandonner_millesime(conn, 2024, "admin@vv.test")

    requete, params = conn.calls[0]
    assert "data.abandonner_millesime" in requete
    assert params == [2024, "admin@vv.test"]


def test_lire_millesimes_rend_l_etat_et_les_effectifs_par_millesime() -> None:
    decide_le = datetime(2026, 8, 15, 10, 30)
    conn = _FakeConnection(
        [
            (2024, None, None, None, 1240, 18, 7),
            (2023, "publie", "admin@vv.test", decide_le, 1198, 0, 3),
        ]
    )

    millesimes = lire_millesimes(conn)

    assert [m.millesime for m in millesimes] == [2024, 2023]
    assert millesimes[0].etat is None, "Un millésime calculé mais non décidé n'a pas d'état."
    assert millesimes[0].mailles_calculees == 1240
    assert millesimes[0].mailles_bloquees == 18
    assert millesimes[0].mailles_en_alerte == 7
    assert millesimes[1].etat == "publie"
    assert millesimes[1].decide_par == "admin@vv.test"
    # Quand la decision a ete prise : c'est ce que l'ecran affiche a cote de l'etat.
    assert millesimes[1].decide_le == decide_le
    assert millesimes[0].decide_le is None


def test_publier_un_millesime_ecarte_les_mailles_bloquees_par_reg1() -> None:
    """Une maille en écart de blocage garde sa valeur du millésime précédent.

    La décision de la publier quand même est prise séparément, maille par maille,
    après examen — c'est le sens de ``publier_maille``.
    """
    conn = _FakeConnection([(1222,)])

    publier_millesime(conn, 2024, "admin@vv.test")

    requete, _ = conn.calls[0]
    assert "data.publier_millesime" in requete


def test_publier_maille_debloque_une_seule_commune() -> None:
    conn = _FakeConnection([(1,)])

    publier_maille(conn, "31555", 2024, "admin@vv.test")

    requete, params = conn.calls[0]
    assert "data.publier_maille" in requete
    assert params == ["31555", 2024, "admin@vv.test"]


def test_lire_mailles_bloquees_rend_l_ecart_de_chaque_maille_du_millesime() -> None:
    """Alimente l'écran de déblocage : sans le motif chiffré, l'opérateur
    arbitrerait à l'aveugle."""
    conn = _FakeConnection([("31555", 1.80, "élasticité bâti"), ("31149", 0.42, "coef_dpe C")])

    bloquees = lire_mailles_bloquees(conn, 2024)

    requete, _ = conn.calls[0]
    assert "coefficient_maille_historique" in requete
    assert "classe_ecart = 'blocage'" in requete
    assert [m.code_insee for m in bloquees] == ["31555", "31149"]
    assert bloquees[0].ecart == 1.80
    assert bloquees[0].coefficient == "élasticité bâti"
    _, params = conn.calls[0]
    assert params == [2024]


def test_lire_millesimes_ordonne_du_plus_recent_au_plus_ancien() -> None:
    conn = _FakeConnection([])

    lire_millesimes(conn)

    requete, _ = conn.calls[0]
    assert "ORDER BY" in requete and "DESC" in requete

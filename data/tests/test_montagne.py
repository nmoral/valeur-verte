"""Référentiel montagne (#341, Lot 1) : communes classées en zone de montagne.

Marqueur **contextuel communal** montagne=oui (l'absence vaut non) — présence dans
la liste officielle (règlement CE n°1257/1999, Ministère de l'Agriculture).
Alimente le bloc « Pourquoi ici ? » : en montagne, le chauffage pèse lourd, une
maison bien isolée s'y valorise probablement davantage (hypothèse). Par commune,
pour varier d'une commune à l'autre (anti-duplicate #341).
"""

from __future__ import annotations

from valeur_verte_data.marqueurs import TYPE_CONTEXTUEL
from valeur_verte_data.montagne import marqueurs_montagne_departement


def test_les_communes_montagne_d_un_departement_alpin() -> None:
    marqueurs = marqueurs_montagne_departement("74")  # Haute-Savoie

    codes = {m.code_insee for m in marqueurs}
    assert "74001" in codes
    for m in marqueurs:
        assert m.code_departement is None
        assert m.nom == "montagne"
        assert m.valeur == "oui"
        assert m.type_marqueur == TYPE_CONTEXTUEL
        assert "montagne" in m.source.lower()


def test_un_departement_de_plaine_n_a_aucune_commune_montagne() -> None:
    assert marqueurs_montagne_departement("29") == []  # Finistère


def test_ne_retient_que_les_communes_du_departement_demande() -> None:
    marqueurs = marqueurs_montagne_departement("05")  # Hautes-Alpes

    assert marqueurs != []
    assert all((m.code_insee or "").startswith("05") for m in marqueurs)

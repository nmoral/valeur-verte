"""Référentiel tourisme (#341, Lot 1) : forte présence touristique.

Marqueur **contextuel communal** ``tourisme=fort`` (l'absence vaut faible) — la
commune a une part élevée de résidences secondaires (seuil INSEE, cf. ressource).
Alimente le bloc « Pourquoi ici ? » : littoral/montagne prisé → la demande soutient
les prix, l'impact du DPE y est généralement plus faible (hypothèse). Par commune,
pour varier d'une commune à l'autre (anti-duplicate #341).
"""

from __future__ import annotations

from valeur_verte_data.marqueurs import TYPE_CONTEXTUEL
from valeur_verte_data.tourisme import marqueurs_tourisme_departement


def test_les_communes_touristiques_d_un_departement() -> None:
    marqueurs = marqueurs_tourisme_departement("73")  # Savoie

    codes = {m.code_insee for m in marqueurs}
    assert "73206" in codes  # Tignes, station de ski
    for m in marqueurs:
        assert m.code_departement is None
        assert m.nom == "tourisme"
        assert m.valeur == "fort"
        assert m.type_marqueur == TYPE_CONTEXTUEL
        assert "INSEE" in m.source


def test_une_commune_peu_touristique_n_a_pas_de_marqueur() -> None:
    # Toulouse (31555) : peu de résidences secondaires → faible → aucun marqueur.
    codes = {m.code_insee for m in marqueurs_tourisme_departement("31")}
    assert "31555" not in codes


def test_ne_retient_que_les_communes_du_departement_demande() -> None:
    marqueurs = marqueurs_tourisme_departement("73")

    assert marqueurs != []
    assert all((m.code_insee or "").startswith("73") for m in marqueurs)

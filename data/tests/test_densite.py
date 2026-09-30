"""Référentiel densité (#341, Lot 1) : grille communale de densité INSEE.

Marqueur **contextuel communal** ``densite`` ∈ {urbain, periurbain, rural}, dérivé
du degré de densité INSEE à 3 postes (1 → urbain, 2 → périurbain, 3 → rural).
Couverture nationale. Alimente le bloc « Pourquoi ici ? » (territoire rural, parc
souvent ancien → une rénovation s'y remarque davantage).
"""

from __future__ import annotations

from valeur_verte_data.densite import densite_de_niveau, marqueurs_densite_departement
from valeur_verte_data.marqueurs import TYPE_CONTEXTUEL


def test_niveau_insee_mappe_sur_la_densite() -> None:
    assert densite_de_niveau("1") == "urbain"
    assert densite_de_niveau("2") == "periurbain"
    assert densite_de_niveau("3") == "rural"


def test_les_marqueurs_densite_d_un_departement() -> None:
    marqueurs = marqueurs_densite_departement("31")  # Haute-Garonne

    par_code = {m.code_insee: m for m in marqueurs}
    assert par_code["31555"].valeur == "urbain"  # Toulouse (niveau 1)
    for m in marqueurs:
        assert m.code_departement is None
        assert m.nom == "densite"
        assert m.type_marqueur == TYPE_CONTEXTUEL
        assert "INSEE" in m.source
        assert m.valeur in {"urbain", "periurbain", "rural"}


def test_ne_retient_que_le_departement_demande_et_couvre_le_rural() -> None:
    marqueurs = marqueurs_densite_departement("05")  # Hautes-Alpes

    assert marqueurs != []
    assert all((m.code_insee or "").startswith("05") for m in marqueurs)
    assert any(m.valeur == "rural" for m in marqueurs)

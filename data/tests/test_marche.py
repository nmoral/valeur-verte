"""Référentiel marché (#341, Lot 1) : tension du marché du logement (zonage ABC).

Marqueur **contextuel communal** ``marche`` ∈ {tendu, intermediaire, detendu},
dérivé de la zone ABC officielle (Abis/A/B1 → tendu, B2 → intermédiaire,
C → détendu). Couverture nationale : chaque commune porte une valeur explicite.
Alimente le bloc « Pourquoi ici ? » (marché détendu → le DPE devient un levier de
négociation ; marché tendu → il pèse moins).
"""

from __future__ import annotations

from valeur_verte_data.marche import marche_de_zone, marqueurs_marche_departement
from valeur_verte_data.marqueurs import TYPE_CONTEXTUEL


def test_zone_abc_mappee_sur_la_tension() -> None:
    assert marche_de_zone("Abis") == "tendu"
    assert marche_de_zone("A") == "tendu"
    assert marche_de_zone("B1") == "tendu"
    assert marche_de_zone("B2") == "intermediaire"
    assert marche_de_zone("C") == "detendu"


def test_les_marqueurs_marche_d_un_departement() -> None:
    marqueurs = marqueurs_marche_departement("31")  # Haute-Garonne

    par_code = {m.code_insee: m for m in marqueurs}
    assert par_code["31555"].valeur == "tendu"  # Toulouse (B1)
    for m in marqueurs:
        assert m.code_departement is None
        assert m.nom == "marche"
        assert m.type_marqueur == TYPE_CONTEXTUEL
        assert "ABC" in m.source
        assert m.valeur in {"tendu", "intermediaire", "detendu"}


def test_un_departement_rural_est_majoritairement_detendu() -> None:
    marqueurs = marqueurs_marche_departement("05")  # Hautes-Alpes

    assert marqueurs != []
    assert all((m.code_insee or "").startswith("05") for m in marqueurs)
    assert any(m.valeur == "detendu" for m in marqueurs)

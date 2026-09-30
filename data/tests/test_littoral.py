"""Référentiel littoral (#341, Lot 1) : communes de la loi Littoral.

Marqueur **contextuel communal** littoral=oui (l'absence vaut non) — présence dans
la liste officielle COG 2022 (DGALN/SIDAUH). Alimente le bloc « Pourquoi ici ? » :
sur le littoral, la demande soutient les prix, l'impact du DPE y est souvent plus
faible (hypothèse). Renseigné par commune pour varier d'une commune à l'autre
(anti-duplicate #341).
"""

from __future__ import annotations

from valeur_verte_data.littoral import marqueurs_littoral_departement
from valeur_verte_data.marqueurs import TYPE_CONTEXTUEL


def test_les_communes_littoral_d_un_departement_cotier() -> None:
    marqueurs = marqueurs_littoral_departement("29")  # Finistère

    codes = {m.code_insee for m in marqueurs}
    assert "29019" in codes  # Brest, commune littoral
    # Toutes portées commune, marqueur littoral=oui contextuel, sourcé.
    for m in marqueurs:
        assert m.code_departement is None
        assert m.nom == "littoral"
        assert m.valeur == "oui"
        assert m.type_marqueur == TYPE_CONTEXTUEL
        assert "Littoral" in m.source


def test_un_departement_interieur_n_a_aucune_commune_littoral() -> None:
    assert marqueurs_littoral_departement("31") == []  # Haute-Garonne, sans littoral


def test_ne_retient_que_les_communes_du_departement_demande() -> None:
    marqueurs = marqueurs_littoral_departement("06")  # Alpes-Maritimes

    assert marqueurs != []
    assert all((m.code_insee or "").startswith("06") for m in marqueurs)

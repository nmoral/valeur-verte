"""Référentiel climat (#341, Lot 1) : zone climatique RT2012 par département.

Marqueur contextuel départemental (froid/tempéré/doux ← H1/H2/H3), issu de la
répartition officielle par département (ministère de la Transition écologique).
Embarqué et versionné : donnée de référence figée, pas de dépendance réseau.
"""

from __future__ import annotations

from valeur_verte_data.climat import (
    departements_couverts,
    marqueur_climat,
)
from valeur_verte_data.marqueurs import TYPE_CONTEXTUEL


def test_un_departement_h1_est_froid() -> None:
    marqueur = marqueur_climat("55")

    assert marqueur is not None
    assert marqueur.code_departement == "55"
    assert marqueur.code_insee is None
    assert marqueur.nom == "climat"
    assert marqueur.valeur == "froid"
    assert marqueur.type_marqueur == TYPE_CONTEXTUEL
    assert "RT2012" in marqueur.source


def test_un_departement_h2_est_tempere() -> None:
    marqueur = marqueur_climat("33")

    assert marqueur is not None
    assert marqueur.valeur == "tempere"


def test_un_departement_h3_est_doux() -> None:
    marqueur = marqueur_climat("06")

    assert marqueur is not None
    assert marqueur.valeur == "doux"


def test_la_corse_est_douce_sur_ses_deux_departements() -> None:
    """La répartition officielle liste « 20 Corse » en H3 ; les codes réels
    2A/2B doivent tous deux porter le climat doux."""
    for code in ("2A", "2B"):
        marqueur = marqueur_climat(code)
        assert marqueur is not None
        assert marqueur.valeur == "doux"


def test_un_departement_hors_zonage_metropolitain_n_a_pas_de_climat() -> None:
    """Les DOM ne relèvent pas du zonage RT2012 métropolitain : pas de marqueur
    (le bloc « Pourquoi ici ? » n'émettra simplement pas d'hypothèse climat)."""
    assert marqueur_climat("971") is None
    assert marqueur_climat("00") is None


def test_le_referentiel_couvre_les_96_departements_metropolitains() -> None:
    """Garde d'exhaustivité : métropole = 96 départements (dont Corse 2A/2B),
    chacun classé dans exactement une zone."""
    couverts = departements_couverts()

    assert len(couverts) == 96
    assert "20" not in couverts, "La Corse est portée par 2A/2B, pas par le code 20."
    assert {"2A", "2B"} <= couverts

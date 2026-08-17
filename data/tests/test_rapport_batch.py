"""Rapport de batch archivé (#131) : métriques exposées par les rapports d'exécution.

Le rapport archivé doit porter le **taux d'appariement DVF↔DPE** (DAT-3) : une base
chargée sans étiquette DPE est stérile (le moteur écarte ces ventes), et une chute de
l'appariement d'un millésime à l'autre est le premier symptôme à repérer six mois plus
tard. On le dérive des ventes réellement insérées, pas d'un compteur parallèle.
"""

from __future__ import annotations

from valeur_verte_data.orchestration import RapportIngestion


def test_taux_appariement_est_la_part_de_ventes_avec_dpe() -> None:
    rapport = RapportIngestion(departement="31", millesime=2024)
    rapport.mutations_inserees = 60
    rapport.ventes_appariees = 45

    assert rapport.taux_appariement == 0.75


def test_taux_appariement_vaut_zero_sans_aucune_vente() -> None:
    # Un département sans ingestion ne doit pas faire diviser par zéro : l'absence
    # d'appariement est un taux nul, pas une erreur.
    rapport = RapportIngestion(departement="31", millesime=2024)

    assert rapport.taux_appariement == 0.0

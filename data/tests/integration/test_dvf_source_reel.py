"""Contrat avec le CSV DVF réel d'Etalab.

Les tests hors-ligne servent des lignes que *nous* écrivons : ils ne peuvent pas
voir qu'une colonne a changé de nom ou qu'un filtre ne matche jamais. C'est ce
qui s'est produit (#136) — `nature_culture` était lu en attendant le code `S`
alors qu'il contient le libellé `sols`, et **toutes** les surfaces de terrain
étaient silencieusement perdues.

Ce test dépend d'un service tiers : son échec peut venir d'une indisponibilité
d'Etalab comme d'une vraie rupture de contrat. Le distinguer se fait en ouvrant
l'URL affichée.
"""

from __future__ import annotations

import pytest

from valeur_verte_data.dvf_source import dvf_url, fetch_mutations
from valeur_verte_data.http import HttpFetcher

pytestmark = pytest.mark.integration

# Commune au parc important : garantit un volume de maisons non trivial.
TOULOUSE = "31555"
MILLESIME = 2024

# Part minimale de maisons devant porter une surface de terrain. Observé à 90 %
# sur Toulouse ; le seuil laisse de la marge pour les variations de millésime,
# tout en restant très au-dessus de zéro — la valeur qu'avait le défaut.
PART_MINIMALE_AVEC_TERRAIN = 0.5


def test_les_maisons_portent_une_surface_de_terrain() -> None:
    """Une maison sans terrain est l'exception ; toutes sans terrain est un défaut."""
    mutations = fetch_mutations(HttpFetcher(), TOULOUSE, MILLESIME)
    maisons = [m for m in mutations if m.type_local == "Maison"]

    assert maisons, f"aucune maison dans {dvf_url(MILLESIME, TOULOUSE)}"

    avec_terrain = [m for m in maisons if m.surface_terrain]
    part = len(avec_terrain) / len(maisons)
    assert part >= PART_MINIMALE_AVEC_TERRAIN, (
        f"{part:.0%} des maisons portent une surface de terrain "
        f"({len(avec_terrain)}/{len(maisons)}) : filtre de nature de culture "
        "probablement désaligné avec le format de la source."
    )


def test_les_surfaces_de_terrain_sont_plausibles() -> None:
    """Des terres agricoles comptées comme terrain gonfleraient la médiane."""
    mutations = fetch_mutations(HttpFetcher(), TOULOUSE, MILLESIME)
    surfaces = sorted(m.surface_terrain for m in mutations if m.surface_terrain)

    mediane = surfaces[len(surfaces) // 2]
    assert 50 <= mediane <= 3000, (
        f"médiane de {mediane:.0f} m² en zone urbaine : le filtre laisse "
        "probablement passer des parcelles agricoles."
    )

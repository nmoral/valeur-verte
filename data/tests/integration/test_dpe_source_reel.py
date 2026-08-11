"""Contrat avec l'API DPE de l'ADEME, vérifié contre le service réel.

Les tests hors-ligne servent des réponses que *nous* avons écrites : ils ne
peuvent pas voir qu'un identifiant de jeu de données ou un nom de champ ne
correspond plus à l'API. C'est exactement ce qui s'est produit — le client
visait ``dpe-v2-logements-existants`` avec des champs ``Etiquette_DPE``,
``Adresse_(BAN)``… tous disparus, et l'appariement rendait zéro DPE sur
12 438 ventes sans que rien ne le signale.

Ce test dépend d'un service tiers : son échec peut venir d'une indisponibilité
de l'ADEME comme d'une vraie rupture de contrat. Le distinguer se fait en
ouvrant l'URL affichée.
"""

from __future__ import annotations

import pytest

from valeur_verte_data.dpe_source import ADEME_LINES_URL, dpe_lookup
from valeur_verte_data.http import HttpFetcher

pytestmark = pytest.mark.integration

# Commune au parc important : garantit un volume de DPE non trivial.
TOULOUSE = "31555"


def test_le_jeu_de_donnees_de_l_ademe_repond() -> None:
    """Un identifiant de jeu de données périmé rend 404 et stérilise l'appariement."""
    reponse = HttpFetcher().get_json(ADEME_LINES_URL, {"size": "1"})

    assert "results" in reponse, f"Réponse inattendue de {ADEME_LINES_URL}"


def test_les_dpe_d_une_commune_sont_indexables() -> None:
    """Les noms de champs doivent permettre de construire la table d'appariement."""
    lookup = dpe_lookup(HttpFetcher(), TOULOUSE)

    assert lookup, "aucun DPE indexé : identifiant de jeu de données ou champs périmés"

    (code_postal, adresse), info = next(iter(lookup.items()))
    assert code_postal.startswith("31"), f"code postal inattendu : {code_postal}"
    assert adresse, "clé d'adresse vide : le champ d'adresse a changé de nom"
    assert info.etiquette_dpe in set("ABCDEFG")

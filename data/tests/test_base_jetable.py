"""Garde-fou empêchant les tests de vider une base qui n'est pas jetable (#112).

Trois tests d'intégration font ``TRUNCATE data.vente`` pour s'isoler. Ils
s'exécutent contre la base désignée par ``DATABASE_URL``, sans rien savoir de ce
qu'elle contient : un tunnel SSH est ouvert vers la production pour l'accès
client (#78), et une variable d'environnement restée en place suffirait à vider
la base réelle.

Le défaut est **sûr** : en l'absence de marqueur explicite, on refuse. Et on
refuse par une erreur, jamais par un `skip` — un skip ferait disparaître la
couverture en silence le jour où le marqueur manquerait dans la CI.
"""

from __future__ import annotations

import pytest

from valeur_verte_data.db.jetable import MARQUEUR_BASE_JETABLE, exiger_base_jetable


def test_sans_marqueur_le_vidage_est_refuse() -> None:
    """C'est le cas d'une base de production : rien ne doit être effacé."""
    with pytest.raises(RuntimeError) as erreur:
        exiger_base_jetable({})

    assert MARQUEUR_BASE_JETABLE in str(erreur.value), (
        "le message doit nommer la variable à définir, sinon il est inexploitable"
    )


@pytest.mark.parametrize("valeur", ["", "0", "false", "non"])
def test_une_valeur_non_affirmative_est_refusee(valeur: str) -> None:
    """Seule une autorisation explicite compte ; une variable vide ne vaut pas accord."""
    with pytest.raises(RuntimeError):
        exiger_base_jetable({MARQUEUR_BASE_JETABLE: valeur})


@pytest.mark.parametrize("valeur", ["1", "true", "oui"])
def test_une_autorisation_explicite_laisse_passer(valeur: str) -> None:
    """La CI et les bases de développement portent ce marqueur."""
    exiger_base_jetable({MARQUEUR_BASE_JETABLE: valeur})

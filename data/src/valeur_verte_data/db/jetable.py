"""Autorisation explicite avant de vider une base (#112).

Les tests d'intégration s'isolent en tronquant des tables. Ils visent la base
désignée par ``DATABASE_URL`` sans rien savoir de ce qu'elle contient : rien ne
distingue, dans une chaîne de connexion, une base jetable d'une production.

D'où un marqueur que seule une base jetable porte. En son absence on lève, car
« une perte de base = la fin du produit » (épic #8) : le comportement par défaut
doit protéger, pas effacer.
"""

from __future__ import annotations

from collections.abc import Mapping

MARQUEUR_BASE_JETABLE = "VALEUR_VERTE_BASE_JETABLE"

_AUTORISATIONS = frozenset({"1", "true", "oui", "yes"})


def exiger_base_jetable(env: Mapping[str, str]) -> None:
    """Lève si l'environnement n'autorise pas explicitement à vider la base."""
    if env.get(MARQUEUR_BASE_JETABLE, "").strip().lower() in _AUTORISATIONS:
        return

    raise RuntimeError(
        f"Vidage refusé : {MARQUEUR_BASE_JETABLE} n'autorise pas cette base. "
        f"Définir {MARQUEUR_BASE_JETABLE}=1 uniquement sur une base jetable "
        "(service de la CI, base de développement) — jamais sur la production."
    )

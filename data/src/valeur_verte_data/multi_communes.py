"""Exclusion des mutations multi-communes du pool moteur.

L'ingestion est **par commune** (``ingest_commune`` récupère le DVF d'une seule
commune) : une vente qui chevauche plusieurs communes produit donc **une ligne
par commune**, chacune portant le **prix total** de la vente (``valeur_fonciere``
répétée) mais la **surface de sa seule commune**. Le rapport prix/surface est
alors faux (€/m² surestimé), ce qui biaiserait la régression log-prix.

On écarte toutes les copies d'une même ``(id_mutation, millesime)`` présente sous
plus d'un ``code_insee``. Le filtre s'applique **au niveau du pool** (dept +
limitrophes) : les communes d'une même vente étant contiguës, toutes ses copies
sont présentes dans le pool, donc le groupe est complet et détectable. L'ordre du
pool est préservé (INV-5).
"""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable

from .dvf import Mutation


def filtrer_multi_communes(pool: Iterable[Mutation]) -> list[Mutation]:
    """Écarte du pool les mutations réparties sur plusieurs communes (prix non attribuable)."""
    mutations = list(pool)
    communes_par_mutation: dict[tuple[str, int], set[str]] = defaultdict(set)
    for mutation in mutations:
        communes_par_mutation[(mutation.id_mutation, mutation.millesime)].add(mutation.code_insee)
    return [
        mutation
        for mutation in mutations
        if len(communes_par_mutation[(mutation.id_mutation, mutation.millesime)]) == 1
    ]

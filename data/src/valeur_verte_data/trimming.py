"""Écrêtage de l'échantillon au calcul (S32) — troncature robuste.

Retire les comparables au **prix au m²** le plus atypique de l'échantillon
*réellement utilisé* pour une maille, mais seulement si l'échantillon est assez
grand (``seuil_n``). Impact faible sur la taille, potentiellement décisif sur les
coefficients : ce sont les points à fort levier qui déstabilisent la régression.

On écrête sur ``prix / surface_bati`` et **jamais** sur le prix brut, qui
recouperait les grandes maisons chères (le biais que S32 cherche à éviter). La
variante V2 (résidu pondéré) viendra ensuite.
"""

from __future__ import annotations

from collections.abc import Sequence

from .dvf import Mutation


def ecreter_prix_m2(
    sample: Sequence[Mutation], *, fraction: float = 0.01, seuil_n: int = 100
) -> list[Mutation]:
    """Retire les ``fraction`` extrêmes (haut et bas) du prix au m², si n ≥ ``seuil_n``.

    Les ventes sans prix ou sans surface bâtie exploitable ne comptent ni dans le
    seuil ni dans le tri ; elles sont conservées telles quelles (le moteur les
    écartera à l'éligibilité).
    """
    valides = [
        mutation
        for mutation in sample
        if mutation.prix is not None
        and mutation.prix > 0
        and mutation.surface_bati is not None
        and mutation.surface_bati > 0
    ]
    if len(valides) < seuil_n:
        return list(sample)

    k = int(len(valides) * fraction)
    if k == 0:
        return list(sample)

    ordonnees = sorted(valides, key=lambda mutation: mutation.prix / mutation.surface_bati)  # type: ignore[operator]
    exclus = {id(mutation) for mutation in ordonnees[:k]}
    exclus |= {id(mutation) for mutation in ordonnees[-k:]}
    return [mutation for mutation in sample if id(mutation) not in exclus]

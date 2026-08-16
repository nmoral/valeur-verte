"""Ruptures de réforme DPE (#215) — ne pas pooler à travers une réforme.

Une réforme du DPE (méthode 3CL au 2021-07-01, recalcul des petites surfaces au
2024-07-01, réforme ~2026) casse la comparabilité des étiquettes : un « D » d'avant
la réforme ne mesure pas la même performance qu'un « D » d'après. Pooler plusieurs
millésimes (fenêtre glissante #177) à travers une réforme mélange deux référentiels
et biaise le premium DPE.

Politique retenue : **exclusion**. Pour une maille publiée sous le millésime N, on
écarte du calcul les ventes dont le DPE a été établi **avant** la dernière réforme
survenue au plus tard fin N. La cascade de couverture (#169) absorbe la baisse
d'effectif. Le calendrier des réformes est configurable (``dpe_reform_boundaries``,
vide par défaut = aucun filtrage). Logique pure, hors-ligne.
"""

from __future__ import annotations

from collections.abc import Iterable, Sequence

from .dvf import Mutation


def derniere_reforme(millesime: int, boundaries: Sequence[str]) -> str | None:
    """Date de la dernière réforme survenue au plus tard fin du millésime N.

    Une réforme postérieure à N (date > ``{N}-12-31``) n'a pas encore d'effet sur
    les diagnostics du pool. ``None`` si aucune réforme n'est applicable.
    """
    fin_de_n = f"{millesime}-12-31"
    applicables = [borne for borne in boundaries if borne <= fin_de_n]
    return max(applicables) if applicables else None


def filtrer_regime_dpe(
    mutations: Iterable[Mutation], millesime: int, boundaries: Sequence[str]
) -> list[Mutation]:
    """Écarte les ventes dont le DPE précède la dernière réforme applicable à N.

    Une vente est conservée si son DPE date de la réforme (ou après), ou si sa date
    d'établissement est **inconnue** (on n'exclut que ce qu'on peut positivement
    dater avant la borne). Calendrier vide ⇒ aucun filtrage (non-régression exacte).
    """
    borne = derniere_reforme(millesime, boundaries)
    if borne is None:
        return list(mutations)
    return [
        mutation
        for mutation in mutations
        if mutation.date_etablissement_dpe is None or mutation.date_etablissement_dpe >= borne
    ]

"""DAT-2 (#125) — assertion de volumétrie par département (± 30 %).

Le nombre de ventes DVF insérées par département peut **légitimement** bouger d'un
millésime à l'autre ; mais un effondrement — ou une explosion — de volumétrie est le
symptôme d'une source tronquée, dédoublée ou d'un millésime mal ciblé. DAT-2
confronte, en **fin d'ingestion et par département**, la volumétrie du millésime à
celle du précédent : un écart au-delà de ± 30 % déclenche une **alerte**, jamais un
échec (contrairement à DAT-1/DAT-3) — DVF peut réellement varier. C'est la seule
assertion DAT non bloquante. Décision **pure** : deux volumétries entrent, un verdict
(alerte + motifs, prêt à journaliser) sort.
"""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class VerdictVolumetrie:
    """Verdict DAT-2 d'un département : la volumétrie, si elle alerte, et pourquoi.

    ``alerte`` (jamais ``bloque`` : DAT-2 ne barre rien) ; ``motifs`` liste les
    raisons lisibles de l'alerte (vide si le département reste dans la fourchette) —
    journalisées par département et exposées à l'admin. ``volumetrie_precedente`` est
    ``None`` au premier chargement.
    """

    departement: str
    volumetrie: int
    volumetrie_precedente: int | None
    alerte: bool
    motifs: tuple[str, ...]


def evaluer_volumetrie(
    departement: str,
    volumetrie: int,
    volumetrie_precedente: int | None,
    *,
    ecart_max: float,
) -> VerdictVolumetrie:
    """Confronte la volumétrie d'un département à celle du millésime précédent (§DAT-2).

    Alerte si l'écart relatif ``|volumetrie − précédent| / précédent`` dépasse
    ``ecart_max`` (borne stricte : ± ``ecart_max`` pile reste dans la fourchette).
    Sans base positive — premier chargement (``None``) ou précédent à zéro — l'écart
    relatif est indéfini : verdict **neutre** plutôt qu'une alerte infondée.
    """
    if not volumetrie_precedente:
        return VerdictVolumetrie(departement, volumetrie, volumetrie_precedente, False, ())
    ecart = abs(volumetrie - volumetrie_precedente) / volumetrie_precedente
    if ecart > ecart_max:
        sens = "hausse" if volumetrie > volumetrie_precedente else "baisse"
        motif = (
            f"volumétrie en {sens} de {ecart:.0%} vs précédent "
            f"({volumetrie_precedente} → {volumetrie})"
        )
        return VerdictVolumetrie(departement, volumetrie, volumetrie_precedente, True, (motif,))
    return VerdictVolumetrie(departement, volumetrie, volumetrie_precedente, False, ())

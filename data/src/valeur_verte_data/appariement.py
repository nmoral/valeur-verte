"""DAT-3 (#126) — porte sur le taux d'appariement DVF ↔ DPE.

L'appariement DVF ↔ DPE est la charnière du modèle : sans étiquette DPE rattachée
à une vente, aucun coefficient à estimer. Un appariement qui s'effondre ne lève
aucune erreur — il produit des coefficients sur un échantillon biaisé. DAT-3
oppose, en **fin d'ingestion et par département**, deux garde-fous au taux brut
(``dpe.matching_rate``) :

- un **plancher** (60 %, critère de sortie de J1) ;
- une **chute** de plus de 10 points vs le millésime précédent.

Un département qui échoue **n'est pas calculé**. Décision **pure** : les taux
entrent, un verdict (bloque + motifs, prêt à journaliser) sort.
"""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(frozen=True)
class VerdictAppariement:
    """Verdict DAT-3 d'un département : le taux, s'il bloque, et pourquoi.

    ``motifs`` liste les raisons lisibles du blocage (vide si le département passe)
    — journalisées par département et exposées à l'admin (#46).
    """

    departement: str
    taux: float
    bloque: bool
    motifs: tuple[str, ...]


def evaluer_appariement(
    departement: str,
    taux: float,
    taux_precedent: float | None,
    *,
    seuil_min: float,
    chute_max: float,
) -> VerdictAppariement:
    """Confronte le taux d'appariement d'un département à ses garde-fous (§DAT-3).

    Bloque si le taux passe **sous** ``seuil_min`` (borne incluse : à ``seuil_min``
    pile il passe), ou s'il **chute** de plus de ``chute_max`` par rapport au
    millésime précédent (borne stricte : une baisse égale à ``chute_max`` passe).
    Les deux motifs peuvent coexister. Sans précédent (premier millésime), seule la
    borne plancher s'applique.
    """
    motifs: list[str] = []
    if taux < seuil_min:
        motifs.append(f"taux {taux:.0%} sous le seuil plancher {seuil_min:.0%}")
    if taux_precedent is not None:
        # Arrondi : sinon 0,80 − 0,70 = 0,1000000000000001 > 0,10 bloquerait à tort.
        chute = round(taux_precedent - taux, 10)
        if chute > chute_max:
            motifs.append(
                f"chute de {chute * 100:.0f} points vs précédent "
                f"({taux_precedent:.0%} → {taux:.0%})"
            )
    return VerdictAppariement(departement, taux, bool(motifs), tuple(motifs))

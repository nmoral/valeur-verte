"""DAT-5 (#128) — fraîcheur des sources (mutation la plus récente < 12 mois).

DVF est publié semestriellement : une source qui **cesse d'être mise à jour** ne lève
aucune erreur — le pipeline retraite sereinement des données périmées et publie des
coefficients qui vieillissent en silence. C'est le mode de défaillance le plus discret
du lot. DAT-5 confronte, **en fin d'ingestion**, la date de mutation la plus récente du
département à la **date d'exécution** du batch : au-delà de ``max_mois`` (12 mois)
d'ancienneté, une **alerte** est journalisée — jamais un blocage (un backfill
volontaire est légitime ; la décision de recalculer reste humaine). Décision **pure** :
une date (+ la référence) entre, un verdict (périmée + motif, prêt à journaliser) sort.
"""

from __future__ import annotations

import calendar
from dataclasses import dataclass
from datetime import date


@dataclass(frozen=True)
class VerdictFraicheur:
    """Verdict DAT-5 d'un département : la date la plus récente, si elle est périmée.

    ``perimee`` alerte sans jamais bloquer ; ``motifs`` nomme l'ancienneté (vide si la
    source est fraîche ou sans date). ``date_mutation_max`` est ``None`` quand aucune
    vente datée n'a été ingérée.
    """

    departement: str
    date_mutation_max: date | None
    perimee: bool
    motifs: tuple[str, ...]


def date_limite_fraicheur(reference: date, mois: int) -> date:
    """Date ``reference`` reculée de ``mois`` mois, jour ramené au dernier du mois cible.

    Un recul de mois peut viser un jour inexistant (31 mars − 1 mois → « 31 février ») :
    on le ramène au dernier jour valide (28/29 février selon l'année bissextile).
    """
    total = reference.year * 12 + (reference.month - 1) - mois
    annee, index_mois = divmod(total, 12)
    mois_cible = index_mois + 1
    jour = min(reference.day, calendar.monthrange(annee, mois_cible)[1])
    return date(annee, mois_cible, jour)


def evaluer_fraicheur(
    departement: str,
    date_mutation_max: date | None,
    reference: date,
    *,
    max_mois: int,
) -> VerdictFraicheur:
    """Confronte la fraîcheur d'un département à la date d'exécution (§DAT-5).

    Alerte si la mutation la plus récente précède ``reference`` de plus de ``max_mois``
    mois (borne stricte : à la limite exacte la source est encore fraîche). Sans date
    (aucune vente datée), il n'y a rien à juger : verdict **neutre**.
    """
    if date_mutation_max is None:
        return VerdictFraicheur(departement, None, False, ())
    limite = date_limite_fraicheur(reference, max_mois)
    if date_mutation_max < limite:
        motif = (
            f"source périmée : mutation la plus récente {date_mutation_max:%Y-%m-%d}, "
            f"au-delà de {max_mois} mois avant l'exécution (limite {limite:%Y-%m-%d})"
        )
        return VerdictFraicheur(departement, date_mutation_max, True, (motif,))
    return VerdictFraicheur(departement, date_mutation_max, False, ())

"""Mode dégradé et seuil plancher pour les petits échantillons (§5.3).

Plutôt qu'un refus sec, on bascule sous un seuil sur le **coefficient
départemental** avec un **bandeau d'alerte non masquable** (repris tel quel dans
le PDF). Si même le département est trop peu peuplé, on refuse (ne devrait pas
arriver en Occitanie). Décision pure, consommée par le batch de calcul (#24).
"""

from __future__ import annotations

from dataclasses import dataclass
from enum import Enum

MIN_LOCAL_SAMPLE = 30

DEGRADED_ALERT = (
    "Données locales insuffisantes. Estimation basée sur la moyenne "
    "départementale — fiabilité réduite."
)
REFUS_MESSAGE = "Données insuffisantes pour produire une estimation sur cette zone."


class CoverageMode(Enum):
    """Origine du coefficient retenu pour une maille."""

    LOCAL = "local"
    DEPARTEMENTAL = "departemental"
    REFUS = "refus"


@dataclass(frozen=True)
class ModeDecision:
    """Mode retenu et bandeau d'alerte à afficher (``None`` en mode local)."""

    mode: CoverageMode
    alerte: str | None


def decide_mode(n_local: int, n_departement: int, *, seuil: int = MIN_LOCAL_SAMPLE) -> ModeDecision:
    """Choisit le mode selon les tailles d'échantillon local et départemental (§5.3)."""
    if n_local >= seuil:
        return ModeDecision(CoverageMode.LOCAL, None)
    if n_departement >= seuil:
        return ModeDecision(CoverageMode.DEPARTEMENTAL, DEGRADED_ALERT)
    return ModeDecision(CoverageMode.REFUS, REFUS_MESSAGE)

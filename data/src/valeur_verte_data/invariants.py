"""Invariants du modèle, évalués en fin de calcul par maille (INV-3, INV-4).

Une maille qui viole un invariant **n'est pas publiée** — elle remonte en alerte
et #22 (mode dégradé départemental) prend le relais sur la zone. Les seuils
viennent de la config (source unique, configurable).

- **INV-4 (#122)** — qualité du modèle : erreur médiane, R² et n. Le R² n'est
  qu'un **garde-fou technique** (détecter un modèle qui n'explique rien), jamais
  exposé au client (#23).
- **INV-3 (#121)** — cohérence géographique : le rayon retenu ne dépasse jamais
  le plafond (hors repli départemental). Les invariants #2 (monotonie du rayon)
  et #3 (facteur 2 entre communes limitrophes) vivent ailleurs (propriété du
  sélecteur, contrôle de fin de batch).
"""

from __future__ import annotations

from dataclasses import dataclass

from .batch import MailleCoefficient
from .config import DEFAULT_CONFIG, CalculConfig
from .degraded import CoverageMode


@dataclass(frozen=True)
class VerdictPublication:
    """Décision de publication d'une maille et motifs de rejet (INV-4)."""

    publiable: bool
    raisons: tuple[str, ...]


def evaluer_invariants_publication(
    maille: MailleCoefficient, config: CalculConfig = DEFAULT_CONFIG
) -> VerdictPublication:
    """Vérifie qu'une maille tient les critères de qualité pour être publiée."""
    raisons: list[str] = []

    if maille.erreur_mediane is None:
        raisons.append("erreur médiane indisponible (validation croisée non calculable)")
    elif maille.erreur_mediane >= config.erreur_mediane_max_pct:
        raisons.append(
            f"erreur médiane {maille.erreur_mediane:.1f} % ≥ {config.erreur_mediane_max_pct:.0f} %"
        )

    if maille.r2 <= config.r2_min:
        raisons.append(f"R² {maille.r2:.2f} ≤ {config.r2_min:.2f}")

    if maille.n_transactions < config.min_local_sample:
        raisons.append(f"n {maille.n_transactions} < {config.min_local_sample}")

    return VerdictPublication(publiable=not raisons, raisons=tuple(raisons))


def evaluer_coherence_geographique(
    maille: MailleCoefficient, config: CalculConfig = DEFAULT_CONFIG
) -> VerdictPublication:
    """Vérifie l'invariant #1 d'INV-3 : le rayon retenu ne dépasse pas le plafond.

    Le repli départemental (mode ``DEPARTEMENTAL``, rayon indéfini) en est
    exempté — c'est le seul cas où le plafond de 50 km ne s'applique pas (#22).
    """
    raisons: list[str] = []

    if (
        maille.mode is not CoverageMode.DEPARTEMENTAL
        and maille.rayon_retenu_km is not None
        and maille.rayon_retenu_km > config.rayon_max_km
    ):
        raisons.append(f"rayon {maille.rayon_retenu_km} km > {config.rayon_max_km} km")

    return VerdictPublication(publiable=not raisons, raisons=tuple(raisons))

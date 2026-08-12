"""INV-4 (#122) — la qualité du modèle comme critère de publication.

Fin de calcul, par maille : une maille dont l'erreur médiane, le R² ou le n
sont hors seuils **n'est pas publiée** — elle remonte en alerte et #22 (mode
dégradé départemental) prend le relais sur la zone. Les seuils viennent de la
config (source unique, configurable). Le R² n'est qu'un **garde-fou technique**
(détecter un modèle qui n'explique rien), jamais exposé au client (#23).
"""

from __future__ import annotations

from dataclasses import dataclass

from .batch import MailleCoefficient
from .config import DEFAULT_CONFIG, CalculConfig


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

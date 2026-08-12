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

from collections.abc import Iterable, Mapping, Sequence
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


@dataclass(frozen=True)
class RuptureLimitrophe:
    """Écart de coef_m2_bati > facteur toléré entre deux communes limitrophes.

    Paire **canonique** (``code_insee_a`` < ``code_insee_b``). ``facteur`` est le
    rapport observé (``max / min``), signalant un artefact de bord probable (§3.2).
    """

    code_insee_a: str
    code_insee_b: str
    coef_a: float
    coef_b: float
    facteur: float


def detecter_ruptures_limitrophes(
    mailles: Iterable[MailleCoefficient],
    adjacence: Mapping[str, Sequence[str]],
    config: CalculConfig = DEFAULT_CONFIG,
) -> tuple[RuptureLimitrophe, ...]:
    """Invariant #3 d'INV-3 : ruptures de coef_m2_bati entre communes limitrophes.

    Ne compare que les paires dont **les deux** communes ont une maille publiée
    et un coef_m2_bati positif ; un coef ≤ 0 relève d'INV-2, pas du bord. La
    sortie est triée sur la paire pour un résultat reproductible (INV-5).
    """
    coef_par_commune = {maille.code_insee: maille.coef_m2_bati for maille in mailles}

    ruptures: dict[tuple[str, str], RuptureLimitrophe] = {}
    for commune, voisins in adjacence.items():
        for voisin in voisins:
            paire = (commune, voisin) if commune < voisin else (voisin, commune)
            if paire in ruptures or paire[0] == paire[1]:
                continue
            coef_a = coef_par_commune.get(paire[0])
            coef_b = coef_par_commune.get(paire[1])
            if coef_a is None or coef_b is None or coef_a <= 0 or coef_b <= 0:
                continue
            facteur = max(coef_a, coef_b) / min(coef_a, coef_b)
            if facteur > config.rupture_facteur_max:
                ruptures[paire] = RuptureLimitrophe(
                    code_insee_a=paire[0],
                    code_insee_b=paire[1],
                    coef_a=coef_a,
                    coef_b=coef_b,
                    facteur=facteur,
                )

    return tuple(ruptures[paire] for paire in sorted(ruptures))

"""Pooling partiel hiérarchique (#249) — cœur empirical-Bayes, pur.

Chaque effet DPE communal ``β̂`` (de variance d'échantillonnage ``v = SE²``, exposée par
``regression.fit``) est tiré vers l'effet **départemental** ``μ`` (le fit poolé, D-E)
proportionnellement à sa **précision** :

    θ̂ = μ + w·(β̂ − μ),   w = τ²/(τ²+v)

- classe bien estimée (``v`` petit devant ``τ²``) ⇒ ``w → 1`` ⇒ quasi inchangée ;
- classe mal estimée (``v`` grand, voire ``+∞`` sans degré de liberté) ⇒ ``w → 0`` ⇒
  tirée vers ``μ`` ;
- communes homogènes (``τ² → 0``) ⇒ pooling complet (``μ`` partout).

La **variance inter-communes** ``τ̂²`` s'estime en forme fermée (méthode des moments
précision-pondérée), autour du **centre fixe** ``μ`` : comme ``E[Σ w (β̂ − μ)²] = τ²·Σw
+ M`` (avec ``w = 1/v`` et donc ``w·v = 1``), on isole ``τ̂² = (Σ w (β̂ − μ)² − M) / Σw``,
plancher 0. Le centre venant d'un **autre** estimateur (fit poolé), on ne retire pas de
degré de liberté (pas le ``M − 1`` de DerSimonian-Laird, qui estime la moyenne depuis les
mêmes données). Tout est en **sommes commutatives** ⇒ déterministe (INV-5).
"""

from __future__ import annotations

import math
from collections.abc import Sequence


def poids_pool(tau2: float, variance: float) -> float:
    """Poids de shrinkage ``w = τ²/(τ²+v)`` (part gardée de l'estimation locale).

    ``variance = +∞`` (maille sans information) ⇒ ``0`` (entièrement tirée vers le prior).
    ``variance = 0`` (précision infinie) ⇒ ``1`` (estimation locale conservée).
    ``τ² = 0`` (communes homogènes) ⇒ ``0`` (pooling complet).
    """
    if not math.isfinite(variance):
        return 0.0
    if variance <= 0.0:
        return 1.0
    return tau2 / (tau2 + variance)


def effet_pool(beta: float, cible: float, tau2: float, variance: float) -> float:
    """Effet DPE shrinké ``θ̂ = μ + w·(β̂ − μ)`` (``cible`` = μ, le fit poolé)."""
    return cible + poids_pool(tau2, variance) * (beta - cible)


def variance_inter(estimations: Sequence[tuple[float, float]], cible: float) -> float | None:
    """Variance inter-communes ``τ̂²`` autour de ``cible``, méthode des moments (§4 note).

    ``estimations`` = suite de ``(β̂, v)``. Les variances **infinies ou nulles** sont
    ignorées (aucune information exploitable / précision dégénérée). ``None`` s'il reste
    **moins de deux** estimations finies : ``τ²`` n'est alors pas séparable du bruit et
    l'appelant garde l'estimation locale (pas de shrinkage).
    """
    finies = [(beta, v) for beta, v in estimations if math.isfinite(v) and v > 0.0]
    if len(finies) < 2:
        return None
    somme_poids = sum(1.0 / v for _, v in finies)
    # w·(β̂−μ)² avec w = 1/v : la dispersion précision-pondérée autour du centre.
    dispersion = sum((beta - cible) ** 2 / v for beta, v in finies)
    return max(0.0, (dispersion - len(finies)) / somme_poids)


def variance_posterieure(tau2: float, variance: float) -> float:
    """Variance a posteriori de ``θ̂`` : ``τ²·v/(τ²+v)`` (IC honnête, #249 D-H).

    ``variance = +∞`` ⇒ ``τ²`` (on ne connaît que le prior) ; ``variance = 0`` ⇒ ``0``
    (effet parfaitement connu) ; ``τ² = 0`` ⇒ ``0`` (effet homogène = μ, connu).
    """
    if not math.isfinite(variance):
        return tau2
    if variance <= 0.0:
        return 0.0
    return tau2 * variance / (tau2 + variance)

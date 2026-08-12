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
- **INV-2 (#120)** — signe et ordre de grandeur : reformulé pour le modèle
  log-prix (effets log, élasticités), on vérifie des signes/ordres (gain E→C > 0,
  effet de seuil, terrain < bâti) et des bornes de magnitude en log.
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


def evaluer_signe_ordre_grandeur(
    maille: MailleCoefficient, config: CalculConfig = DEFAULT_CONFIG
) -> VerdictPublication:
    """INV-2 (#120), reformulé en log : signes, ordres et magnitudes plausibles.

    Les ``coef_dpe`` sont des effets log (gains de rénovation) ; ``coef_m2_bati``
    et ``coef_m2_terrain`` des élasticités. Les contrôles DPE ne s'appliquent que
    si les classes concernées sont publiées.
    """
    raisons: list[str] = []
    coef = maille.coef_dpe

    if "E" in coef and "C" in coef:
        gain_ec = coef["C"] - coef["E"]
        if gain_ec <= 0:
            raisons.append(f"gain E→C {gain_ec:.2f} ≤ 0 (non positif)")
        elif gain_ec > config.gain_dpe_ec_max:
            raisons.append(f"gain E→C {gain_ec:.2f} > {config.gain_dpe_ec_max:.2f}")
        if "D" in coef:
            gain_ed = coef["D"] - coef["E"]
            if gain_ed >= gain_ec:
                raisons.append(f"gain E→D {gain_ed:.2f} ≥ gain E→C {gain_ec:.2f} (seuil rompu)")

    bati = maille.coef_m2_bati
    terrain = maille.coef_m2_terrain
    if bati <= 0:
        raisons.append(f"élasticité bâti {bati:.2f} ≤ 0")
    elif not config.elasticite_bati_min <= bati <= config.elasticite_bati_max:
        raisons.append(
            f"élasticité bâti {bati:.2f} hors "
            f"[{config.elasticite_bati_min:.2f} ; {config.elasticite_bati_max:.2f}]"
        )
    if terrain <= 0:
        raisons.append(f"élasticité terrain {terrain:.2f} ≤ 0")
    elif terrain >= bati:
        raisons.append(f"élasticité terrain {terrain:.2f} ≥ bâti {bati:.2f}")

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


# Bandeau non masquable attaché aux deux communes d'une rupture (§3.2). La maille
# reste publiée : l'écart est signalé au client, pas censuré (décision produit).
_ALERTE_BORD = (
    "Écart de valeur important avec une commune limitrophe (facteur {facteur:.1f}) — "
    "cohérence de bord à vérifier."
)


def alertes_de_bord(ruptures: Iterable[RuptureLimitrophe]) -> dict[str, str]:
    """Message d'alerte par commune impliquée dans une rupture, au **pire** facteur.

    Une commune au carrefour de plusieurs ruptures ne porte qu'un bandeau, calibré
    sur l'écart le plus fort — le plus informatif pour le client.
    """
    pire_facteur: dict[str, float] = {}
    for rupture in ruptures:
        for code in (rupture.code_insee_a, rupture.code_insee_b):
            if rupture.facteur > pire_facteur.get(code, 0.0):
                pire_facteur[code] = rupture.facteur
    return {code: _ALERTE_BORD.format(facteur=facteur) for code, facteur in pire_facteur.items()}

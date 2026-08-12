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
- **INV-1 (#119)** — monotonie de l'échelle DPE : ``coef(A) > … > coef(G)``. Une
  inversion entre classes adjacentes est tolérée (et journalisée) si la moins
  fournie est sous le seuil ; sinon la maille n'est pas publiée.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass
from itertools import pairwise

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


# Échelle DPE, de la classe la plus valorisée (A) à la moins (G) — l'ordre de
# référence de la monotonie (#119). L'index dans cette chaîne donne le rang.
_ECHELLE_DPE = "ABCDEFG"


@dataclass(frozen=True)
class ResultatMonotonie:
    """Verdict de monotonie DPE (INV-1) : anomalies bloquantes + inversions tolérées.

    ``raisons`` non vide ⇒ maille non publiée. ``inversions_tolerees`` est tracé
    même quand la maille reste publiable : c'est le signal qui, six mois plus tard,
    distingue une faiblesse chronique d'une dérive nouvelle.
    """

    raisons: tuple[str, ...]
    inversions_tolerees: tuple[str, ...]

    @property
    def publiable(self) -> bool:
        return not self.raisons


def evaluer_monotonie_dpe(
    maille: MailleCoefficient, config: CalculConfig = DEFAULT_CONFIG
) -> ResultatMonotonie:
    """Vérifie l'ordre ``coef(A) > … > coef(G)`` sur les classes publiées (#119).

    Une inversion (classe meilleure au coef plus bas) n'est tolérée que si les deux
    classes sont **adjacentes** dans l'échelle et que la moins fournie est sous
    ``config.inversion_dpe_seuil`` ; toute autre inversion est une anomalie.
    """
    classes = sorted((c for c in maille.coef_dpe if c in _ECHELLE_DPE), key=_ECHELLE_DPE.index)

    raisons: list[str] = []
    tolerees: list[str] = []
    for meilleure, moins_bonne in pairwise(classes):
        coef_haut = maille.coef_dpe[meilleure]
        coef_bas = maille.coef_dpe[moins_bonne]
        if coef_haut >= coef_bas:
            continue
        adjacentes = _ECHELLE_DPE.index(moins_bonne) - _ECHELLE_DPE.index(meilleure) == 1
        moins_fournie = min(
            maille.effectifs_dpe.get(meilleure, 0), maille.effectifs_dpe.get(moins_bonne, 0)
        )
        trace = f"{meilleure}<{moins_bonne} (coef {coef_haut:.2f} < {coef_bas:.2f})"
        if adjacentes and moins_fournie < config.inversion_dpe_seuil:
            tolerees.append(trace)
        else:
            raisons.append(f"inversion {trace}")

    return ResultatMonotonie(raisons=tuple(raisons), inversions_tolerees=tuple(tolerees))

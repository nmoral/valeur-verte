"""REG-3 (#56) — cas de référence figés et leur vérificateur.

REG-3 attrape la régression de **code/modèle** : celle qui déplace silencieusement
un coefficient **à données constantes**. On rejoue, à chaque déploiement, un jeu de
**cas de référence** — des adresses figées dont la maille recalculée doit rester
dans un domaine **plausible**. Là où REG-1 (#129) mesure la dérive entre millésimes
sur données vivantes, REG-3 tient l'entrée figée : seule une évolution du moteur
peut alors bouger un chiffre.

Les valeurs attendues sont des **fourchettes**, jamais des égalités exactes
(``assert coef == 40729`` est proscrit par ``QUALITE-DONNEES.md`` : il rougit à
chaque millésime et finit mis à jour mécaniquement). Le catégoriel (``mode`` /
``palier``) épingle le **profil** de la maille ; les grandeurs (gain E→C, erreur
médiane, effectif) sont bornées. Ce module est **pur** : ``(cas, maille) → verdict``.
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from enum import Enum

from .batch import MailleCoefficient
from .degraded import CoverageMode, PalierFiabilite


class Profil(Enum):
    """Profil de qualité d'une maille de référence (étiquette de couverture, #56).

    Sert à garantir que le jeu de cas éprouve bien les quatre régimes de
    publication (garde de couverture, articulation #122/INV-4). Le verdict, lui,
    porte sur ``mode``/``palier`` et les fourchettes, pas sur cette étiquette.
    """

    SOLIDE = "solide"
    CORRECTE = "correcte"
    FRAGILE = "fragile"
    REPLI_DEPARTEMENTAL = "repli_departemental"
    REPLI_EPCI = "repli_epci"


@dataclass(frozen=True)
class CasReference:
    """Une adresse figée et le domaine plausible de sa maille (REG-3).

    ``mode_attendu`` / ``palier_attendu`` épinglent le profil (catégoriel exact) ;
    ``n_min``, ``erreur_max_pct`` et ``gain_ec_pct`` bornent les grandeurs.
    """

    code_insee: str
    profil: Profil
    mode_attendu: CoverageMode
    palier_attendu: PalierFiabilite
    n_min: int
    erreur_max_pct: float
    gain_ec_pct: tuple[float, float]


@dataclass(frozen=True)
class Verdict:
    """Résultat de la confrontation d'un cas à sa maille recalculée.

    ``ecarts`` liste les raisons lisibles de non-conformité (vide si conforme) —
    exploitable tel quel dans un rapport de recette ou l'écran de supervision (#45).
    """

    code_insee: str
    conforme: bool
    ecarts: tuple[str, ...]


def verifier_cas(cas: CasReference, maille: MailleCoefficient) -> Verdict:
    """Confronte un cas de référence à la maille recalculée (§REG-3).

    Tous les écarts sont remontés (pas de court-circuit) : le rapport doit dire
    *tout* ce qui a bougé, pas seulement la première anomalie. Un cas est conforme
    si et seulement si aucun écart n'est relevé.
    """
    ecarts: list[str] = []
    if maille.mode is not cas.mode_attendu:
        ecarts.append(f"mode : attendu {cas.mode_attendu.value}, obtenu {maille.mode.value}")
    if maille.palier is not cas.palier_attendu:
        ecarts.append(f"palier : attendu {cas.palier_attendu.value}, obtenu {maille.palier.value}")
    if maille.n_transactions < cas.n_min:
        ecarts.append(f"effectif : {maille.n_transactions} < {cas.n_min} minimum")
    erreur = _ecart_erreur(cas, maille)
    if erreur is not None:
        ecarts.append(erreur)
    gain = _ecart_gain(cas, maille)
    if gain is not None:
        ecarts.append(gain)
    return Verdict(cas.code_insee, not ecarts, tuple(ecarts))


def _ecart_erreur(cas: CasReference, maille: MailleCoefficient) -> str | None:
    """Erreur médiane sous son plafond ; une maille sans erreur est invérifiable."""
    if maille.erreur_mediane is None:
        return "erreur médiane absente : maille non vérifiable"
    if maille.erreur_mediane > cas.erreur_max_pct:
        return f"erreur médiane : {maille.erreur_mediane:.1f} % > {cas.erreur_max_pct:.1f} % max"
    return None


def _ecart_gain(cas: CasReference, maille: MailleCoefficient) -> str | None:
    """Gain E→C ``(exp(coef_C − coef_E) − 1)`` dans sa fourchette (bornes incluses).

    Une classe C ou E **non publiée** rend le gain invérifiable : une classe qui
    disparaît d'un millésime au suivant est un changement majeur, jamais un silence.
    """
    if "C" not in maille.coef_dpe or "E" not in maille.coef_dpe:
        manquante = "C" if "C" not in maille.coef_dpe else "E"
        return f"gain E→C invérifiable : classe {manquante} non publiée"
    gain = (math.exp(maille.coef_dpe["C"] - maille.coef_dpe["E"]) - 1) * 100
    bas, haut = cas.gain_ec_pct
    if gain < bas or gain > haut:
        return f"gain E→C : {gain:.1f} % hors [{bas:.1f} ; {haut:.1f}]"
    return None

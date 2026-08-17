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

import json
import math
from collections.abc import Iterable
from dataclasses import dataclass
from enum import Enum
from typing import Any, TextIO, TypeVar

from .batch import MailleCoefficient
from .degraded import CoverageMode, PalierFiabilite

_EnumT = TypeVar("_EnumT", bound=Enum)


class CasReferenceInvalide(ValueError):
    """Un cas du fichier de recette REG-3 est mal formé (#56).

    Levée **au chargement** (frontière du système) : un jeu de recette invalide
    doit échouer tôt, avec un message nommant le cas et le problème, plutôt que de
    planter obscurément au milieu d'un batch de déploiement.
    """


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


def charger_cas_reference(fichier: TextIO) -> list[CasReference]:
    """Lit un tableau JSON de cas de référence en ``CasReference`` typés (§REG-3).

    Valide chaque cas aux frontières : champs présents, enums connus, fourchette
    de gain ordonnée à deux bornes, cohérence profil ↔ cran. Toute anomalie lève
    ``CasReferenceInvalide`` en nommant le cas et le problème.
    """
    return [_cas_depuis_dict(brut) for brut in json.load(fichier)]


# Régimes de publication que le jeu de recette **doit** éprouver (#56). Le repli
# EPCI en est volontairement exclu : c'est un bonus, pas une exigence de couverture.
PROFILS_REQUIS: frozenset[Profil] = frozenset(
    {Profil.SOLIDE, Profil.CORRECTE, Profil.FRAGILE, Profil.REPLI_DEPARTEMENTAL}
)


def profils_manquants(cas: Iterable[CasReference]) -> frozenset[Profil]:
    """Profils requis (§REG-3) absents du jeu de cas ; vide = couverture complète.

    Un jeu qui ne couvre pas les quatre régimes ne prouve rien sur ceux qu'il omet
    (un « fragile » absent laisse INV-4 non éprouvé, #122). Les doublons ne comptent
    qu'une fois : c'est la **présence** de chaque profil qui fait la couverture.
    """
    return PROFILS_REQUIS - {reference.profil for reference in cas}


def calibrer_cas(profil: Profil, maille: MailleCoefficient, *, tolerance: float) -> CasReference:
    """Transforme une maille recalculée (baseline) en ``CasReference`` (§REG-3).

    ``mode``/``palier``/``code_insee`` viennent de la maille (la réalité) ; les
    grandeurs deviennent des fourchettes **baseline ± tolérance** (arrondies à
    0,1). La baseline est donc toujours dans sa propre fourchette : seule une
    dérive du moteur, à données figées, peut faire échouer le cas ensuite. Refuse
    une maille dont le profil contredit le cran, sans erreur médiane, ou dont la
    classe C ou E n'est pas publiée (gain E→C incalculable).
    """
    attendu = _CRAN_ATTENDU_PAR_PROFIL[profil]
    if maille.mode is not attendu:
        raise CasReferenceInvalide(
            f"cas {maille.code_insee} : profil {profil.value} incohérent avec mode "
            f"{maille.mode.value} (attendu {attendu.value})"
        )
    if maille.erreur_mediane is None:
        raise CasReferenceInvalide(
            f"cas {maille.code_insee} : erreur médiane absente, calibration impossible"
        )
    if "C" not in maille.coef_dpe or "E" not in maille.coef_dpe:
        manquante = "C" if "C" not in maille.coef_dpe else "E"
        raise CasReferenceInvalide(
            f"cas {maille.code_insee} : classe {manquante} non publiée, gain E→C incalculable"
        )
    gain = (math.exp(maille.coef_dpe["C"] - maille.coef_dpe["E"]) - 1) * 100
    return CasReference(
        code_insee=maille.code_insee,
        profil=profil,
        mode_attendu=maille.mode,
        palier_attendu=maille.palier,
        n_min=math.floor(maille.n_transactions * (1 - tolerance)),
        erreur_max_pct=round(maille.erreur_mediane * (1 + tolerance), 1),
        gain_ec_pct=(round(gain * (1 - tolerance), 1), round(gain * (1 + tolerance), 1)),
    )


def serialiser_cas(cas: CasReference) -> dict[str, object]:
    """Sérialise un cas en dict JSON-able — inverse exact de ``charger_cas_reference``."""
    return {
        "code_insee": cas.code_insee,
        "profil": cas.profil.value,
        "mode": cas.mode_attendu.value,
        "palier": cas.palier_attendu.value,
        "n_min": cas.n_min,
        "erreur_max_pct": cas.erreur_max_pct,
        "gain_ec_pct": [cas.gain_ec_pct[0], cas.gain_ec_pct[1]],
    }


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


# Cran de couverture attendu par profil : garantit que l'étiquette du cas colle au
# mode réellement calculé, sinon la garde de couverture (lot 3) devient mensongère.
_CHAMPS_REQUIS = (
    "code_insee",
    "profil",
    "mode",
    "palier",
    "n_min",
    "erreur_max_pct",
    "gain_ec_pct",
)
_CRAN_ATTENDU_PAR_PROFIL: dict[Profil, CoverageMode] = {
    Profil.SOLIDE: CoverageMode.LOCAL,
    Profil.CORRECTE: CoverageMode.LOCAL,
    Profil.FRAGILE: CoverageMode.LOCAL,
    Profil.REPLI_EPCI: CoverageMode.EPCI,
    Profil.REPLI_DEPARTEMENTAL: CoverageMode.DEPARTEMENTAL,
}


def _cas_depuis_dict(brut: Any) -> CasReference:
    """Construit un ``CasReference`` depuis un dict JSON, en validant aux frontières."""
    manquants = [champ for champ in _CHAMPS_REQUIS if champ not in brut]
    if manquants:
        raise CasReferenceInvalide(
            f"cas {brut.get('code_insee', '?')} : champ(s) manquant(s) : {', '.join(manquants)}"
        )
    code_insee = str(brut["code_insee"])
    profil = _enum(Profil, brut["profil"], "profil", code_insee)
    mode = _enum(CoverageMode, brut["mode"], "mode", code_insee)
    palier = _enum(PalierFiabilite, brut["palier"], "palier", code_insee)
    gain = _fourchette(brut["gain_ec_pct"], code_insee)
    attendu = _CRAN_ATTENDU_PAR_PROFIL[profil]
    if mode is not attendu:
        raise CasReferenceInvalide(
            f"cas {code_insee} : profil {profil.value} incohérent avec mode {mode.value} "
            f"(attendu {attendu.value})"
        )
    return CasReference(
        code_insee, profil, mode, palier, int(brut["n_min"]), float(brut["erreur_max_pct"]), gain
    )


def _enum(cls: type[_EnumT], valeur: Any, champ: str, code_insee: str) -> _EnumT:
    """Résout une valeur d'enum, ou lève une erreur nommant le champ et la valeur."""
    try:
        return cls(valeur)
    except ValueError:
        raise CasReferenceInvalide(f"cas {code_insee} : {champ} « {valeur} » inconnu") from None


def _fourchette(valeur: Any, code_insee: str) -> tuple[float, float]:
    """Une fourchette ``[bas, haut]`` ordonnée ; sinon erreur nommant le cas."""
    try:
        bas, haut = valeur
    except (TypeError, ValueError):
        raise CasReferenceInvalide(
            f"cas {code_insee} : « gain_ec_pct » doit être une paire [bas, haut]"
        ) from None
    bas, haut = float(bas), float(haut)
    if bas > haut:
        raise CasReferenceInvalide(
            f"cas {code_insee} : « gain_ec_pct » désordonné : {bas} > {haut}"
        )
    return bas, haut

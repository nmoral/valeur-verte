"""Écarts entre millésimes — REG-1 (#129).

Après un batch, chaque coefficient est comparé à son millésime précédent. L'écart
**relatif** classe la maille : normal (publiée sans réserve), alerte (publiée mais
signalée pour revue manuelle), ou blocage (publication de la maille suspendue,
§REG-1). Décision **pure**, consommée par le batch de calcul.
"""

from __future__ import annotations

import csv
import math
from dataclasses import dataclass, field
from enum import Enum
from typing import TYPE_CHECKING, TextIO

if TYPE_CHECKING:
    from .batch import MailleCoefficient


class ClasseEcart(Enum):
    """Ampleur de l'écart d'un coefficient d'un millésime au précédent (REG-1)."""

    NORMAL = "normal"
    ALERTE = "alerte"
    BLOCAGE = "blocage"


class MotifRefus(Enum):
    """Pourquoi une commune n'entre pas dans un millésime (REG-2, #130).

    Le refus est **différé** : il n'écarte la commune du service qu'à la publication
    du millésime. On distingue le refus sec (rien de calculable) du rejet d'invariant
    (calculé mais hors seuils) — les deux ne demandent pas la même enquête.
    """

    REFUS = "refus"
    REJET_INVARIANT = "rejet_invariant"


# Gravité croissante : la maille retient le pire écart de ses coefficients.
_GRAVITE = (ClasseEcart.NORMAL, ClasseEcart.ALERTE, ClasseEcart.BLOCAGE)


@dataclass(frozen=True)
class EcartMaille:
    """Écart d'une maille à son millésime précédent, prêt pour le rapport REG-1.

    ``classe`` = ampleur (le pire coefficient) ; ``ecart`` = écart relatif de ce
    coefficient (``inf`` pour une apparition depuis zéro) ; ``coefficient`` = libellé
    du coefficient en cause (« DPE F », « élasticité bâti », « classes publiées »).
    """

    classe: ClasseEcart
    ecart: float
    coefficient: str


@dataclass(frozen=True)
class LigneEcart:
    """Une ligne du rapport d'écarts : la maille et l'écart de son pire coefficient."""

    code_insee: str
    classe: ClasseEcart
    ecart: float
    coefficient: str


@dataclass
class RapportEcarts:
    """Rapport d'écarts d'un batch — une ligne par maille comparée au précédent (REG-1).

    Objet dédié (distinct de ``RapportCalcul``) : il alimente l'écran de supervision
    (#45) où les blocages et alertes sont revus avant validation manuelle (REG-2).
    """

    lignes: list[LigneEcart] = field(default_factory=list)

    def ajouter(self, code_insee: str, ecart: EcartMaille) -> None:
        """Journalise l'écart d'une maille."""
        self.lignes.append(LigneEcart(code_insee, ecart.classe, ecart.ecart, ecart.coefficient))

    @property
    def blocages(self) -> list[LigneEcart]:
        """Mailles dont la publication est bloquée (écart > seuil de blocage)."""
        return [ligne for ligne in self.lignes if ligne.classe is ClasseEcart.BLOCAGE]

    @property
    def alertes(self) -> list[LigneEcart]:
        """Mailles publiées mais signalées pour revue manuelle."""
        return [ligne for ligne in self.lignes if ligne.classe is ClasseEcart.ALERTE]


def ecrire_ecarts_csv(rapport: RapportEcarts, fichier: TextIO) -> None:
    """Écrit le rapport d'écarts en CSV, une ligne par maille comparée (REG-1, #129).

    Rend le rapport (sinon en mémoire) **observable** : revue après un batch VPS,
    analyse de la distribution des écarts, et futur écran de supervision (#45).
    L'écart infini (apparition d'un coefficient depuis zéro) est écrit ``inf``.
    """
    writer = csv.writer(fichier)
    writer.writerow(("code_insee", "classe", "ecart_pct", "coefficient"))
    for ligne in rapport.lignes:
        ecart = "inf" if math.isinf(ligne.ecart) else f"{ligne.ecart * 100:.1f}"
        writer.writerow((ligne.code_insee, ligne.classe.value, ecart, ligne.coefficient))


def classer_ecart(
    ancien: float, nouveau: float, *, seuil_alerte: float, seuil_blocage: float
) -> ClasseEcart:
    """Classe l'écart **relatif** entre l'ancien et le nouveau coefficient (§REG-1).

    Écart = ``|nouveau − ancien| / |ancien|`` (symétrique hausse/baisse, rapporté à
    ``|ancien|`` pour rester juste sur les coefficients log négatifs). Sous
    ``seuil_alerte`` c'est ``NORMAL`` ; jusqu'à ``seuil_blocage`` inclus c'est
    ``ALERTE`` ; au-delà ``BLOCAGE`` — bornes calées sur « < 10 % / 10-25 % / > 25 % ».

    Quand ``ancien`` est nul, l'écart relatif est indéfini : une valeur qui apparaît
    (``nouveau`` non nul) est un changement majeur → ``BLOCAGE`` ; deux valeurs nulles
    (ex. la référence D) n'ont aucun écart → ``NORMAL``.
    """
    return _classer(_ecart_relatif(ancien, nouveau), seuil_alerte, seuil_blocage)


def _ecart_relatif(ancien: float, nouveau: float) -> float:
    """Écart relatif ``|nouveau − ancien| / |ancien|`` ; ``inf`` si apparition (ancien nul)."""
    if ancien == 0:
        return 0.0 if nouveau == 0 else math.inf
    return abs(nouveau - ancien) / abs(ancien)


def _classer(ecart: float, seuil_alerte: float, seuil_blocage: float) -> ClasseEcart:
    if ecart < seuil_alerte:
        return ClasseEcart.NORMAL
    if ecart <= seuil_blocage:
        return ClasseEcart.ALERTE
    return ClasseEcart.BLOCAGE


def ecart_maille(
    ancien: MailleCoefficient,
    nouveau: MailleCoefficient,
    *,
    seuil_alerte: float,
    seuil_blocage: float,
) -> EcartMaille:
    """Classe l'écart d'une maille à son millésime précédent, au **pire** coefficient.

    Chaque coefficient est comparé (§REG-1) :

    - **DPE** : sur le facteur de prix ``exp(coef)`` — un effet log peut bouger
      beaucoup sans que le prix bouge, seul l'effet exponentié est parlant produit.
      Seules les classes présentes **des deux côtés** sont comparées (la référence D,
      ``coef=0`` → ``exp=1``, reste stable par construction).
    - **Élasticités** ``coef_m2_bati`` / ``coef_m2_terrain`` : sur la valeur brute.

    Un **changement de l'ensemble des classes publiées** (rebanding entre millésimes,
    une classe qui apparaît ou disparaît) est un changement structurel à revoir → au
    moins ``ALERTE``, même si les coefficients partagés sont stables.
    """
    # (libellé, valeur ancienne, valeur nouvelle) — DPE sur exp(coef), élasticités brutes.
    candidats: list[tuple[str, float, float]] = [
        (f"DPE {dpe}", math.exp(ancien.coef_dpe[dpe]), math.exp(nouveau.coef_dpe[dpe]))
        for dpe in ancien.coef_dpe.keys() & nouveau.coef_dpe.keys()
    ]
    candidats.append(("élasticité bâti", ancien.coef_m2_bati, nouveau.coef_m2_bati))
    candidats.append(("élasticité terrain", ancien.coef_m2_terrain, nouveau.coef_m2_terrain))
    evalues = [
        EcartMaille(_classer(ecart, seuil_alerte, seuil_blocage), ecart, libelle)
        for libelle, avant, apres in candidats
        for ecart in (_ecart_relatif(avant, apres),)
    ]
    if ancien.coef_dpe.keys() != nouveau.coef_dpe.keys():
        evalues.append(EcartMaille(ClasseEcart.ALERTE, 0.0, "classes publiées"))
    return max(evalues, key=lambda r: (_GRAVITE.index(r.classe), r.ecart))

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

# Rang du signal, à classe égale (REG-1, #176, option B) : le signal **produit** — les
# effets DPE et le rebanding des classes publiées — l'emporte sur une **élasticité**
# dans le libellé du coefficient en cause. Ce que voit l'utilisateur prime dans le nommage.
_SIGNAL_PRODUIT = 1
_SIGNAL_ELASTICITE = 0


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


def _plafonner(classe: ClasseEcart, plafond: ClasseEcart) -> ClasseEcart:
    """Ramène une classe à son ``plafond`` de gravité (au plus ``plafond``, REG-1 #176)."""
    return classe if _GRAVITE.index(classe) <= _GRAVITE.index(plafond) else plafond


def ecart_maille(
    ancien: MailleCoefficient,
    nouveau: MailleCoefficient,
    *,
    seuil_alerte: float,
    seuil_blocage: float,
) -> EcartMaille:
    """Classe l'écart d'une maille à son millésime précédent, au **pire** coefficient.

    Le palier ``normal / alerte / blocage`` porte sur le **signal produit — les effets
    DPE** (REG-1, #176). Les **élasticités** sont **informatives** : elles ne servent
    que la surveillance de dérive modèle, jamais le blocage de publication.

    - **DPE** (bloquant) : sur le facteur de prix ``exp(coef)`` — un effet log peut
      bouger beaucoup sans que le prix bouge, seul l'effet exponentié est parlant
      produit. Seules les classes présentes **des deux côtés** sont comparées (la
      référence D, ``coef=0`` → ``exp=1``, reste stable par construction).
    - **Élasticités** ``coef_m2_bati`` / ``coef_m2_terrain`` (informatives) : sur la
      valeur brute, mais **plafonnées à ``ALERTE``** — jamais ``BLOCAGE`` (#176). Ce
      sont des régresseurs internes, invisibles de l'utilisateur, et instables par
      rééchantillonnage annuel (le terrain surtout) : un BLOCAGE dessus n'a bloqué que
      des rouages, pas ce que voit le client.

    Un **changement de l'ensemble des classes publiées** (rebanding entre millésimes,
    une classe qui apparaît ou disparaît) est un changement structurel à revoir → au
    moins ``ALERTE``, même si les coefficients partagés sont stables.

    À classe égale, le **signal produit** (DPE, rebanding) l'emporte sur une élasticité
    dans le libellé du coefficient en cause (option B, #176) ; à rang égal, le plus
    grand écart est retenu.
    """
    # (rang de signal, EcartMaille) — DPE bloquant sur exp(coef), élasticités plafonnées.
    evalues: list[tuple[int, EcartMaille]] = []
    for dpe in ancien.coef_dpe.keys() & nouveau.coef_dpe.keys():
        ecart = _ecart_relatif(math.exp(ancien.coef_dpe[dpe]), math.exp(nouveau.coef_dpe[dpe]))
        classe = _classer(ecart, seuil_alerte, seuil_blocage)
        evalues.append((_SIGNAL_PRODUIT, EcartMaille(classe, ecart, f"DPE {dpe}")))
    for libelle, avant, apres in (
        ("élasticité bâti", ancien.coef_m2_bati, nouveau.coef_m2_bati),
        ("élasticité terrain", ancien.coef_m2_terrain, nouveau.coef_m2_terrain),
    ):
        ecart = _ecart_relatif(avant, apres)
        classe = _plafonner(_classer(ecart, seuil_alerte, seuil_blocage), ClasseEcart.ALERTE)
        evalues.append((_SIGNAL_ELASTICITE, EcartMaille(classe, ecart, libelle)))
    if ancien.coef_dpe.keys() != nouveau.coef_dpe.keys():
        evalues.append((_SIGNAL_PRODUIT, EcartMaille(ClasseEcart.ALERTE, 0.0, "classes publiées")))
    _, pire = max(evalues, key=lambda t: (_GRAVITE.index(t[1].classe), t[0], t[1].ecart))
    return pire

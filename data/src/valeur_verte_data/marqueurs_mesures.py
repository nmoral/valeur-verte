"""Marqueurs mesurés (#341, Lot 2) — dérivés de nos données (``coefficient_maille``).

Contrairement aux référentiels contextuels du Lot 1 (climat, marché…), ces
marqueurs sont des **faits** issus de notre modèle (``type_marqueur = 'mesure'``,
sans badge « hypothèse » côté restitution) :

- ``levier_renovation`` fort/moyen/faible — tertiles **nationaux** du gain E→C ;
- ``sensibilite_dpe`` forte/modérée/faible — tertiles **nationaux** de l'amplitude A↔G ;
- ``fiabilite`` solide/correct/fragile — effectif et rayon de la maille.

Logique **pure** : convertir un écart de coefficients DPE en pourcentage, classer
sur des seuils. La lecture de ``coefficient_maille`` vit dans
``db.coefficient_repository`` ; l'assemblage par département dans ``orchestration``.
"""

from __future__ import annotations

import math
import statistics
from collections.abc import Mapping, Sequence
from dataclasses import dataclass

from .marqueurs import TYPE_MESURE, Marqueur
from .referentiel_communes import departement_de


@dataclass(frozen=True)
class MailleMesurable:
    """Matière première d'une maille publiée pour dériver ses marqueurs mesurés.

    Sous-ensemble de ``coefficient_maille`` (table vive) : ``coef_dpe`` porte le gain
    E→C et l'amplitude A↔G ; ``n_transactions`` et ``rayon_retenu_km`` portent la
    fiabilité (rayon ``None`` = repli EPCI/départemental).
    """

    code_insee: str
    coef_dpe: dict[str, float]
    n_transactions: int
    rayon_retenu_km: int | None

# Fiabilité (port de ``BackOffice/Restitution/IndicateurFiabilite`` côté app, seule
# source jusqu'ici) : dérivée de l'effectif ``n`` et du rayon retenu. Un rayon absent
# (repli EPCI/départemental) est toujours « fragile », quelle que soit la taille.
_EFFECTIF_FRAGILE = 50
_EFFECTIF_SOLIDE = 80
_RAYON_SOLIDE_MAX = 20


def gain_ec(coef_dpe: Mapping[str, float]) -> int | None:
    """Gain de valeur d'une rénovation E→C, en pourcentage entier (fait mesuré).

    Même conversion que la restitution (``exp(coef_C − coef_E) − 1``) : les
    coefficients DPE sont un effet log en base D, l'écart devient un ratio de prix.
    ``None`` si l'une des deux classes n'est pas publiée sur la maille — sans les
    deux bornes, aucun gain n'est calculable.
    """
    return _ecart_pct(coef_dpe, depuis="E", vers="C")


def amplitude_ag(coef_dpe: Mapping[str, float]) -> int | None:
    """Amplitude de l'échelle DPE (écart A↔G), en pourcentage entier (fait mesuré).

    Mesure combien l'étiquette pèse sur cette maille : ``exp(coef_A − coef_G) − 1``.
    ``None`` si A ou G n'est pas publiée (échelle tronquée, pas de sensibilité mesurable).
    """
    return _ecart_pct(coef_dpe, depuis="G", vers="A")


def _ecart_pct(coef_dpe: Mapping[str, float], *, depuis: str, vers: str) -> int | None:
    """Écart de coefficients ``vers``−``depuis`` en pourcentage, ``None`` si incomplet."""
    if depuis not in coef_dpe or vers not in coef_dpe:
        return None
    return round((math.exp(coef_dpe[vers] - coef_dpe[depuis]) - 1) * 100)


def fiabilite(n_transactions: int, rayon_retenu_km: int | None) -> str:
    """Fiabilité lisible d'une maille — ``solide`` / ``correct`` / ``fragile``.

    Règle héritée de la restitution : un rayon absent (repli EPCI/départemental) ou
    un effectif sous le plancher est **fragile** ; un gros échantillon resserré
    (``n ≥ 80`` et rayon ``≤ 20 km``) est **solide** ; entre les deux, **correct**.
    """
    if n_transactions < _EFFECTIF_FRAGILE or rayon_retenu_km is None:
        return "fragile"
    if n_transactions >= _EFFECTIF_SOLIDE and rayon_retenu_km <= _RAYON_SOLIDE_MAX:
        return "solide"
    return "correct"


def tertiles(valeurs: Sequence[float]) -> tuple[float, float]:
    """Seuils nationaux p33/p66 d'une distribution — fig(és) « par millésime ».

    Calculés à la volée sur toutes les communes publiées du millésime : mêmes
    données → mêmes seuils, donc idempotent. Sous deux valeurs, la distribution
    n'est pas exploitable et les seuils sont confondus (aucune classe « moyenne »).
    """
    donnees = sorted(valeurs)
    if len(donnees) < 2:
        seul = float(donnees[0]) if donnees else 0.0
        return (seul, seul)
    p33, p66 = statistics.quantiles(donnees, n=3)
    return (p33, p66)


def _classer(
    valeur: float, seuils: tuple[float, float], *, bas: str, moyen: str, haut: str
) -> str:
    """Classe ``valeur`` par rapport aux seuils : ``≤ p33`` bas, ``≥ p66`` haut, sinon moyen."""
    p33, p66 = seuils
    if valeur <= p33:
        return bas
    if valeur >= p66:
        return haut
    return moyen


def levier_renovation(gain_ec_pct: float, seuils: tuple[float, float]) -> str:
    """Force du levier de rénovation — ``fort`` / ``moyen`` / ``faible``.

    Position du gain E→C de la commune dans la distribution **nationale** : un gain
    au moins égal au tertile haut est un levier ``fort``, au plus égal au tertile
    bas un levier ``faible``. Relatif au parc national, pas un seuil absolu.
    """
    return _classer(gain_ec_pct, seuils, bas="faible", moyen="moyen", haut="fort")


def sensibilite_dpe(amplitude_ag_pct: float, seuils: tuple[float, float]) -> str:
    """Sensibilité au DPE — ``forte`` / ``modérée`` / ``faible``.

    Position de l'amplitude A↔G de la commune dans la distribution **nationale**.
    """
    return _classer(amplitude_ag_pct, seuils, bas="faible", moyen="modérée", haut="forte")


def _source(libelle: str, millesime: int) -> str:
    """Source versionnée d'un marqueur mesuré (critère #341 : tout marqueur est sourcé)."""
    return f"{libelle} — coefficient_maille (millésime {millesime})"


def marqueurs_mesures_departement(
    mailles: Sequence[MailleMesurable], departement: str, millesime: int
) -> list[Marqueur]:
    """Dérive les marqueurs mesurés des communes d'un département (#341, Lot 2).

    Les seuils de ``levier_renovation`` et ``sensibilite_dpe`` sont des **tertiles
    nationaux** : ``mailles`` doit couvrir tout le millésime (pas seulement le
    département), sinon la classification fort/moyen/faible n'a plus de sens. Seules
    les communes du ``departement`` sont émises. La ``fiabilite`` est toujours dérivée
    (n, rayon) ; ``levier_renovation``/``sensibilite_dpe`` seulement quand l'échelle
    DPE porte les classes nécessaires (E+C, A+G).
    """
    seuils_levier = tertiles([g for m in mailles if (g := gain_ec(m.coef_dpe)) is not None])
    seuils_sensibilite = tertiles(
        [a for m in mailles if (a := amplitude_ag(m.coef_dpe)) is not None]
    )

    marqueurs: list[Marqueur] = []
    for maille in mailles:
        if departement_de(maille.code_insee) != departement:
            continue

        gain = gain_ec(maille.coef_dpe)
        if gain is not None:
            marqueurs.append(
                _marqueur(
                    maille.code_insee,
                    "levier_renovation",
                    levier_renovation(gain, seuils_levier),
                    _source("Levier de rénovation (gain E→C, tertiles nationaux)", millesime),
                )
            )

        amplitude = amplitude_ag(maille.coef_dpe)
        if amplitude is not None:
            marqueurs.append(
                _marqueur(
                    maille.code_insee,
                    "sensibilite_dpe",
                    sensibilite_dpe(amplitude, seuils_sensibilite),
                    _source("Sensibilité au DPE (amplitude A↔G, tertiles nationaux)", millesime),
                )
            )

        marqueurs.append(
            _marqueur(
                maille.code_insee,
                "fiabilite",
                fiabilite(maille.n_transactions, maille.rayon_retenu_km),
                _source("Fiabilité (effectif et rayon de la maille)", millesime),
            )
        )

    return marqueurs


def _marqueur(code_insee: str, nom: str, valeur: str, source: str) -> Marqueur:
    """Un marqueur mesuré communal (portée commune, ``type_marqueur = 'mesure'``)."""
    return Marqueur(
        code_insee=code_insee,
        code_departement=None,
        nom=nom,
        valeur=valeur,
        type_marqueur=TYPE_MESURE,
        source=source,
    )

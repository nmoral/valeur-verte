"""DAT-4 (#127) — assertions de distribution (prix, surfaces, étiquettes DPE).

En fin d'ingestion, avant calcul, DAT-4 confronte les distributions **observées**
d'un département — mesurées sur ``data.vente``, ce qui reste après nettoyage §7 — à
des fourchettes de plausibilité et à l'ingestion précédente :

- **plausibilité** (bloque le calcul, comme DAT-1/DAT-3) : prix médian dans une
  fourchette, surface bâtie médiane ∈ [60 ; 160] m², part de terrain nul/aberrant sous
  un seuil. Une médiane départementale grossièrement fausse trahit une ingestion
  cassée (colonne prix en centimes, bâti et terrain intervertis) que DAT-1 ne voit pas
  — les colonnes existent, seul leur contenu est aberrant ;
- **répartition DPE** (alerte seule, **jamais** un blocage) : un déplacement marqué de
  la répartition des étiquettes vs le millésime précédent est **légitime** (réforme DPE
  2026) mais exige une décision humaine — DAT-4 le **rend visible** sans arrêter le
  pipeline.

Décision **pure** : les distributions observées (+ l'histogramme DPE précédent)
entrent, un verdict (bloque + motifs, alerte + motifs) sort.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class Distribution:
    """Distributions observées d'un (dept, millésime), archivées à l'ingestion (#131).

    ``terrain_aberrant`` compte les ventes dont ``surface_terrain`` est nulle ou hors
    bornes de plausibilité ; ``effectif`` est le total des ventes du département (le
    dénominateur de la part aberrante). ``effectifs_dpe`` est l'histogramme des
    étiquettes DPE **renseignées** (classe → nombre), base de la comparaison de
    répartition vs le millésime précédent.
    """

    prix_median: float | None
    surface_bati_median: float | None
    terrain_aberrant: int
    effectif: int
    effectifs_dpe: dict[str, int]


@dataclass(frozen=True)
class VerdictDistribution:
    """Verdict DAT-4 d'un département : ce qui bloque (plausibilité), ce qui alerte (DPE).

    ``bloque``/``motifs`` : la plausibilité barre le calcul (comme DAT-1/DAT-3).
    ``alerte``/``alertes`` : la répartition DPE est signalée sans jamais bloquer. Les
    deux dimensions sont indépendantes — un déplacement DPE n'empêche pas un blocage de
    plausibilité, et réciproquement.
    """

    departement: str
    bloque: bool
    motifs: tuple[str, ...]
    alerte: bool
    alertes: tuple[str, ...]


def distribution_en_dict(distribution: Distribution) -> dict[str, Any]:
    """Sérialise une distribution pour l'archivage JSONB (``data.rapport_batch``)."""
    return {
        "prix_median": distribution.prix_median,
        "surface_bati_median": distribution.surface_bati_median,
        "terrain_aberrant": distribution.terrain_aberrant,
        "effectif": distribution.effectif,
        "dpe": distribution.effectifs_dpe,
    }


def distribution_depuis_dict(brut: dict[str, Any]) -> Distribution:
    """Reconstruit une distribution depuis le JSONB archivé (relecture à la porte).

    ``brut`` provient d'un JSONB relu (non typé) : les médianes nulles restent nulles.
    """
    prix = brut["prix_median"]
    bati = brut["surface_bati_median"]
    return Distribution(
        prix_median=None if prix is None else float(prix),
        surface_bati_median=None if bati is None else float(bati),
        terrain_aberrant=int(brut["terrain_aberrant"]),
        effectif=int(brut["effectif"]),
        effectifs_dpe=dict(brut["dpe"]),
    )


def parts_dpe(effectifs: dict[str, int]) -> dict[str, float]:
    """Répartition (part de chaque classe) d'un histogramme DPE ; vide si total nul."""
    total = sum(effectifs.values())
    if not total:
        return {}
    return {classe: nombre / total for classe, nombre in effectifs.items()}


def ecart_maximal_dpe(
    courant: dict[str, int], precedent: dict[str, int]
) -> tuple[str, float, float, float]:
    """Classe DPE dont la **part** bouge le plus entre deux histogrammes.

    Retourne ``(classe, écart, part_précédente, part_courante)`` — l'écart est
    ``|part_courante − part_précédente|`` maximal sur l'union des classes. Une classe
    absente d'un côté compte pour une part nulle (elle apparaît ou disparaît).
    """
    parts_courant = parts_dpe(courant)
    parts_precedent = parts_dpe(precedent)
    pire_classe = ""
    pire_ecart = -1.0
    pire_precedent = 0.0
    pire_courant = 0.0
    for classe in sorted(parts_courant.keys() | parts_precedent.keys()):
        part_courant = parts_courant.get(classe, 0.0)
        part_precedent = parts_precedent.get(classe, 0.0)
        ecart = abs(part_courant - part_precedent)
        if ecart > pire_ecart:
            pire_classe = classe
            pire_ecart = ecart
            pire_precedent = part_precedent
            pire_courant = part_courant
    return pire_classe, pire_ecart, pire_precedent, pire_courant


def evaluer_distribution(
    departement: str,
    distribution: Distribution,
    effectifs_dpe_precedent: dict[str, int] | None,
    *,
    prix_median_min: float,
    prix_median_max: float,
    bati_median_min: float,
    bati_median_max: float,
    terrain_aberrant_max: float,
    dpe_ecart_max: float,
) -> VerdictDistribution:
    """Confronte les distributions observées aux garde-fous DAT-4 (§DAT-4).

    Bloque (plausibilité) si le prix médian ou la surface bâtie médiane sort de sa
    fourchette (bornes **incluses**), ou si la part de terrain nul/aberrant dépasse
    ``terrain_aberrant_max`` (borne stricte). Alerte (répartition DPE) si une classe
    déplace sa part de plus de ``dpe_ecart_max`` vs le millésime précédent — jamais un
    blocage. Sans précédent (premier chargement), la cohérence DPE n'est pas évaluée.
    """
    motifs = _motifs_plausibilite(
        distribution,
        prix_median_min=prix_median_min,
        prix_median_max=prix_median_max,
        bati_median_min=bati_median_min,
        bati_median_max=bati_median_max,
        terrain_aberrant_max=terrain_aberrant_max,
    )
    alertes = _alertes_dpe(distribution, effectifs_dpe_precedent, dpe_ecart_max=dpe_ecart_max)
    return VerdictDistribution(
        departement, bool(motifs), tuple(motifs), bool(alertes), tuple(alertes)
    )


def _motifs_plausibilite(
    distribution: Distribution,
    *,
    prix_median_min: float,
    prix_median_max: float,
    bati_median_min: float,
    bati_median_max: float,
    terrain_aberrant_max: float,
) -> list[str]:
    motifs: list[str] = []
    prix = distribution.prix_median
    if prix is not None and not prix_median_min <= prix <= prix_median_max:
        motifs.append(
            f"prix médian {prix:.0f} € hors fourchette "
            f"[{prix_median_min:.0f} ; {prix_median_max:.0f}]"
        )
    bati = distribution.surface_bati_median
    if bati is not None and not bati_median_min <= bati <= bati_median_max:
        motifs.append(
            f"surface bâtie médiane {bati:.0f} m² hors fourchette "
            f"[{bati_median_min:.0f} ; {bati_median_max:.0f}]"
        )
    if distribution.effectif:
        part_aberrante = distribution.terrain_aberrant / distribution.effectif
        if part_aberrante > terrain_aberrant_max:
            motifs.append(
                f"terrain nul/aberrant {part_aberrante:.0%} "
                f"au-dessus du seuil {terrain_aberrant_max:.0%}"
            )
    return motifs


def _alertes_dpe(
    distribution: Distribution,
    effectifs_dpe_precedent: dict[str, int] | None,
    *,
    dpe_ecart_max: float,
) -> list[str]:
    if not effectifs_dpe_precedent:
        return []
    classe, ecart, part_precedent, part_courant = ecart_maximal_dpe(
        distribution.effectifs_dpe, effectifs_dpe_precedent
    )
    # Les parts sont des fractions : 0,65 − 0,50 = 0,150000000000000002 dépasserait
    # 0,15 à tort. Arrondi avant comparaison (même garde que la chute DAT-3).
    if round(ecart, 10) > dpe_ecart_max:
        return [
            f"répartition DPE : classe {classe} déplacée de {ecart * 100:.0f} pts vs "
            f"précédent ({part_precedent:.0%} → {part_courant:.0%})"
        ]
    return []

"""Bandes DPE adaptatives (#169).

Regroupe les classes DPE adjacentes sous-effectif en bandes atteignant un seuil,
en préservant la granularité maximale. Destiné aux crans EPCI et départemental de
la cascade de couverture (le local reste en classes pleines). Fonction **pure** :
effectifs par classe → mapping ``classe → représentant de bande``.
"""

from __future__ import annotations

from collections.abc import Mapping

# Échelle DPE, de la mieux valorisée (A) à la moins (G) : l'index donne le rang.
_ECHELLE_DPE = "ABCDEFG"


def bandes_adaptatives(
    effectifs: Mapping[str, int], seuil: int, *, reference: str = "D"
) -> dict[str, str]:
    """Regroupe les classes DPE sous-effectif en bandes contiguës ≥ ``seuil``.

    Fusionne la plus petite bande sous le seuil avec son voisin **le moins fourni**
    (égalité → côté de la meilleure classe), jusqu'à ce que chaque bande atteigne le
    seuil. Le représentant d'une bande est la classe **la plus proche de
    ``reference``** — la bande contenant D reste ainsi « D ».

    Renvoie ``{}`` si moins de deux bandes ≥ ``seuil`` sont possibles : une bande
    unique ne dit rien (rien à comparer), ses classes sont exclues et la maille sera
    refusée en aval (``min_classes_publiees``).
    """
    classes = sorted(
        (classe for classe in effectifs if classe in _ECHELLE_DPE),
        key=_ECHELLE_DPE.index,
    )
    bandes: list[list[str]] = [[classe] for classe in classes]
    poids: list[int] = [effectifs[classe] for classe in classes]

    while len(bandes) > 1 and min(poids) < seuil:
        indice = min(
            (i for i in range(len(bandes)) if poids[i] < seuil),
            key=lambda i: poids[i],
        )
        gauche = poids[indice - 1] if indice > 0 else None
        droite = poids[indice + 1] if indice + 1 < len(bandes) else None
        if droite is None or (gauche is not None and gauche <= droite):
            cible = indice - 1
        else:
            cible = indice + 1
        bas, haut = sorted((indice, cible))
        bandes[bas : haut + 1] = [bandes[bas] + bandes[haut]]
        poids[bas : haut + 1] = [poids[bas] + poids[haut]]

    if len(bandes) < 2:
        return {}

    rang_reference = _ECHELLE_DPE.index(reference)
    mapping: dict[str, str] = {}
    for bande in bandes:
        representant = min(
            bande,
            key=lambda classe: (
                abs(_ECHELLE_DPE.index(classe) - rang_reference),
                _ECHELLE_DPE.index(classe),
            ),
        )
        for classe in bande:
            mapping[classe] = representant
    return mapping


def fusionner_inversions(
    bandes: Mapping[str, str], coef: Mapping[str, float], *, reference: str = "D"
) -> dict[str, str]:
    """Fusionne la **première** paire de bandes adjacentes qui inverse.

    ``bandes`` = ``classe → représentant`` (banding courant) ; ``coef`` =
    ``représentant → coefficient`` du dernier ajustement. Les bandes sont ordonnées
    A→G (mieux valorisées d'abord) ; le coefficient est attendu décroissant. Une
    **inversion** est une paire adjacente où la meilleure bande a un coefficient
    **strictement inférieur** à la moins bonne (égalité tolérée, cf. INV-1).

    Ne traite qu'**une** inversion par appel (la première côté A) : l'orchestration
    boucle ajustement → fusion → ré-ajustement jusqu'à monotonie, les coefficients
    changeant à chaque fusion. Le représentant de la bande fusionnée est la classe la
    plus proche de ``reference``. Peut réduire à une seule bande — le refus est géré
    en aval (``min_classes_publiees``).
    """
    classes = sorted((c for c in bandes if c in _ECHELLE_DPE), key=_ECHELLE_DPE.index)
    reps_ordonnes: list[str] = []
    for classe in classes:
        representant = bandes[classe]
        if representant not in reps_ordonnes:
            reps_ordonnes.append(representant)

    rang_reference = _ECHELLE_DPE.index(reference)
    for meilleure, moins_bonne in zip(reps_ordonnes, reps_ordonnes[1:], strict=False):
        if coef[meilleure] < coef[moins_bonne]:
            a_fusionner = {meilleure, moins_bonne}
            membres = [classe for classe in classes if bandes[classe] in a_fusionner]
            nouveau_rep = min(
                membres,
                key=lambda classe: (
                    abs(_ECHELLE_DPE.index(classe) - rang_reference),
                    _ECHELLE_DPE.index(classe),
                ),
            )
            return {
                classe: (nouveau_rep if representant in a_fusionner else representant)
                for classe, representant in bandes.items()
            }

    return dict(bandes)

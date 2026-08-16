"""Dérive la carte d'adjacence départementale de `limitrophes.LIMITROPHES` (#223).

Provenance de la table statique `valeur_verte_data.limitrophes.LIMITROPHES` : elle
n'est pas saisie à la main mais **dérivée des contours départementaux** de
`dept-contours.geojson` (la même source que la carte de validation), pour être une
vraie vérité géographique et non une liste approximative.

Deux départements sont déclarés limitrophes s'ils partagent une **frontière réelle**
(un segment), pas un simple **contact ponctuel** aux tripoints/quadripoints (ex. la
Haute-Saône 70 et le Haut-Rhin 68 ne se touchent qu'en un point via le Ballon
d'Alsace → non limitrophes). Les contours étant simplifiés pour l'affichage, on
bufferise légèrement pour combler les micro-jeux de sommets, puis on mesure la
longueur de frontière effectivement partagée et on écarte ce qui est sous le seuil.

Ce script est un **outil de régénération** (contours mis à jour, ajout des DROM…),
pas du code de production : il dépend de `shapely`, hors dépendances du projet.

    python -m venv /tmp/sv && /tmp/sv/bin/pip install shapely
    /tmp/sv/bin/python docs/carte-validation/deriver-limitrophes.py

Le résultat est à recopier tel quel dans `data/src/valeur_verte_data/limitrophes.py`.
Les invariants (couverture des 96 départements, symétrie, pas d'auto-voisin, Corse
isolée) sont verrouillés côté tests (`data/tests/test_limitrophes.py`).
"""

from __future__ import annotations

import itertools
import json
from pathlib import Path

from shapely.geometry import shape

# Buffer de comblement des micro-jeux de sommets (~90 m) et seuil de frontière
# partagée (~exclut les contacts ponctuels sans rogner la plus courte frontière
# réelle, Var/Vaucluse 83-84 ≈ 0,006).
BUFFER_DEG = 0.0008
SEUIL_FRONTIERE = 0.003


def deriver(geojson: Path) -> dict[str, tuple[str, ...]]:
    """Adjacence {département: voisins triés} dérivée des contours."""
    features = json.loads(geojson.read_text())["features"]
    geoms = {f["properties"]["dept"]: shape(f["geometry"]).buffer(0) for f in features}
    codes = sorted(geoms)

    adjacence: dict[str, set[str]] = {code: set() for code in codes}
    for a, b in itertools.combinations(codes, 2):
        if not geoms[a].buffer(BUFFER_DEG).intersects(geoms[b]):
            continue
        cote_a = geoms[a].boundary.intersection(geoms[b].buffer(BUFFER_DEG)).length
        cote_b = geoms[b].boundary.intersection(geoms[a].buffer(BUFFER_DEG)).length
        if min(cote_a, cote_b) > SEUIL_FRONTIERE:
            adjacence[a].add(b)
            adjacence[b].add(a)

    return {code: tuple(sorted(adjacence[code])) for code in codes}


def main() -> None:
    carte = deriver(Path(__file__).parent / "dept-contours.geojson")
    for code, voisins in carte.items():
        virgule = "," if len(voisins) == 1 else ""
        elements = ", ".join(f'"{v}"' for v in voisins)
        print(f'    "{code}": ({elements}{virgule}),')


if __name__ == "__main__":
    main()

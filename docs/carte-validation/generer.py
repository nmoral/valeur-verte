#!/usr/bin/env python3
"""Génère la carte de validation France (choroplèthe) d'un millésime.

Assemble un HTML autonome (SVG + JS inline, sans dépendance réseau) à partir de
trois exports du schéma ``data`` et du template ``template.html``. Les métriques
sont agrégées **par département** ; la carte les colore (granularité, fiabilité,
premium E→C, couverture) avec un détail au survol.

Source des données = ``data.coefficient_maille_historique`` (le millésime **calculé
et archivé**, avant publication REG-2). Voir ``README.md`` pour les requêtes d'export.

Entrées (dans ``--data-dir``, défaut = ce dossier) :
  - ``dept-contours.geojson``       contours départementaux (ST_Union de commune_contour)
  - ``mailles-<millesime>.csv``     coefficient_maille_historique du millésime
  - ``communes-par-dept.csv``       nb de communes par département (pour la couverture)

Sortie : ``validation-france-<millesime>.html``.

Usage : ``python generer.py [--millesime 2025] [--data-dir .]``
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import statistics
from collections import defaultdict
from pathlib import Path

# Codes INSEE département → nom (métropole, avec la Corse 2A/2B).
NOMS = {
    "01": "Ain", "02": "Aisne", "03": "Allier", "04": "Alpes-de-Hte-Provence",
    "05": "Hautes-Alpes", "06": "Alpes-Maritimes", "07": "Ardèche", "08": "Ardennes",
    "09": "Ariège", "10": "Aube", "11": "Aude", "12": "Aveyron", "13": "Bouches-du-Rhône",
    "14": "Calvados", "15": "Cantal", "16": "Charente", "17": "Charente-Maritime",
    "18": "Cher", "19": "Corrèze", "2A": "Corse-du-Sud", "2B": "Haute-Corse",
    "21": "Côte-d'Or", "22": "Côtes-d'Armor", "23": "Creuse", "24": "Dordogne",
    "25": "Doubs", "26": "Drôme", "27": "Eure", "28": "Eure-et-Loir", "29": "Finistère",
    "30": "Gard", "31": "Haute-Garonne", "32": "Gers", "33": "Gironde", "34": "Hérault",
    "35": "Ille-et-Vilaine", "36": "Indre", "37": "Indre-et-Loire", "38": "Isère",
    "39": "Jura", "40": "Landes", "41": "Loir-et-Cher", "42": "Loire", "43": "Haute-Loire",
    "44": "Loire-Atlantique", "45": "Loiret", "46": "Lot", "47": "Lot-et-Garonne",
    "48": "Lozère", "49": "Maine-et-Loire", "50": "Manche", "51": "Marne",
    "52": "Haute-Marne", "53": "Mayenne", "54": "Meurthe-et-Moselle", "55": "Meuse",
    "56": "Morbihan", "57": "Moselle", "58": "Nièvre", "59": "Nord", "60": "Oise",
    "61": "Orne", "62": "Pas-de-Calais", "63": "Puy-de-Dôme", "64": "Pyrénées-Atlantiques",
    "65": "Hautes-Pyrénées", "66": "Pyrénées-Orientales", "67": "Bas-Rhin", "68": "Haut-Rhin",
    "69": "Rhône", "70": "Haute-Saône", "71": "Saône-et-Loire", "72": "Sarthe",
    "73": "Savoie", "74": "Haute-Savoie", "75": "Paris", "76": "Seine-Maritime",
    "77": "Seine-et-Marne", "78": "Yvelines", "79": "Deux-Sèvres", "80": "Somme",
    "81": "Tarn", "82": "Tarn-et-Garonne", "83": "Var", "84": "Vaucluse", "85": "Vendée",
    "86": "Vienne", "87": "Haute-Vienne", "88": "Vosges", "89": "Yonne",
    "90": "Terr. de Belfort", "91": "Essonne", "92": "Hauts-de-Seine",
    "93": "Seine-Saint-Denis", "94": "Val-de-Marne", "95": "Val-d'Oise",
}

# La projection doit produire la même boîte que le viewBox figé du template.
LARGEUR, HAUTEUR, MARGE = 760, 720, 10


def _gain_ec(coef_json: str) -> float | None:
    """Gain E→C en % : ``exp(coef_C − coef_E) − 1``, ou None si C/E absentes."""
    try:
        coefs = json.loads(coef_json)
    except (json.JSONDecodeError, TypeError):
        return None
    if "C" not in coefs or "E" not in coefs:
        return None
    return (math.e ** (coefs["C"] - coefs["E"]) - 1) * 100


def charger_metriques(mailles_csv: Path, communes_csv: Path) -> dict[str, dict]:
    """Agrège par département : effectif, couverture, répartition de mode, tier dégradé, gain E→C."""
    communes = {row[0]: int(row[1]) for row in csv.reader(communes_csv.open()) if len(row) == 2}
    par_dept: dict[str, list] = defaultdict(list)
    with mailles_csv.open(newline="") as f:
        for row in csv.reader(f):
            if len(row) < 8:
                continue
            # code_insee, mode, n, erreur, palier, alerte, coef_dpe, effectifs_dpe
            par_dept[row[0][:2]].append(row)

    metriques: dict[str, dict] = {}
    for dept, mailles in par_dept.items():
        n = len(mailles)
        gains = [g for m in mailles if (g := _gain_ec(m[6])) is not None]
        metriques[dept] = {
            "n": n,
            "cov": round(100 * n / communes.get(dept, n)),
            "local": round(100 * sum(1 for m in mailles if m[1] == "local") / n),
            "dept": round(100 * sum(1 for m in mailles if m[1] == "departemental") / n),
            "degrade": round(100 * sum(1 for m in mailles if m[4] == "degrade") / n),
            "gain": round(statistics.median(gains)) if gains else None,
        }
    return metriques


def projeter_contours(geojson_path: Path) -> list[dict]:
    """Projette chaque contour départemental en un chemin SVG (équirectangulaire, corrigé en longitude)."""
    geo = json.loads(geojson_path.read_text())

    def anneaux(geometrie: dict) -> list:
        if geometrie["type"] == "Polygon":
            return [geometrie["coordinates"]]
        return geometrie["coordinates"]  # MultiPolygon

    xs, ys = [], []
    for feature in geo["features"]:
        for polygone in anneaux(feature["geometry"]):
            for anneau in polygone:
                for lon, lat in anneau:
                    xs.append(lon)
                    ys.append(lat)
    min_lon, max_lon, min_lat, max_lat = min(xs), max(xs), min(ys), max(ys)
    lat_ref = math.radians((min_lat + max_lat) / 2)

    def px(lon: float) -> float:
        return lon * math.cos(lat_ref)

    pmin, pmax = px(min_lon), px(max_lon)
    echelle = min((LARGEUR - 2 * MARGE) / (pmax - pmin), (HAUTEUR - 2 * MARGE) / (max_lat - min_lat))

    def point(lon: float, lat: float) -> tuple[float, float]:
        x = MARGE + (px(lon) - pmin) * echelle
        y = MARGE + (max_lat - lat) * echelle
        return round(x, 1), round(y, 1)

    feats = []
    for feature in geo["features"]:
        dept = feature["properties"]["dept"]
        chemin = ""
        for polygone in anneaux(feature["geometry"]):
            for anneau in polygone:
                chemin += "M" + " L".join(f"{a},{b}" for a, b in (point(*c) for c in anneau)) + "Z"
        feats.append({"dept": dept, "nom": NOMS.get(dept, dept), "path": chemin})
    return feats


def generer(millesime: str, data_dir: Path) -> Path:
    metriques = charger_metriques(
        data_dir / f"mailles-{millesime}.csv", data_dir / "communes-par-dept.csv"
    )
    feats = projeter_contours(data_dir / "dept-contours.geojson")
    for feature in feats:
        feature["m"] = metriques.get(feature["dept"])

    donnees = json.dumps(
        {"w": LARGEUR, "h": HAUTEUR, "feats": feats}, ensure_ascii=False, separators=(",", ":")
    )
    template = (Path(__file__).parent / "template.html").read_text()
    html = template.replace("__DATA__", donnees)
    sortie = data_dir / f"validation-france-{millesime}.html"
    sortie.write_text(html)
    return sortie


def main() -> None:
    parser = argparse.ArgumentParser(description="Génère la carte de validation France.")
    parser.add_argument("--millesime", default="2025", help="Millésime à cartographier (défaut 2025).")
    parser.add_argument(
        "--data-dir", default=str(Path(__file__).parent), help="Dossier des exports (défaut : ce dossier)."
    )
    args = parser.parse_args()
    sortie = generer(args.millesime, Path(args.data_dir))
    print(f"écrit : {sortie}")


if __name__ == "__main__":
    main()

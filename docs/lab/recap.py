"""Run récapitulatif : config gagnante vs défauts, toutes métriques, base sans bornes."""

from __future__ import annotations

import math
import os

import psycopg

from valeur_verte_data.batch import _sigma_local, compute_maille
from valeur_verte_data.config import CalculConfig
from valeur_verte_data.db.pool_repository import load_pool
from valeur_verte_data.limitrophes import limitrophes
from valeur_verte_data.radius import adaptive_selection

COMMUNES = [
    ("31555", "Toulouse (urbain dense)"),
    ("31149", "Colomiers (périurbain)"),
    ("31135", "Cazères (rural 31)"),
    ("09122", "Foix (rural 09)"),
    ("09306", "Tarascon (petit n)"),
    ("65160", "65160 (montagne, r15)"),
    ("65481", "65481 (montagne isolée, r20)"),
]

DEFAUTS = CalculConfig()
GAGNANTE = CalculConfig(
    restreindre_bati_ancien=False,  # S20
    ecretage="residu",  # S32 V2
    sigma_mode="densite",  # S23
    sigma_diviseur=1.0,
    sigma_min=3.0,
    effets_fixes_commune=False,  # S22
    indice_local=True,  # S24 (gardé)
    min_class_sample=20,  # S21
)
CLASSES = ["A", "B", "C", "D", "E", "F", "G"]


def dpe_pct(coef_dpe: dict[str, float]) -> str:
    parts = []
    for classe in CLASSES:
        if classe in coef_dpe:
            parts.append(f"{classe}:{(math.exp(coef_dpe[classe]) - 1) * 100:+.0f}%")
    return " ".join(parts)


def gain_ec(coef_dpe: dict[str, float]) -> str:
    if "C" in coef_dpe and "E" in coef_dpe:
        return f"{(math.exp(coef_dpe['C'] - coef_dpe['E']) - 1) * 100:+.1f}%"
    return "n/a"


def ligne(conn: psycopg.Connection, code: str, pool: list, config: CalculConfig) -> str:
    row = conn.execute(
        "select avg(latitude),avg(longitude) from data.vente "
        "where code_insee=%s and type_local='Maison' and latitude is not null",
        [code],
    ).fetchone()
    lat, lon = float(row[0]), float(row[1])
    sel = adaptive_selection(lat, lon, pool, min_sample=config.min_sample, radii=config.radii_km)
    sigma = _sigma_local(config, sel)
    m = compute_maille(code, lat, lon, 2024, pool, config=config)
    if m is None:
        return f"    d80={sel.distance_min_sample:5.2f}km  REFUS"
    rayon = "dept" if m.rayon_retenu_km is None else f"{m.rayon_retenu_km}km"
    return (
        f"    d80={sel.distance_min_sample:5.2f}km  n={m.n_transactions:<4} mode={m.mode.value[:5]:<6} "
        f"rayon={rayon:<5} sigma={sigma:4.1f}  r2={m.r2:5.3f}  errCV={m.erreur_mediane or 0:4.1f}%  "
        f"m2bati={m.coef_m2_bati:.2f} m2terr={m.coef_m2_terrain:.2f}  gainE-C={gain_ec(m.coef_dpe):>7}  "
        f"classes={len(m.coef_dpe)}\n    DPE(%): {dpe_pct(m.coef_dpe)}"
    )


def main() -> None:
    dsn = os.environ["DATABASE_URL"]
    with psycopg.connect(dsn) as conn:
        pools = {d: load_pool(conn, d, 2024, limitrophes=limitrophes(d)) for d in ("31", "09", "65")}
        for code, label in COMMUNES:
            pool = pools[code[:2]]
            print(f"### {label}  [{code}]")
            print("  Défauts   :")
            print(ligne(conn, code, pool, DEFAUTS))
            print("  Gagnante  :")
            print(ligne(conn, code, pool, GAGNANTE))
            print()


if __name__ == "__main__":
    main()

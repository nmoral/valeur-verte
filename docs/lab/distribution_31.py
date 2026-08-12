"""Config gagnante sur TOUT le 31 : métriques de distribution, pas par commune.

Ce qui décide de ce qu'on peut publier : quelle part des mailles calcule, passe
sous errCV 20 %, et présente une violation de monotonie DPE.
"""

from __future__ import annotations

import math
import os

import numpy as np
import psycopg

from valeur_verte_data.batch import compute_maille
from valeur_verte_data.config import CalculConfig
from valeur_verte_data.db.pool_repository import load_pool
from valeur_verte_data.limitrophes import limitrophes

GAGNANTE = CalculConfig(
    restreindre_bati_ancien=False,
    ecretage="residu",
    sigma_mode="densite",
    sigma_diviseur=1.0,
    sigma_min=3.0,
    effets_fixes_commune=False,
    min_class_sample=20,
)
CLASSES = ["A", "B", "C", "D", "E", "F", "G"]


def nb_violations(coef_dpe: dict[str, float]) -> int:
    present = [c for c in CLASSES if c in coef_dpe]
    return sum(1 for a, b in zip(present, present[1:]) if coef_dpe[a] <= coef_dpe[b])


def main() -> None:
    dsn = os.environ["DATABASE_URL"]
    with psycopg.connect(dsn) as conn:
        pool = load_pool(conn, "31", 2024, limitrophes=limitrophes("31"))
        communes = conn.execute(
            "SELECT code_insee, avg(latitude), avg(longitude), count(*) "
            "FROM data.vente WHERE code_insee LIKE '31%' AND type_local='Maison' "
            "AND latitude IS NOT NULL GROUP BY code_insee"
        ).fetchall()

    total = len(communes)
    refus = calc = err_ok = avec_viol = publiables = 0
    sans_gain_ec = publiable_ec = 0
    errs: list[float] = []
    # Pondération par le nombre de ventes de maisons de la commune (part de marché).
    tx_total = sum(int(c[3]) for c in communes)
    tx_calc = tx_err_ok = tx_publiable_ec = tx_publiable_strict = 0
    for code, lat, lon, n in communes:
        n = int(n)
        m = compute_maille(str(code), float(lat), float(lon), 2024, pool, config=GAGNANTE)
        if m is None:
            refus += 1
            continue
        calc += 1
        tx_calc += n
        e = m.erreur_mediane
        if e is not None:
            errs.append(e)
        ok = e is not None and e < 20.0
        viol = nb_violations(m.coef_dpe)
        d = m.coef_dpe
        a_gain_ec = "C" in d and "E" in d
        gain_sain = a_gain_ec and d["C"] > d["E"]
        if ok:
            err_ok += 1
            tx_err_ok += n
        if viol:
            avec_viol += 1
        if ok and viol == 0:
            publiables += 1
            tx_publiable_strict += n
        if not a_gain_ec:
            sans_gain_ec += 1
        if ok and gain_sain:
            publiable_ec += 1
            tx_publiable_ec += n

    def pct(x: int, base: int) -> str:
        return f"{x} ({x / base * 100:.0f}%)" if base else f"{x} (—)"

    print(f"Config gagnante — département 31 complet, {total} communes")
    print(f"  calculées : {pct(calc, total)}   |   REFUS : {pct(refus, total)}")
    print(f"  parmi calculées :")
    print(f"    errCV < 20 %              : {pct(err_ok, calc)}")
    print(f"    avec violation monotonie  : {pct(avec_viol, calc)}")
    print(f"    sans gain E→C (C ou E abs): {pct(sans_gain_ec, calc)}")
    print(f"  PUBLIABLES strict (calc & err<20 & monotone) : {pct(publiables, total)} du total")
    print(f"  PUBLIABLES gain E→C (calc & err<20 & C>E)    : {pct(publiable_ec, total)} du total")
    if errs:
        arr = np.array(errs)
        print(
            f"  errCV : médiane={np.median(arr):.1f}  p25={np.percentile(arr, 25):.1f}  "
            f"p75={np.percentile(arr, 75):.1f}  p90={np.percentile(arr, 90):.1f}  max={arr.max():.1f}"
        )
    print(f"\n  === Pondéré par les ventes (part de marché, {tx_total} ventes) ===")
    print(f"    couvertes par une maille calculée   : {pct(tx_calc, tx_total)}")
    print(f"    dans une maille errCV < 20 %        : {pct(tx_err_ok, tx_total)}")
    print(f"    publiable strict (monotone)         : {pct(tx_publiable_strict, tx_total)}")
    print(f"    publiable gain E→C                  : {pct(tx_publiable_ec, tx_total)}")


if __name__ == "__main__":
    main()

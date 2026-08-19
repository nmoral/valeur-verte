#!/usr/bin/env python3
"""Analyse comparative pooling hierarchique sur plusieurs millesimes.

Lit les CSV /opt/valeur-verte/dumps/pooling_m_<N>.csv (colonnes :
code_insee, mode, n_transactions, erreur_mediane, palier, alerte,
coef_dpe, effectifs_dpe, se_dpe, classe_ecart, ecart).

Axes (integre l'orientation « C ou B ? » de vv-economie-cb-publication) :
  1. Solidite du B par millesime : couverture, effectif median, SE median (pooling).
  2. Ecart C/B : gain median E->C vs C->B, ratio, universalite par departement.
  3. Derive inter-annee sur panel stable (mailles communes a tous les millesimes).
  4. Effet du pooling sur la precision : SE median des effets par annee.

Usage : python3 analyse_pooling_millesimes.py <dir_csv>
"""
import csv
import glob
import json
import math
import os
import re
import statistics
import sys

SEUIL_PUBLIABLE = 20  # effectif minimal pour juger une classe fiable (cf. economie C->B)


def med(xs):
    xs = [x for x in xs if x is not None]
    return statistics.median(xs) if xs else None


def gain(coef, a, b):
    """Gain relatif a->b en % : exp(coef_b - coef_a) - 1. Coefs en log, ref D=0."""
    if coef is None or a not in coef or b not in coef:
        return None
    return (math.exp(coef[b] - coef[a]) - 1.0) * 100.0


def publiable(eff, classe):
    return eff is not None and eff.get(classe, 0) >= SEUIL_PUBLIABLE


def charger(path):
    lignes = []
    with open(path, newline="") as f:
        for r in csv.DictReader(f):
            def js(col):
                v = r.get(col)
                if not v:
                    return None
                try:
                    return json.loads(v)
                except (ValueError, TypeError):
                    return None
            lignes.append({
                "code_insee": r["code_insee"],
                "dept": r["code_insee"][:2],
                "mode": r.get("mode"),
                "n": int(r["n_transactions"]) if r.get("n_transactions") else 0,
                "coef": js("coef_dpe"),
                "eff": js("effectifs_dpe"),
                "se": js("se_dpe"),
            })
    return lignes


def analyse_millesime(N, lignes):
    total = len(lignes)
    # --- Solidite du B ---
    b_pub = [l for l in lignes if publiable(l["eff"], "B")]
    depts_b = {l["dept"] for l in b_pub}
    eff_b = [l["eff"]["B"] for l in b_pub]
    se_b = [l["se"]["B"] for l in b_pub if l["se"] and l["se"].get("B") is not None]
    se_c = [l["se"]["C"] for l in lignes if l["se"] and l["se"].get("C") is not None]
    # --- Ecart C/B sur mailles ou B, C, E sont tous fiables ---
    bce = [l for l in lignes if all(publiable(l["eff"], k) for k in ("B", "C", "E"))]
    ec = [gain(l["coef"], "E", "C") for l in bce]
    cb = [gain(l["coef"], "C", "B") for l in bce]
    eb = [gain(l["coef"], "E", "B") for l in bce]
    med_ec, med_cb = med(ec), med(cb)
    # --- Universalite par departement (>= 20 mailles B/C/E fiables) ---
    par_dept = {}
    for l in bce:
        par_dept.setdefault(l["dept"], []).append(l)
    depts_ok, cb_inf_ec, cb_pos = 0, 0, 0
    for d, ls in par_dept.items():
        if len(ls) < 20:
            continue
        depts_ok += 1
        dcb = med([gain(l["coef"], "C", "B") for l in ls])
        dec = med([gain(l["coef"], "E", "C") for l in ls])
        if dcb is not None and dec is not None and dcb < dec:
            cb_inf_ec += 1
        if dcb is not None and dcb > 0:
            cb_pos += 1
    return {
        "N": N,
        "total": total,
        "b_pub": len(b_pub),
        "b_pub_pct": 100.0 * len(b_pub) / total if total else 0,
        "depts_b": len(depts_b),
        "eff_b_med": med(eff_b),
        "se_b_med": med(se_b),
        "se_c_med": med(se_c),
        "n_bce": len(bce),
        "med_ec": med_ec,
        "med_cb": med_cb,
        "med_eb": med(eb),
        "ratio_ec_cb": (med_ec / med_cb) if (med_ec and med_cb) else None,
        "depts_ok": depts_ok,
        "cb_inf_ec": cb_inf_ec,
        "cb_pos": cb_pos,
        "bce_index": {l["code_insee"]: l for l in bce},
    }


def fmt(x, suf="", nd=1):
    return f"{x:.{nd}f}{suf}" if x is not None else "—"


def main():
    d = sys.argv[1] if len(sys.argv) > 1 else "/opt/valeur-verte/dumps"
    fichiers = sorted(glob.glob(os.path.join(d, "pooling_m_*.csv")))
    if not fichiers:
        print(f"Aucun pooling_m_*.csv dans {d}")
        sys.exit(1)
    res = {}
    for f in fichiers:
        N = int(re.search(r"pooling_m_(\d+)\.csv", f).group(1))
        res[N] = analyse_millesime(N, charger(f))
    millesimes = sorted(res)

    print("=" * 78)
    print("  ANALYSE POOLING MULTI-MILLESIMES  (pooling hierarchique + fenetre W2)")
    print("=" * 78)

    print("\n### 1. SOLIDITE DU B (couverture / precision sous pooling)")
    print(f"{'Millesime':>10} {'mailles':>8} {'B publie':>9} {'%B':>6} "
          f"{'depts B':>8} {'eff.B med':>9} {'SE.B med':>8} {'SE.C med':>8}")
    for N in millesimes:
        r = res[N]
        print(f"{N:>10} {r['total']:>8} {r['b_pub']:>9} {fmt(r['b_pub_pct'],'%'):>6} "
              f"{r['depts_b']:>8} {fmt(r['eff_b_med'],nd=0):>9} "
              f"{fmt(r['se_b_med'],nd=3):>8} {fmt(r['se_c_med'],nd=3):>8}")

    print("\n### 2. ECART C/B — gain median sur mailles B/C/E fiables (eff>=20)")
    print(f"{'Millesime':>10} {'n mailles':>9} {'E->C':>8} {'C->B':>8} {'E->B':>8} "
          f"{'ratio EC/CB':>12}")
    for N in millesimes:
        r = res[N]
        print(f"{N:>10} {r['n_bce']:>9} {fmt(r['med_ec'],'%'):>8} "
              f"{fmt(r['med_cb'],'%'):>8} {fmt(r['med_eb'],'%'):>8} "
              f"{fmt(r['ratio_ec_cb'],'x',1):>12}")

    print("\n### 2bis. UNIVERSALITE par departement (depts avec >=20 mailles B/C/E)")
    print(f"{'Millesime':>10} {'depts':>7} {'C->B < E->C':>13} {'C->B > 0':>10}")
    for N in millesimes:
        r = res[N]
        col1 = f"{r['cb_inf_ec']}/{r['depts_ok']}"
        col2 = f"{r['cb_pos']}/{r['depts_ok']}"
        print(f"{N:>10} {r['depts_ok']:>7} {col1:>13} {col2:>10}")

    print("\n### 3. DERIVE INTER-ANNEE sur PANEL STABLE (mailles B/C/E fiables TOUS millesimes)")
    communs = None
    for N in millesimes:
        s = set(res[N]["bce_index"])
        communs = s if communs is None else (communs & s)
    print(f"Panel commun : {len(communs)} mailles presentes et B/C/E fiables sur "
          f"{millesimes[0]}..{millesimes[-1]}")
    print(f"{'Millesime':>10} {'E->C med':>9} {'C->B med':>9} {'E->B med':>9} {'ratio':>7}")
    serie_ec, serie_cb = [], []
    for N in millesimes:
        idx = res[N]["bce_index"]
        ec = med([gain(idx[c]["coef"], "E", "C") for c in communs])
        cb = med([gain(idx[c]["coef"], "C", "B") for c in communs])
        eb = med([gain(idx[c]["coef"], "E", "B") for c in communs])
        serie_ec.append(ec)
        serie_cb.append(cb)
        ratio = (ec / cb) if (ec and cb) else None
        print(f"{N:>10} {fmt(ec,'%'):>9} {fmt(cb,'%'):>9} {fmt(eb,'%'):>9} {fmt(ratio,'x'):>7}")
    if len(serie_ec) >= 2 and serie_ec[0] and serie_ec[-1]:
        dr = (serie_ec[-1] / serie_ec[0] - 1) * 100
        print(f"\nDerive E->C {millesimes[0]}->{millesimes[-1]} : {dr:+.1f}% "
              f"({fmt(serie_ec[0],'%')} -> {fmt(serie_ec[-1],'%')})")
    if len(serie_cb) >= 2 and serie_cb[0] and serie_cb[-1]:
        dr = (serie_cb[-1] / serie_cb[0] - 1) * 100
        print(f"Derive C->B {millesimes[0]}->{millesimes[-1]} : {dr:+.1f}% "
              f"({fmt(serie_cb[0],'%')} -> {fmt(serie_cb[-1],'%')})")

    print("\n(Interpretation : le message publiable « le gros du gain est atteint a C, ")
    print(" le dernier cran vers B rapporte bien moins, partout » se verifie si ratio EC/CB")
    print(" reste >1 et C->B<E->C sur ~tous les depts, sur tous les millesimes.)")


if __name__ == "__main__":
    main()

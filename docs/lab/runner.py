"""Runner d'expérience léger : calcule les coefficients des communes-test sur une
base locale et imprime le comparatif. Outil d'expérience, pas du code produit.

Usage : DSN et millésime en constantes ; la config vient de l'environnement
(VV_MOTEUR_*), donc chaque variante = mêmes communes, config différente.
"""

from __future__ import annotations

import math
import os

import psycopg

from valeur_verte_data.batch import (
    _classes_publiables,
    _meme_departement,
    compute_maille,
)
from valeur_verte_data.config import resolve_config
from valeur_verte_data.db.pool_repository import load_pool
from valeur_verte_data.degraded import decide_mode
from valeur_verte_data.limitrophes import limitrophes
from valeur_verte_data.radius import adaptive_selection
from valeur_verte_data.regression import _eligible

DSN = os.environ.get("DATABASE_URL", "postgresql://valeur_verte:valeur_verte@localhost:5432/vv_lab")
MILLESIME = int(os.environ.get("EXP_MILLESIME", "2024"))

COMMUNES = [
    ("31555", "Toulouse — urbain dense"),
    ("31149", "Colomiers — périurbain"),
    ("31135", "Cazères — rural 31"),
    ("09122", "Foix — rural 09"),
    ("09306", "Tarascon-sur-Ariège — petit n"),
]


def centroid(conn: psycopg.Connection, code_insee: str) -> tuple[float, float]:
    row = conn.execute(
        "SELECT avg(latitude), avg(longitude) FROM data.vente "
        "WHERE code_insee = %s AND type_local = 'Maison' AND latitude IS NOT NULL",
        [code_insee],
    ).fetchone()
    assert row is not None and row[0] is not None
    return float(row[0]), float(row[1])


def pct(coef: float) -> float:
    """Coefficient log → effet en % de prix."""
    return (math.exp(coef) - 1.0) * 100.0


def gain_ec(coef_dpe: dict[str, float]) -> str:
    if "C" in coef_dpe and "E" in coef_dpe:
        return f"{pct(coef_dpe['C'] - coef_dpe['E']):+.1f}%"
    return "n/a"


def main() -> None:
    config = resolve_config(os.environ)
    with psycopg.connect(DSN) as conn:
        pools = {
            dep: load_pool(
                conn, dep, MILLESIME, limitrophes=limitrophes(dep), type_local=config.segment
            )
            for dep in ("31", "09")
        }
        print(
            f"millésime {MILLESIME} | restreindre_bati_ancien={config.restreindre_bati_ancien} "
            f"| ecretage={config.ecretage} (k={config.ecretage_k}, "
            f"seuil_n={config.ecretage_seuil_n})"
        )
        print(f"Pool 31 (+limitrophes) : {len(pools['31'])} maisons | Pool 09 : {len(pools['09'])}")
        print()
        entete = (
            f"{'commune':<32}{'n':>6}{'mode':>6}{'ray':>5}{'r2':>7}"
            f"{'err%':>7}{'m2bati':>8}{'m2terr':>8}{'gainE→C':>10}"
        )
        print(entete)
        print("-" * len(entete))
        for code, label in COMMUNES:
            lat, lon = centroid(conn, code)
            pool = pools[code[:2]]
            maille = compute_maille(code, lat, lon, MILLESIME, pool, config=config)
            if maille is None:
                # Diagnostic du refus : ré-exécute les décisions clés du calcul.
                selection = adaptive_selection(
                    lat, lon, pool, min_sample=config.min_sample, radii=config.radii_km
                )
                restr = config.restreindre_bati_ancien
                n_local = len(_eligible(selection.sample, restreindre_bati_ancien=restr))
                n_dept = len(
                    _eligible(_meme_departement(code, pool), restreindre_bati_ancien=restr)
                )
                decision = decide_mode(n_local, n_dept, seuil=config.min_local_sample)
                sample = (
                    selection.sample
                    if decision.mode.value == "local"
                    else _meme_departement(code, pool)
                )
                pub = _classes_publiables(
                    sample, config.min_class_sample, restreindre_bati_ancien=restr
                )
                raison = (
                    f"n_local_élig={n_local}  n_dept_élig={n_dept}  "
                    f"mode={decision.mode.value}  classes≥{config.min_class_sample}={sorted(pub)}"
                )
                print(f"{label:<32}  REFUS — {raison}")
                continue
            mode = maille.mode.value[:5]
            ray = "—" if maille.rayon_retenu_km is None else str(maille.rayon_retenu_km)
            err = "—" if maille.erreur_mediane is None else f"{maille.erreur_mediane:.1f}"
            print(
                f"{label:<32}{maille.n_transactions:>6}{mode:>6}{ray:>5}"
                f"{maille.r2:>7.3f}{err:>7}{maille.coef_m2_bati:>8.2f}"
                f"{maille.coef_m2_terrain:>8.2f}{gain_ec(maille.coef_dpe):>10}"
            )


if __name__ == "__main__":
    main()

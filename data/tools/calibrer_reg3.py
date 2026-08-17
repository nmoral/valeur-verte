"""Régénère le JSON des cas de référence REG-3 (#56) depuis une base recalculée.

One-shot, **hors CI** : à jouer contre une base au schéma courant chargée du jeu
figé (`tests/fixtures/reg3/reg3_prod_subset.dump`, voir son README) puis calculée
par ``compute --departement 31 --millesime 2025``. Lit les 13 communes de
référence, **calibre** chaque maille (fourchette baseline ± tolérance), écrit
``cas_reference.json``, et vérifie la couverture des quatre profils.

Ne pas lancer pour « faire vert » : un chiffre qui bouge se comprend d'abord
(régression réelle vs évolution volontaire du moteur), la régénération n'est
légitime qu'après cette décision.

Usage : ``DATABASE_URL=postgresql://…/vv_verif python tools/calibrer_reg3.py``
"""

from __future__ import annotations

import json
import os
from pathlib import Path
from typing import Any

import psycopg

from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.degraded import CoverageMode, PalierFiabilite
from valeur_verte_data.reg3 import (
    Profil,
    calibrer_cas,
    charger_cas_reference,
    profils_manquants,
    serialiser_cas,
)

_TOLERANCE = 0.25
_MILLESIME = 2025
_SORTIE = Path(__file__).resolve().parents[1] / "tests" / "fixtures" / "reg3" / "cas_reference.json"

# Sélection validée (département 31, N=2025) — code_insee → profil de référence.
_COMMUNES: tuple[tuple[str, Profil], ...] = (
    ("31555", Profil.SOLIDE),  # Toulouse
    ("31557", Profil.SOLIDE),  # Tournefeuille
    ("31044", Profil.SOLIDE),  # Balma
    ("31163", Profil.CORRECTE),  # Drémil-Lafage
    ("31572", Profil.CORRECTE),  # Venerque
    ("31358", Profil.CORRECTE),  # Montastruc-la-Conseillère
    ("31585", Profil.FRAGILE),  # Villeneuve-de-Rivière
    ("31021", Profil.FRAGILE),  # Aspret-Sarrat
    ("31247", Profil.FRAGILE),  # Labarthe-Rivière
    ("31011", Profil.REPLI_DEPARTEMENTAL),  # Arbas
    ("31059", Profil.REPLI_DEPARTEMENTAL),  # Belbèze-en-Comminges
    ("31069", Profil.REPLI_EPCI),  # Blagnac
    ("31150", Profil.REPLI_EPCI),  # Cornebarrieu
)

_REQUETE = (
    "SELECT mode, palier, rayon_retenu_km, n_transactions, r2, erreur_mediane, "
    "coef_m2_bati, coef_m2_terrain, coef_dpe, alerte "
    "FROM data.coefficient_maille_historique WHERE code_insee = %s AND millesime = %s"
)


def _maille(conn: psycopg.Connection[Any], code_insee: str) -> MailleCoefficient:
    """Reconstruit la maille recalculée d'une commune depuis l'historique."""
    ligne = conn.execute(_REQUETE, (code_insee, _MILLESIME)).fetchone()
    if ligne is None:
        raise SystemExit(f"{code_insee} : aucune maille au millésime {_MILLESIME}")
    mode, palier, rayon, n, r2, erreur, bati, terrain, coef_dpe, alerte = ligne
    return MailleCoefficient(
        code_insee=code_insee,
        millesime=_MILLESIME,
        mode=CoverageMode(mode),
        palier=PalierFiabilite(palier),
        rayon_retenu_km=rayon,
        n_transactions=n,
        r2=float(r2),
        erreur_mediane=float(erreur) if erreur is not None else None,
        coef_m2_bati=float(bati),
        coef_m2_terrain=float(terrain),
        coef_dpe={classe: float(effet) for classe, effet in coef_dpe.items()},
        ic_dpe={},
        alerte=alerte,
    )


def main() -> None:
    with psycopg.connect(os.environ["DATABASE_URL"]) as conn:
        cas = [
            calibrer_cas(profil, _maille(conn, code_insee), tolerance=_TOLERANCE)
            for code_insee, profil in _COMMUNES
        ]
    corps = json.dumps(
        [serialiser_cas(reference) for reference in cas], ensure_ascii=False, indent=2
    )
    _SORTIE.write_text(corps + "\n", encoding="utf-8")

    with _SORTIE.open(encoding="utf-8") as fichier:
        recharges = charger_cas_reference(fichier)
    manquants = profils_manquants(recharges)
    if manquants:
        absents = sorted(profil.value for profil in manquants)
        raise SystemExit(f"couverture incomplète, profils manquants : {absents}")
    print(f"{len(recharges)} cas écrits dans {_SORTIE} — couverture des 4 profils requis OK")


if __name__ == "__main__":
    main()

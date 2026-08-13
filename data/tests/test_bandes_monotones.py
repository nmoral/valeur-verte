"""Tests de l'orchestration bandes → fit → anti-inversion (#169).

`bandes_monotones` enchaîne : bandes par effectif → ajustement → fusion des
inversions → ré-ajustement, jusqu'à monotonie. Renvoie le mapping classe →
représentant à appliquer, ou ``None`` si moins de deux bandes.

Ventes synthétiques : ``prix = prix_m2 × bâti`` → les coefficients DPE valent
exactement ``log(prix_m2_classe / prix_m2_référence)`` (fit à résidu nul), ce qui
rend l'ordre — et donc les inversions — déterministe.
"""

from __future__ import annotations

from dataclasses import replace

from valeur_verte_data.bandes import bandes_monotones
from valeur_verte_data.config import DEFAULT_CONFIG
from valeur_verte_data.dvf import Mutation

# Fit isolé : pas d'indice local ni d'effets fixes commune (bruit), pas d'écrêtage,
# seuil de classe bas pour des échantillons compacts.
CFG = replace(
    DEFAULT_CONFIG,
    indice_local=False,
    effets_fixes_commune=False,
    ecretage="off",
    min_class_sample=5,
)
SIGMA = 1000.0  # pondération quasi uniforme
LAT, LON = 48.11, 5.14


def _maisons(dpe: str, prix_m2: float, n: int, depart: int) -> list[Mutation]:
    """n maisons de classe ``dpe`` au prix/m² donné (prix = prix_m2 × bâti)."""
    ventes: list[Mutation] = []
    for i in range(n):
        bati = 80.0 + (i % 5) * 10.0
        terrain = 200.0 + (i % 4) * 50.0
        ventes.append(
            Mutation(
                id_mutation=f"{dpe}{depart + i}",
                date_mutation=None,
                millesime=2024,
                code_insee="52001",
                code_postal=None,
                nom_commune=None,
                type_local="Maison",
                nombre_pieces_principales=None,
                prix=prix_m2 * bati,
                surface_bati=bati,
                surface_terrain=terrain,
                prix_m2=None,
                adresse_numero=None,
                adresse_suffixe=None,
                adresse_nom_voie=None,
                longitude=LON + i * 0.001,
                latitude=LAT + i * 0.001,
                etiquette_dpe=dpe,
                periode_construction="1948-1974",
            )
        )
    return ventes


def test_monotone_bien_fourni_aucune_fusion() -> None:
    """Classes bien fournies, prix décroissants avec le DPE : bandes = classes pleines."""
    sample = [
        *_maisons("C", 2600, 8, 0),
        *_maisons("D", 2400, 8, 100),
        *_maisons("E", 2200, 8, 200),
    ]
    assert bandes_monotones(sample, LAT, LON, SIGMA, CFG) == {"C": "C", "D": "D", "E": "E"}


def test_inversion_ef_fusionnee() -> None:
    """F plus chère que E (coef E < coef F) : {E,F} fusionnent, le reste intact."""
    sample = [
        *_maisons("C", 2600, 8, 0),
        *_maisons("D", 2400, 8, 100),
        *_maisons("E", 2000, 8, 200),
        *_maisons("F", 2200, 8, 300),
    ]
    assert bandes_monotones(sample, LAT, LON, SIGMA, CFG) == {
        "C": "C",
        "D": "D",
        "E": "E",
        "F": "E",
    }


def test_moins_de_deux_bandes_renvoie_none() -> None:
    """Une seule classe atteint le seuil : aucune bande exploitable → None."""
    sample = [
        *_maisons("D", 2400, 8, 0),
        *_maisons("E", 2200, 3, 100),
    ]
    assert bandes_monotones(sample, LAT, LON, SIGMA, CFG) is None

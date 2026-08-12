"""Tests de l'écrêtage de l'échantillon au calcul (S32)."""

from __future__ import annotations

import math

from valeur_verte_data.dvf import Mutation
from valeur_verte_data.trimming import ecreter_residu


def _m_geo(bati: float, prix: float, ident: int) -> Mutation:
    """Mutation géolocalisée, ancienne, classe D — éligible à la régression."""
    return Mutation(
        id_mutation=f"g{ident}",
        date_mutation=None,
        millesime=2024,
        code_insee="31555",
        code_postal=None,
        nom_commune=None,
        type_local="Maison",
        nombre_pieces_principales=None,
        prix=float(prix),
        surface_bati=float(bati),
        surface_terrain=300.0,
        prix_m2=None,
        adresse_numero=None,
        adresse_suffixe=None,
        adresse_nom_voie=None,
        longitude=1.44,
        latitude=43.6,
        etiquette_dpe="D",
        periode_construction="1948-1974",
    )


def test_residu_retire_le_bien_aberrant() -> None:
    """V2 : un prix incohérent vu les caractéristiques (résidu énorme) est retiré."""
    conformes = [
        _m_geo(80 + i % 40, round(3000 * (80 + i % 40) * math.exp(0.1 if i % 2 == 0 else -0.1)), i)
        for i in range(120)
    ]
    aberrant = _m_geo(100, 3000 * 100 * 20, 999)  # ~20× le prix attendu
    sample = [*conformes, aberrant]

    result = ecreter_residu(sample, 43.6, 1.44, k=3.0, seuil_n=100)

    identifiants = {id(mutation) for mutation in result}
    assert id(aberrant) not in identifiants
    assert len(result) >= 118  # seul l'aberrant (au plus un ou deux) est retiré


def test_residu_sous_le_seuil_ne_retire_rien() -> None:
    sample = [_m_geo(80 + i, round(3000 * (80 + i)), i) for i in range(50)]
    assert ecreter_residu(sample, 43.6, 1.44, seuil_n=100) == sample

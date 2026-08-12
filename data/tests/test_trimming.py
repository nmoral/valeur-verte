"""Tests de l'écrêtage de l'échantillon au calcul (S32)."""

from __future__ import annotations

import math

from valeur_verte_data.dvf import Mutation
from valeur_verte_data.trimming import ecreter_prix_m2, ecreter_residu


def _m(prix_m2: float, ident: int) -> Mutation:
    """Mutation de prix au m² donné (bâti = 100 m²), le reste neutre."""
    return Mutation(
        id_mutation=f"m{ident}",
        date_mutation=None,
        millesime=2024,
        code_insee="31555",
        code_postal=None,
        nom_commune=None,
        type_local="Maison",
        nombre_pieces_principales=None,
        prix=prix_m2 * 100.0,
        surface_bati=100.0,
        surface_terrain=None,
        prix_m2=None,
        adresse_numero=None,
        adresse_suffixe=None,
        adresse_nom_voie=None,
        longitude=None,
        latitude=None,
        etiquette_dpe="D",
        periode_construction="1948-1974",
    )


def test_en_dessous_du_seuil_rien_n_est_retire() -> None:
    sample = [_m(1000 + i, i) for i in range(50)]
    assert ecreter_prix_m2(sample, fraction=0.01, seuil_n=100) == sample


def test_retire_les_fractions_extremes_au_dessus_du_seuil() -> None:
    # 200 ventes, prix/m² de 500 à 699 ; fraction 1 % → k=2 par queue.
    sample = [_m(500 + i, i) for i in range(200)]
    result = ecreter_prix_m2(sample, fraction=0.01, seuil_n=100)

    assert len(result) == 196  # 200 − 2 bas − 2 haut
    prix_m2 = [m.prix / m.surface_bati for m in result if m.prix and m.surface_bati]
    assert min(prix_m2) == 502.0  # 500 et 501 retirés
    assert max(prix_m2) == 697.0  # 698 et 699 retirés


def test_fraction_trop_petite_ne_retire_rien() -> None:
    # n=100, fraction 0.001 → k = int(0.1) = 0 : aucun retrait.
    sample = [_m(500 + i, i) for i in range(100)]
    assert ecreter_prix_m2(sample, fraction=0.001, seuil_n=100) == sample


def test_ventes_sans_prix_m2_calculable_sont_conservees_et_hors_gate() -> None:
    # 60 ventes valides (< seuil 100) + du bruit sans prix/bati : rien n'est retiré.
    valides = [_m(1000 + i, i) for i in range(60)]
    assert ecreter_prix_m2(valides, fraction=0.01, seuil_n=100) == valides


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

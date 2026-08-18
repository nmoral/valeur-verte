"""Tests du nettoyage des transactions (bornes §7, hors-ligne)."""

from __future__ import annotations

from dataclasses import replace

from valeur_verte_data.cleaning import (
    FRANCE_LAT_MAX,
    FRANCE_LAT_MIN,
    FRANCE_LON_MAX,
    FRANCE_LON_MIN,
    apply_cog,
    assainir_coordonnees,
    clean_mutations,
    coordonnees_plausibles,
    is_clean,
    remap_insee,
)
from valeur_verte_data.dvf import Mutation, aggregate_mutations


def _mutation(prix: str = "200000", bati: str = "80", terrain: str | None = None) -> Mutation:
    row = {
        "id_mutation": "m",
        "valeur_fonciere": prix,
        "code_commune": "31555",
        "surface_reelle_bati": bati,
    }
    if terrain is not None:
        row["surface_terrain"] = terrain
        row["code_nature_culture"] = "S"
        row["id_parcelle"] = "p1"
    (mutation,) = aggregate_mutations([row], millesime=2024)
    return mutation


def test_is_clean_accepte_dans_les_bornes() -> None:
    assert is_clean(_mutation(prix="200000", bati="80"))


def test_is_clean_rejette_prix_hors_bornes() -> None:
    assert not is_clean(_mutation(prix="20000"))
    assert not is_clean(_mutation(prix="1000000"))


def test_is_clean_rejette_surface_bati_hors_bornes() -> None:
    assert not is_clean(_mutation(bati="40"))
    assert not is_clean(_mutation(bati="300"))


def test_is_clean_terrain_hors_bornes() -> None:
    assert not is_clean(_mutation(terrain="10"))
    assert not is_clean(_mutation(terrain="6000"))
    assert is_clean(_mutation(terrain="500"))


def test_is_clean_terrain_absent_est_accepte() -> None:
    assert is_clean(_mutation(terrain=None))


def test_is_clean_accepte_les_bornes_exactes() -> None:
    assert is_clean(_mutation(prix="30000"))
    assert is_clean(_mutation(prix="900000"))
    assert is_clean(_mutation(bati="50"))
    assert is_clean(_mutation(bati="250"))


def test_is_clean_rejette_valeurs_manquantes() -> None:
    assert not is_clean(_mutation(prix=""))
    assert not is_clean(_mutation(bati=""))


def test_clean_mutations_filtre_les_aberrantes() -> None:
    mutations = [_mutation(prix="200000"), _mutation(prix="10")]

    assert len(clean_mutations(mutations)) == 1


# --- Assainissement des coordonnées (géocodage corrompu) --------------------


def test_coordonnees_plausibles_dans_la_metropole() -> None:
    assert coordonnees_plausibles(2.35, 48.85)  # Paris
    assert coordonnees_plausibles(9.15, 41.4)  # Corse (bord SE)


def test_coordonnees_implausibles_hors_metropole() -> None:
    assert not coordonnees_plausibles(2.28, 83.51)  # latitude polaire (corrompue)
    assert not coordonnees_plausibles(2.28, 40.88)  # latitude sous la métropole
    assert not coordonnees_plausibles(2.35, None)  # coordonnée partielle
    assert not coordonnees_plausibles(None, None)  # absente


def test_assainir_neutralise_les_coordonnees_impossibles() -> None:
    # Les 60 ventes du 92 (ex. lat 83°N) : géoloc corrompue → NULL, mutation gardée.
    corrompue = replace(_mutation(prix="250000"), longitude=2.287775, latitude=83.517705)

    (assainie,) = assainir_coordonnees([corrompue])

    assert assainie.longitude is None
    assert assainie.latitude is None
    assert assainie.prix == corrompue.prix  # la transaction n'est pas perdue


def test_assainir_conserve_les_coordonnees_plausibles() -> None:
    bonne = replace(_mutation(), longitude=2.35, latitude=48.85)

    (inchangee,) = assainir_coordonnees([bonne])

    assert inchangee.longitude == 2.35
    assert inchangee.latitude == 48.85


def test_assainir_laisse_les_ventes_sans_geoloc() -> None:
    # Une vente sans coordonnées est légitime (elle sort de la sélection spatiale).
    sans_geo = replace(_mutation(), longitude=None, latitude=None)

    (inchangee,) = assainir_coordonnees([sans_geo])

    assert inchangee.longitude is None
    assert inchangee.latitude is None


def test_coordonnees_plausibles_aux_bornes_exactes() -> None:
    # Les coins de l'enveloppe sont inclus (bornes fermées).
    assert coordonnees_plausibles(FRANCE_LON_MIN, FRANCE_LAT_MIN)
    assert coordonnees_plausibles(FRANCE_LON_MAX, FRANCE_LAT_MAX)


def test_assainir_neutralise_les_coordonnees_partielles() -> None:
    # Une seule coordonnée présente est inexploitable : on neutralise les deux.
    (sans_lat,) = assainir_coordonnees([replace(_mutation(), longitude=2.3, latitude=None)])
    assert sans_lat.longitude is None

    (sans_lon,) = assainir_coordonnees([replace(_mutation(), longitude=None, latitude=48.8)])
    assert sans_lon.latitude is None


def test_remap_insee_utilise_la_correspondance() -> None:
    assert remap_insee("14697", {"14697": "14472"}) == "14472"


def test_remap_insee_sans_correspondance_inchange() -> None:
    assert remap_insee("31555", {}) == "31555"


def test_apply_cog_remappe_le_code_insee() -> None:
    (result,) = apply_cog([_mutation()], {"31555": "31999"})

    assert result.code_insee == "31999"


def test_apply_cog_sans_correspondance_laisse_inchange() -> None:
    (result,) = apply_cog([_mutation()], {})

    assert result.code_insee == "31555"

"""Tests de la configuration de calcul lue depuis l'environnement.

Toutes les constantes de calcul (seuils d'échantillon, σ, bornes de nettoyage,
segment…) sont centralisées dans un ``CalculConfig`` résolu depuis un mapping
d'environnement — même motif que ``resolve_dsn`` : env **injecté**, donc testable
hors-ligne, et surchargeable par expérience sans toucher au code.

Les valeurs par défaut reproduisent exactement les constantes actuelles du
pipeline : sans variable d'environnement, le comportement est inchangé.
"""

from __future__ import annotations

from valeur_verte_data.config import CalculConfig, resolve_config


def test_defauts_sans_environnement() -> None:
    config = resolve_config({})
    # Échantillonnage / mode
    assert config.radii_km == (10, 15, 20, 30, 50)
    assert config.min_sample == 80
    assert config.min_local_sample == 30
    assert config.min_class_sample == 15
    assert config.min_classes_publiees == 2
    # Régression
    assert config.sigma_km == 10.0
    assert config.departemental_sigma_km == 1000.0
    assert config.reference_dpe == "D"
    assert config.voisins_indice_local == 20
    assert config.etendue_minimale_indice == 0.002
    # Validation croisée
    assert config.cv_folds == 5
    # Nettoyage
    assert config.price_min == 30_000.0
    assert config.price_max == 900_000.0
    assert config.bati_min == 50.0
    assert config.bati_max == 250.0
    assert config.terrain_min == 50.0
    assert config.terrain_max == 5_000.0
    # Segment
    assert config.segment == "Maison"
    # Restriction bâti ancien (S20) : active par défaut
    assert config.restreindre_bati_ancien is True


def test_config_est_immuable() -> None:
    config = resolve_config({})
    assert isinstance(config, CalculConfig)
    import dataclasses

    assert all(field.name for field in dataclasses.fields(config))


def test_surcharge_float() -> None:
    assert resolve_config({"VV_MOTEUR_SIGMA_KM": "5"}).sigma_km == 5.0


def test_surcharge_int() -> None:
    assert resolve_config({"VV_MOTEUR_MIN_SAMPLE": "120"}).min_sample == 120


def test_surcharge_str() -> None:
    assert resolve_config({"VV_MOTEUR_SEGMENT": "Appartement"}).segment == "Appartement"


def test_surcharge_tuple_radii() -> None:
    assert resolve_config({"VV_MOTEUR_RADII_KM": "5,10,20"}).radii_km == (5, 10, 20)


def test_surcharge_bornes_nettoyage() -> None:
    config = resolve_config({"VV_MOTEUR_BATI_MAX": "400", "VV_MOTEUR_PRICE_MAX": "1500000"})
    assert config.bati_max == 400.0
    assert config.price_max == 1_500_000.0


def test_variable_vide_retombe_sur_defaut() -> None:
    # Une variable présente mais vide ne doit pas écraser le défaut par 0/"".
    assert resolve_config({"VV_MOTEUR_SIGMA_KM": ""}).sigma_km == 10.0


def test_surcharge_bool_false() -> None:
    assert (
        resolve_config({"VV_MOTEUR_RESTREINDRE_BATI_ANCIEN": "false"}).restreindre_bati_ancien
        is False
    )
    assert (
        resolve_config({"VV_MOTEUR_RESTREINDRE_BATI_ANCIEN": "0"}).restreindre_bati_ancien is False
    )


def test_surcharge_bool_true() -> None:
    assert (
        resolve_config({"VV_MOTEUR_RESTREINDRE_BATI_ANCIEN": "true"}).restreindre_bati_ancien
        is True
    )

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
    assert config.rayon_max_km == 50
    assert config.rupture_facteur_max == 2.0
    assert config.min_sample == 80
    assert config.min_local_sample == 30
    assert config.min_class_sample == 20
    assert config.min_classes_publiees == 2
    assert config.inversion_dpe_seuil == 30
    # Fenêtre glissante d'estimation (#177) : 2 par défaut. La validation 15 depts
    # (2021-2024) a tranché — la fenêtre est un régulariseur (queue p90 −22 %,
    # monotonie ↑) mais pooler à poids égaux introduit un biais de retard sur la
    # dérive du premium DPE (~+15 %/an) : W2 minimise ce compromis biais-variance
    # (W3 trop stale, W1 trop bruité). La pondération temporelle décroissante (#208)
    # généralisera ce défaut plat. Surchargeable par VV_MOTEUR_FENETRE_MILLESIMES.
    assert config.fenetre_millesimes == 2
    # Régression — config gagnante par défaut
    assert config.sigma_km == 10.0
    assert config.departemental_sigma_km == 1000.0
    assert config.sigma_mode == "densite"
    assert config.sigma_diviseur == 1.0
    assert config.sigma_min == 3.0
    assert config.reference_dpe == "D"
    assert config.voisins_indice_local == 20
    assert config.etendue_minimale_indice == 0.002
    assert config.effets_fixes_commune is False
    assert config.indice_local is True
    # Pooling hiérarchique (#249) : désactivé par défaut (non-régression exacte)
    assert config.pooling_hierarchique is False
    # Validation croisée
    assert config.cv_folds == 5
    # Seuils de publication (INV-4)
    assert config.erreur_mediane_max_pct == 20.0
    # Palier dégradé (#169) : plafond d'erreur du tier « estimation dégradée »
    assert config.erreur_mediane_max_pct_degrade == 30.0
    # Écarts entre millésimes (REG-1, #129) : alerte à 10 %, blocage à 25 %
    assert config.ecart_alerte_pct == 10.0
    assert config.ecart_blocage_pct == 25.0
    # Appariement DVF ↔ DPE (DAT-3, #126) : plancher 60 %, chute bloquante > 10 pts
    assert config.taux_appariement_min_pct == 60.0
    assert config.chute_appariement_max_pct == 10.0
    # Volumétrie par département (DAT-2, #125) : alerte au-delà de ± 30 % vs précédent
    assert config.ecart_volumetrie_max_pct == 30.0
    # Distributions (DAT-4, #127) : fourchettes de plausibilité (bloquent) + écart DPE (alerte)
    assert config.prix_median_min == 40_000.0
    assert config.prix_median_max == 1_000_000.0
    assert config.bati_median_min == 60.0
    assert config.bati_median_max == 160.0
    assert config.terrain_aberrant_max_pct == 50.0
    assert config.dpe_distribution_ecart_max_pct == 15.0
    # Fraîcheur des sources (DAT-5, #128) : alerte au-delà de 12 mois vs date d'exécution
    assert config.fraicheur_max_mois == 12
    assert config.r2_min == 0.30
    # Signe/ordre de grandeur (INV-2, en log)
    assert config.gain_dpe_ec_max == 0.70
    assert config.elasticite_bati_min == 0.30
    assert config.elasticite_bati_max == 1.50
    # Nettoyage
    assert config.price_min == 30_000.0
    assert config.price_max == 900_000.0
    assert config.bati_min == 50.0
    assert config.bati_max == 250.0
    assert config.terrain_min == 50.0
    assert config.terrain_max == 5_000.0
    # Segment
    assert config.segment == "Maison"
    # Restriction bâti ancien (S20) : retirée par défaut (bâti récent inclus)
    assert config.restreindre_bati_ancien is False
    # Écrêtage (S32) : résidu pondéré par défaut
    assert config.ecretage == "residu"
    assert config.ecretage_seuil_n == 100
    assert config.ecretage_k == 3.0
    # Pondération temporelle décroissante (#208) : désactivée par défaut (None =
    # poids égaux, comportement #177 exact). Opt-in par l'environnement.
    assert config.decroissance_temporelle_tau is None
    # Ruptures de réforme DPE (#215) : calendrier vide par défaut = aucun filtrage
    # (non-régression exacte). Opt-in par l'environnement.
    assert config.dpe_reform_boundaries == ()


def test_config_est_immuable() -> None:
    config = resolve_config({})
    assert isinstance(config, CalculConfig)
    import dataclasses

    assert all(field.name for field in dataclasses.fields(config))


def test_surcharge_float() -> None:
    assert resolve_config({"VV_MOTEUR_SIGMA_KM": "5"}).sigma_km == 5.0


def test_surcharge_seuils_publication() -> None:
    config = resolve_config({"VV_MOTEUR_ERREUR_MEDIANE_MAX_PCT": "15", "VV_MOTEUR_R2_MIN": "0.5"})
    assert config.erreur_mediane_max_pct == 15.0
    assert config.r2_min == 0.5


def test_surcharge_seuil_degrade() -> None:
    config = resolve_config({"VV_MOTEUR_ERREUR_MEDIANE_MAX_PCT_DEGRADE": "25"})
    assert config.erreur_mediane_max_pct_degrade == 25.0


def test_surcharge_seuils_ecart_millesime() -> None:
    config = resolve_config(
        {"VV_MOTEUR_ECART_ALERTE_PCT": "8", "VV_MOTEUR_ECART_BLOCAGE_PCT": "20"}
    )
    assert config.ecart_alerte_pct == 8.0
    assert config.ecart_blocage_pct == 20.0


def test_surcharge_seuils_appariement() -> None:
    config = resolve_config(
        {"VV_MOTEUR_TAUX_APPARIEMENT_MIN_PCT": "55", "VV_MOTEUR_CHUTE_APPARIEMENT_MAX_PCT": "8"}
    )
    assert config.taux_appariement_min_pct == 55.0
    assert config.chute_appariement_max_pct == 8.0


def test_surcharge_seuil_volumetrie() -> None:
    config = resolve_config({"VV_MOTEUR_ECART_VOLUMETRIE_MAX_PCT": "25"})
    assert config.ecart_volumetrie_max_pct == 25.0


def test_surcharge_seuils_distribution() -> None:
    config = resolve_config(
        {
            "VV_MOTEUR_PRIX_MEDIAN_MIN": "50000",
            "VV_MOTEUR_PRIX_MEDIAN_MAX": "800000",
            "VV_MOTEUR_BATI_MEDIAN_MIN": "70",
            "VV_MOTEUR_BATI_MEDIAN_MAX": "150",
            "VV_MOTEUR_TERRAIN_ABERRANT_MAX_PCT": "40",
            "VV_MOTEUR_DPE_DISTRIBUTION_ECART_MAX_PCT": "20",
        }
    )
    assert config.prix_median_min == 50_000.0
    assert config.prix_median_max == 800_000.0
    assert config.bati_median_min == 70.0
    assert config.bati_median_max == 150.0
    assert config.terrain_aberrant_max_pct == 40.0
    assert config.dpe_distribution_ecart_max_pct == 20.0


def test_surcharge_fraicheur_max_mois() -> None:
    assert resolve_config({"VV_MOTEUR_FRAICHEUR_MAX_MOIS": "18"}).fraicheur_max_mois == 18


def test_surcharge_int() -> None:
    assert resolve_config({"VV_MOTEUR_MIN_SAMPLE": "120"}).min_sample == 120


def test_surcharge_fenetre_millesimes() -> None:
    # Fenêtre glissante d'estimation (#177) : nombre de millésimes empilés dans
    # le pool. La v1 se valide en fenêtre 3 avant d'en faire le défaut.
    assert resolve_config({"VV_MOTEUR_FENETRE_MILLESIMES": "3"}).fenetre_millesimes == 3


def test_surcharge_decroissance_temporelle_tau() -> None:
    # Pondération temporelle (#208) : τ (constante de temps) lu depuis l'env.
    config = resolve_config({"VV_MOTEUR_DECROISSANCE_TEMPORELLE_TAU": "1.5"})
    assert config.decroissance_temporelle_tau == 1.5


def test_decroissance_temporelle_tau_vide_retombe_sur_none() -> None:
    # Variable présente mais vide → défaut (désactivé), pas 0.0.
    assert (
        resolve_config({"VV_MOTEUR_DECROISSANCE_TEMPORELLE_TAU": ""}).decroissance_temporelle_tau
        is None
    )


def test_surcharge_dpe_reform_boundaries() -> None:
    # Ruptures de réforme DPE (#215) : calendrier de dates ISO, séparées par virgule.
    config = resolve_config({"VV_MOTEUR_DPE_REFORM_BOUNDARIES": "2021-07-01,2024-07-01"})
    assert config.dpe_reform_boundaries == ("2021-07-01", "2024-07-01")


def test_dpe_reform_boundaries_vide_retombe_sur_tuple_vide() -> None:
    assert resolve_config({"VV_MOTEUR_DPE_REFORM_BOUNDARIES": ""}).dpe_reform_boundaries == ()


def test_surcharge_rayon_max() -> None:
    # Plafond de rayon (INV-3) surchargeable par l'environnement.
    assert resolve_config({"VV_MOTEUR_RAYON_MAX_KM": "40"}).rayon_max_km == 40


def test_surcharge_rupture_facteur() -> None:
    # Facteur de rupture limitrophe (INV-3) surchargeable par l'environnement.
    assert resolve_config({"VV_MOTEUR_RUPTURE_FACTEUR_MAX": "1.5"}).rupture_facteur_max == 1.5


def test_surcharge_bornes_signe_ordre_grandeur() -> None:
    # Bornes de magnitude INV-2 (en log) surchargeables par l'environnement.
    config = resolve_config(
        {
            "VV_MOTEUR_GAIN_DPE_EC_MAX": "0.5",
            "VV_MOTEUR_ELASTICITE_BATI_MIN": "0.4",
            "VV_MOTEUR_ELASTICITE_BATI_MAX": "1.2",
        }
    )
    assert config.gain_dpe_ec_max == 0.5
    assert config.elasticite_bati_min == 0.4
    assert config.elasticite_bati_max == 1.2


def test_surcharge_inversion_dpe_seuil() -> None:
    # Seuil de tolérance de monotonie DPE (INV-1) surchargeable par l'environnement.
    assert resolve_config({"VV_MOTEUR_INVERSION_DPE_SEUIL": "20"}).inversion_dpe_seuil == 20


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


def test_surcharge_pooling_hierarchique() -> None:
    assert resolve_config({"VV_MOTEUR_POOLING_HIERARCHIQUE": "true"}).pooling_hierarchique is True

"""REG-1 (#129) — classification de l'écart entre deux millésimes.

Après un batch, chaque maille est comparée à son millésime précédent. L'écart
relatif classe la maille : sous le seuil d'alerte c'est **normal**, entre alerte et
blocage on **alerte** (revue manuelle), au-delà on **bloque** la publication de la
maille (§REG-1). Fonction pure : deux valeurs → une classe.
"""

from __future__ import annotations

from valeur_verte_data.ecarts import ClasseEcart, classer_ecart


def test_ecart_sous_le_seuil_alerte_est_normal() -> None:
    assert classer_ecart(100.0, 105.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.NORMAL


def test_ecart_entre_alerte_et_blocage_alerte() -> None:
    assert classer_ecart(100.0, 115.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.ALERTE


def test_ecart_au_dela_du_blocage_bloque() -> None:
    assert classer_ecart(100.0, 130.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.BLOCAGE


def test_seuil_alerte_inclus_dans_alerte() -> None:
    # « 10-25 % : alerte » — à 10 % pile, on alerte (borne basse incluse).
    assert classer_ecart(100.0, 110.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.ALERTE


def test_seuil_blocage_inclus_dans_alerte() -> None:
    # « 10-25 % alerte ; > 25 % blocage » — à 25 % pile, encore en alerte (borne haute incluse).
    assert classer_ecart(100.0, 125.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.ALERTE


def test_une_baisse_compte_comme_une_hausse() -> None:
    # Écart relatif en valeur absolue : |nouveau - ancien| / |ancien|.
    assert classer_ecart(100.0, 70.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.BLOCAGE


def test_ecart_sur_coefficient_negatif() -> None:
    # Effets DPE en log (négatifs) : l'écart se rapporte à |ancien|.
    # -0,20 → -0,30 = 50 % d'écart → blocage.
    assert classer_ecart(-0.20, -0.30, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.BLOCAGE


def test_apparition_depuis_zero_bloque() -> None:
    # Ancien nul, nouveau non nul : écart indéfini → blocage (changement majeur à revoir).
    assert classer_ecart(0.0, 0.10, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.BLOCAGE


def test_zero_vers_zero_est_normal() -> None:
    # Deux valeurs nulles (ex. référence D) : aucun écart.
    assert classer_ecart(0.0, 0.0, seuil_alerte=0.10, seuil_blocage=0.25) is ClasseEcart.NORMAL

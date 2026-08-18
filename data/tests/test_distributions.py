"""DAT-4 (#127) — assertions de distribution (prix, surfaces, étiquettes DPE).

En fin d'ingestion, avant calcul, DAT-4 confronte les distributions **observées**
d'un département (mesurées sur `data.vente`, ce qui reste après nettoyage §7) à des
fourchettes de plausibilité et à l'ingestion précédente :

- **plausibilité** (bloque le calcul, comme DAT-1/DAT-3) : prix médian dans une
  fourchette, surface bâtie médiane ∈ [60 ; 160] m², part de terrain nul/aberrant
  sous un seuil ;
- **répartition DPE** (alerte seule, jamais un blocage) : un déplacement marqué vs
  le millésime précédent est **légitime** (réforme DPE 2026) mais doit être **rendu
  visible**.

Décision **pure** : les distributions observées (+ l'histogramme DPE précédent)
entrent, un verdict (bloque + motifs, alerte + motifs) sort.
"""

from __future__ import annotations

from valeur_verte_data.distributions import (
    Distribution,
    VerdictDistribution,
    evaluer_distribution,
)

_BORNES = {
    "prix_median_min": 40_000.0,
    "prix_median_max": 1_000_000.0,
    "bati_median_min": 60.0,
    "bati_median_max": 160.0,
    "terrain_aberrant_max": 0.50,
    "dpe_ecart_max": 0.15,
}


def _dist(
    *,
    prix_median: float | None = 200_000.0,
    surface_bati_median: float | None = 100.0,
    terrain_aberrant: int = 0,
    effectif: int = 1000,
    effectifs_dpe: dict[str, int] | None = None,
) -> Distribution:
    return Distribution(
        prix_median=prix_median,
        surface_bati_median=surface_bati_median,
        terrain_aberrant=terrain_aberrant,
        effectif=effectif,
        effectifs_dpe=effectifs_dpe if effectifs_dpe is not None else {"D": 500, "E": 500},
    )


def _ev(
    distribution: Distribution,
    precedent: dict[str, int] | None = None,
    *,
    departement: str = "31",
) -> VerdictDistribution:
    return evaluer_distribution(departement, distribution, precedent, **_BORNES)


# --- distribution plausible : ni blocage ni alerte ---


def test_distribution_plausible_ne_bloque_ni_n_alerte() -> None:
    verdict = _ev(_dist())
    assert isinstance(verdict, VerdictDistribution)
    assert verdict.bloque is False
    assert verdict.alerte is False
    assert verdict.motifs == ()
    assert verdict.alertes == ()


def test_verdict_porte_le_departement() -> None:
    assert _ev(_dist(), departement="09").departement == "09"


# --- plausibilité (BLOQUE) : prix médian ---


def test_prix_median_hors_fourchette_bloque() -> None:
    # Prix médian dérisoire (1 500 €) : symptôme d'une colonne prix cassée.
    verdict = _ev(_dist(prix_median=1_500.0))
    assert verdict.bloque is True
    assert any("prix médian" in m for m in verdict.motifs)


def test_prix_median_aux_bornes_est_ok() -> None:
    # Bornes incluses : 40 000 € et 1 000 000 € pile passent.
    assert _ev(_dist(prix_median=40_000.0)).bloque is False
    assert _ev(_dist(prix_median=1_000_000.0)).bloque is False


# --- plausibilité (BLOQUE) : surface bâtie médiane ∈ [60 ; 160] ---


def test_surface_bati_mediane_hors_fourchette_bloque() -> None:
    # 300 m² de médiane : bâti et terrain probablement intervertis (DAT-1 ne le voit
    # pas, les deux colonnes existent).
    verdict = _ev(_dist(surface_bati_median=300.0))
    assert verdict.bloque is True
    assert any("surface bâtie" in m for m in verdict.motifs)


def test_surface_bati_mediane_aux_bornes_est_ok() -> None:
    assert _ev(_dist(surface_bati_median=60.0)).bloque is False
    assert _ev(_dist(surface_bati_median=160.0)).bloque is False


# --- plausibilité (BLOQUE) : part de terrain nul/aberrant ---


def test_terrain_aberrant_au_dela_du_seuil_bloque() -> None:
    # 700/1000 = 70 % de terrain nul/aberrant, au-dessus du seuil 50 %.
    verdict = _ev(_dist(terrain_aberrant=700, effectif=1000))
    assert verdict.bloque is True
    assert any("terrain" in m for m in verdict.motifs)


def test_terrain_aberrant_au_seuil_est_ok() -> None:
    # 50 % pile reste sous le plafond (borne stricte : « au-delà » alerte).
    assert _ev(_dist(terrain_aberrant=500, effectif=1000)).bloque is False


# --- plausibilité : plusieurs motifs cumulables ---


def test_plusieurs_motifs_de_blocage_remontent() -> None:
    verdict = _ev(_dist(prix_median=1_500.0, surface_bati_median=300.0))
    assert verdict.bloque is True
    assert len(verdict.motifs) == 2


# --- médiane indéfinie (aucun prix/surface) : pas de motif fantôme ---


def test_mediane_absente_n_est_pas_un_motif() -> None:
    # Une médiane None (aucune valeur mesurable) ne peut pas être « hors fourchette ».
    verdict = _ev(_dist(prix_median=None, surface_bati_median=None))
    assert not any("médian" in m for m in verdict.motifs)


# --- répartition DPE (ALERTE seule) ---


def test_dpe_stable_ne_declenche_pas_d_alerte() -> None:
    courant = _dist(effectifs_dpe={"D": 500, "E": 500})
    assert _ev(courant, {"D": 480, "E": 520}).alerte is False


def test_dpe_deplacement_marque_alerte_sans_bloquer() -> None:
    # La classe D passe de 20 % à 60 % (réforme DPE) : +40 pts → alerte, pas blocage.
    courant = _dist(effectifs_dpe={"D": 600, "E": 400})
    verdict = _ev(courant, {"D": 200, "E": 800})
    assert verdict.alerte is True
    assert verdict.bloque is False
    assert any("DPE" in a and "D" in a for a in verdict.alertes)


def test_dpe_variation_au_seuil_est_ok() -> None:
    # +15 pts pile ne déclenche pas (borne stricte : « au-delà » seulement).
    courant = _dist(effectifs_dpe={"D": 650, "E": 350})
    assert _ev(courant, {"D": 500, "E": 500}).alerte is False


def test_premier_chargement_dpe_est_neutre() -> None:
    # Sans ingestion précédente, la cohérence DPE est inévaluable : pas d'alerte.
    assert _ev(_dist(), None).alerte is False


def test_dpe_ne_bloque_jamais_meme_tres_deplace() -> None:
    # Garde-fou : quelle que soit l'ampleur du déplacement DPE, DAT-4 n'en bloque
    # jamais le calcul (réforme légitime, décision humaine).
    courant = _dist(effectifs_dpe={"A": 1000})
    assert _ev(courant, {"G": 1000}).bloque is False

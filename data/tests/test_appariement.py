"""DAT-3 (#126) — porte sur le taux d'appariement DVF ↔ DPE.

L'appariement DVF ↔ DPE est la charnière du modèle : sans étiquette DPE rattachée
à une vente, aucun coefficient à estimer. Un appariement qui s'effondre ne lève
aucune erreur — il produit des coefficients sur un échantillon biaisé. DAT-3
oppose deux garde-fous, en **fin d'ingestion, par département** :

- **seuil plancher** : taux ≥ 60 % (critère de sortie de J1) ;
- **chute** : une baisse de **plus de 10 points** vs le millésime précédent bloque.

Échec → blocage du calcul. Cette logique d'évaluation est **pure** : le taux (brut,
via ``dpe.matching_rate``) et le taux précédent entrent, un verdict sort.
"""

from __future__ import annotations

from valeur_verte_data.appariement import (
    VerdictAppariement,
    evaluer_appariement,
    taux_depuis_comptes,
)

_SEUILS = {"seuil_min": 0.60, "chute_max": 0.10}


def _ev(taux: float, precedent: float | None, *, departement: str = "31") -> VerdictAppariement:
    return evaluer_appariement(departement, taux, precedent, **_SEUILS)


# --- taux_depuis_comptes : dérivation du taux depuis (appariées, mutations) ---


def test_taux_depuis_comptes_divise_appariees_par_mutations() -> None:
    assert taux_depuis_comptes(72, 100) == 0.72


def test_taux_depuis_comptes_sans_mutation_rend_none() -> None:
    # 0/0 : taux indéfini → None, pas une division par zéro ni un faux 0 %.
    assert taux_depuis_comptes(0, 0) is None


# --- evaluer_appariement ---


def test_taux_au_dessus_du_seuil_sans_chute_est_ok() -> None:
    verdict = _ev(0.75, 0.78)
    assert isinstance(verdict, VerdictAppariement)
    assert verdict.bloque is False
    assert verdict.motifs == ()


def test_verdict_porte_le_departement_et_le_taux() -> None:
    # Journalisation par département (DAT-3) : le verdict transporte les deux.
    verdict = _ev(0.55, None, departement="09")
    assert verdict.departement == "09"
    assert verdict.taux == 0.55


# --- seuil plancher (≥ 60 %) ---


def test_taux_sous_le_seuil_bloque() -> None:
    verdict = _ev(0.55, None)
    assert verdict.bloque is True
    assert any("seuil" in m for m in verdict.motifs)


def test_taux_au_seuil_est_ok() -> None:
    # « ≥ 60 % » : 60 % pile passe (borne incluse).
    assert _ev(0.60, None).bloque is False


# --- chute vs millésime précédent (> 10 points) ---


def test_chute_superieure_au_max_bloque() -> None:
    # 0,80 → 0,68 = −12 pts (le taux reste ≥ 60 %, seule la chute mord).
    verdict = _ev(0.68, 0.80)
    assert verdict.bloque is True
    # Le motif journalisé nomme l'ampleur exacte de la chute.
    assert any("chute" in m and "12 points" in m for m in verdict.motifs)


def test_chute_egale_au_max_est_ok() -> None:
    # « plus de 10 points » : −10 pts pile ne bloque pas (borne stricte).
    assert _ev(0.70, 0.80).bloque is False


def test_une_hausse_du_taux_est_ok() -> None:
    # La chute est une baisse ; une amélioration ne bloque jamais.
    assert _ev(0.85, 0.60).bloque is False


# --- premier millésime : pas de précédent ---


def test_premier_millesime_seul_le_seuil_s_applique() -> None:
    # Sans précédent, la chute est inévaluable : seul le plancher compte.
    assert _ev(0.65, None).bloque is False
    assert _ev(0.55, None).bloque is True


# --- accumulation : les deux motifs remontent ---


def test_seuil_et_chute_remontent_deux_motifs() -> None:
    # 0,75 → 0,50 : sous le plancher ET chute de 25 pts → deux motifs distincts.
    verdict = _ev(0.50, 0.75)
    assert verdict.bloque is True
    assert any("seuil" in m for m in verdict.motifs)
    assert any("chute" in m for m in verdict.motifs)
    assert len(verdict.motifs) == 2

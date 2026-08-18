"""DAT-2 (#125) — assertion de volumétrie par département (± 30 %).

Le nombre de ventes DVF insérées par département peut **légitimement** bouger d'un
millésime à l'autre ; mais un effondrement — ou une explosion — de volumétrie est le
symptôme d'une source tronquée, dédoublée ou d'un millésime mal ciblé. DAT-2
confronte, en **fin d'ingestion**, la volumétrie du département à celle du millésime
précédent : un écart au-delà de ± 30 % déclenche une **alerte**, jamais un échec
(contrairement à DAT-1/DAT-3) — c'est la seule assertion DAT non bloquante. Décision
**pure** : deux volumétries entrent, un verdict (alerte + motifs) sort.
"""

from __future__ import annotations

from valeur_verte_data.volumetrie import VerdictVolumetrie, evaluer_volumetrie

_ECART = {"ecart_max": 0.30}


def _ev(
    volumetrie: int, precedente: int | None, *, departement: str = "31"
) -> VerdictVolumetrie:
    return evaluer_volumetrie(departement, volumetrie, precedente, **_ECART)


# --- écart dans la fourchette : pas d'alerte ---


def test_volumetrie_stable_ne_declenche_pas_d_alerte() -> None:
    verdict = _ev(1000, 1050)
    assert isinstance(verdict, VerdictVolumetrie)
    assert verdict.alerte is False
    assert verdict.motifs == ()


def test_verdict_porte_le_departement_et_les_deux_volumetries() -> None:
    # Journalisation par département (DAT-2) : le verdict transporte tout le contexte
    # de la comparaison (département, volumétrie courante, volumétrie précédente).
    verdict = _ev(1000, 1050, departement="09")
    assert verdict.departement == "09"
    assert verdict.volumetrie == 1000
    assert verdict.volumetrie_precedente == 1050


def test_hausse_au_seuil_ne_declenche_pas_d_alerte() -> None:
    # « ± 30 % » : +30 % pile reste dans la fourchette (borne incluse).
    assert _ev(1300, 1000).alerte is False


def test_baisse_au_seuil_ne_declenche_pas_d_alerte() -> None:
    # −30 % pile reste dans la fourchette (borne incluse).
    assert _ev(700, 1000).alerte is False


# --- écart au-delà de ± 30 % : alerte (non bloquante) ---


def test_hausse_au_dela_du_seuil_alerte() -> None:
    # +45 % : au-delà de la fourchette → alerte. Le motif nomme le sens et l'ampleur ;
    # le verdict transporte le contexte (département, précédent) pour la journalisation.
    verdict = _ev(1450, 1000, departement="09")
    assert verdict.alerte is True
    assert verdict.departement == "09"
    assert verdict.volumetrie_precedente == 1000
    assert any("en hausse de 45" in m for m in verdict.motifs)


def test_baisse_au_dela_du_seuil_alerte() -> None:
    # −50 % : effondrement de volumétrie → alerte.
    verdict = _ev(500, 1000)
    assert verdict.alerte is True
    assert any("en baisse de 50" in m for m in verdict.motifs)


def test_le_motif_nomme_les_deux_volumetries() -> None:
    # Le motif journalisé rend l'écart lisible sans requête : précédent → courant.
    verdict = _ev(1500, 1000)
    assert any("1000" in m and "1500" in m for m in verdict.motifs)


# --- premier chargement : pas de précédent ---


def test_premier_chargement_est_neutre() -> None:
    # Sans millésime précédent (#19, Occitanie 2020), la fourchette est indéfinie :
    # verdict neutre plutôt qu'une alerte (ou un échec) infondée.
    verdict = _ev(1000, None)
    assert verdict.alerte is False
    assert verdict.motifs == ()
    # Le verdict neutre reste renseigné (volumétrie observée conservée, précédent absent).
    assert verdict.volumetrie == 1000
    assert verdict.volumetrie_precedente is None


def test_precedent_a_zero_est_neutre() -> None:
    # Sans base positive, l'écart relatif est indéfini (division par zéro) : neutre.
    verdict = _ev(1000, 0)
    assert verdict.alerte is False
    assert verdict.motifs == ()
    assert verdict.volumetrie_precedente == 0

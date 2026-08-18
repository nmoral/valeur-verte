"""DAT-5 (#128) — fraîcheur des sources (mutation la plus récente < 12 mois).

DVF est publié semestriellement : une source qui **cesse d'être mise à jour** ne lève
aucune erreur — le pipeline retraite sereinement des données périmées et publie des
coefficients qui vieillissent en silence. C'est le mode de défaillance le plus discret
du lot. DAT-5 confronte, **en fin d'ingestion**, la date de mutation la plus récente du
département à la **date d'exécution** : au-delà de ``max_mois`` (12 mois) d'ancienneté,
une **alerte** est journalisée — jamais un blocage (un backfill volontaire est
légitime, la décision de recalculer reste humaine). Décision **pure** : une date (+ la
référence) entre, un verdict sort.
"""

from __future__ import annotations

from datetime import date

from valeur_verte_data.fraicheur import (
    VerdictFraicheur,
    date_limite_fraicheur,
    evaluer_fraicheur,
)

_REFERENCE = date(2026, 3, 15)


def _ev(
    date_max: date | None, *, reference: date = _REFERENCE, departement: str = "31"
) -> VerdictFraicheur:
    return evaluer_fraicheur(departement, date_max, reference, max_mois=12)


# --- source fraîche : pas d'alerte ---


def test_source_fraiche_ne_declenche_pas_d_alerte() -> None:
    verdict = _ev(date(2026, 1, 10))
    assert isinstance(verdict, VerdictFraicheur)
    assert verdict.perimee is False
    assert verdict.motifs == ()


def test_verdict_porte_le_departement_et_la_date() -> None:
    verdict = _ev(date(2026, 1, 10), departement="09")
    assert verdict.departement == "09"
    assert verdict.date_mutation_max == date(2026, 1, 10)


def test_date_a_la_limite_est_fraiche() -> None:
    # 12 mois pile avant la référence : borne incluse (fraîche).
    assert _ev(date(2025, 3, 15)).perimee is False


# --- source périmée : alerte (non bloquante) ---


def test_source_perimee_declenche_une_alerte() -> None:
    # Mutation la plus récente de 2023 alors qu'on tourne en 2026 : source figée.
    verdict = _ev(date(2023, 6, 1), departement="09")
    assert verdict.perimee is True
    assert any("2023-06-01" in m for m in verdict.motifs)
    # Le verdict transporte le contexte pour la journalisation par département.
    assert verdict.departement == "09"
    assert verdict.date_mutation_max == date(2023, 6, 1)


def test_un_jour_avant_la_limite_est_perime() -> None:
    # La veille de la limite (2025-03-15) : périmée (borne stricte).
    assert _ev(date(2025, 3, 14)).perimee is True


# --- aucune vente datée : neutre ---


def test_sans_date_est_neutre() -> None:
    # Aucune mutation datée (rien à juger) : neutre plutôt qu'une alerte infondée.
    verdict = _ev(None, departement="09")
    assert verdict.perimee is False
    assert verdict.motifs == ()
    assert verdict.date_mutation_max is None
    assert verdict.departement == "09"


# --- date_limite_fraicheur : recul de N mois, avec bissextile et bascule d'année ---


def test_limite_recule_de_douze_mois() -> None:
    assert date_limite_fraicheur(date(2026, 3, 15), 12) == date(2025, 3, 15)


def test_limite_bascule_l_annee() -> None:
    assert date_limite_fraicheur(date(2026, 2, 10), 3) == date(2025, 11, 10)


def test_limite_clampe_le_jour_en_fin_de_mois() -> None:
    # 31 mars − 1 mois = 28 février (2026 non bissextile), pas un 31 février invalide.
    assert date_limite_fraicheur(date(2026, 3, 31), 1) == date(2026, 2, 28)


def test_limite_gere_le_29_fevrier_bissextile() -> None:
    # 31 mars 2024 − 1 mois = 29 février (2024 bissextile).
    assert date_limite_fraicheur(date(2024, 3, 31), 1) == date(2024, 2, 29)

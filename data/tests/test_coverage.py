"""Tests de la règle de couverture / limitrophes (§3.2, hors-ligne)."""

from __future__ import annotations

from valeur_verte_data.coverage import (
    CALCULE,
    CHARGE,
    EN_ATTENTE,
    EN_ATTENTE_LIMITROPHES,
    can_compute,
    is_covered,
    next_status,
)

# Limitrophes terrestres (extrait). 2A n'a pas de voisin terrestre modélisé
# (frontière naturelle : mer) → ne doit pas bloquer indéfiniment.
_NEIGHBORS = {
    "31": frozenset({"32", "09", "11"}),
    "32": frozenset({"31"}),
    "09": frozenset({"31"}),
    "11": frozenset({"31"}),
    "2A": frozenset(),
}


def test_can_compute_vrai_si_tous_limitrophes_charges() -> None:
    statuses = {"31": CHARGE, "32": CHARGE, "09": CHARGE, "11": CHARGE}

    assert can_compute("31", _NEIGHBORS, statuses)


def test_can_compute_faux_si_un_limitrophe_manque() -> None:
    statuses = {"31": CHARGE, "32": CHARGE, "09": EN_ATTENTE, "11": CHARGE}

    assert not can_compute("31", _NEIGHBORS, statuses)


def test_can_compute_faux_si_departement_non_charge() -> None:
    assert not can_compute("31", _NEIGHBORS, {"31": EN_ATTENTE, "32": CHARGE})


def test_can_compute_frontiere_naturelle_sans_limitrophe() -> None:
    assert can_compute("2A", _NEIGHBORS, {"2A": CHARGE})


def test_can_compute_departement_absent_de_la_carte() -> None:
    # Aucun limitrophe connu → pas de blocage (comme une frontière naturelle).
    assert can_compute("99", {}, {"99": CHARGE})


def test_can_compute_accepte_un_limitrophe_deja_calcule() -> None:
    statuses = {"31": CHARGE, "32": CALCULE, "09": CHARGE, "11": CHARGE}

    assert can_compute("31", _NEIGHBORS, statuses)


def test_next_status_en_attente_si_non_charge() -> None:
    assert next_status("31", _NEIGHBORS, {"31": EN_ATTENTE}) == EN_ATTENTE


def test_next_status_departement_absent_est_en_attente() -> None:
    assert next_status("31", _NEIGHBORS, {}) == EN_ATTENTE


def test_next_status_calcule_si_pret() -> None:
    statuses = {"31": CHARGE, "32": CHARGE, "09": CHARGE, "11": CHARGE}

    assert next_status("31", _NEIGHBORS, statuses) == CALCULE


def test_next_status_en_attente_limitrophes_si_voisin_manque() -> None:
    statuses = {"31": CHARGE, "32": CHARGE, "09": EN_ATTENTE, "11": CHARGE}

    assert next_status("31", _NEIGHBORS, statuses) == EN_ATTENTE_LIMITROPHES


def test_is_covered_seulement_si_calcule() -> None:
    assert is_covered("31", {"31": CALCULE})
    assert not is_covered("31", {"31": CHARGE})
    assert not is_covered("31", {})

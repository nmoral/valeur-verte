"""Tests de la normalisation et de la clé d'appariement d'adresses (hors-ligne)."""

from __future__ import annotations

from valeur_verte_data.matching import (
    address_key,
    normalize_number,
    normalize_street,
    parse_ban_address,
    strip_accents,
)


def test_strip_accents() -> None:
    assert strip_accents("Générale Éphémère") == "Generale Ephemere"


def test_normalize_street_developpe_les_abreviations_et_ote_les_mots_vides() -> None:
    assert normalize_street("Avenue des Fleurs") == "avenue fleurs"
    assert normalize_street("Bd Leclerc") == "boulevard leclerc"


def test_normalize_street_ote_accents_et_ponctuation() -> None:
    assert normalize_street("Rue de l'Église") == "rue eglise"


def test_normalize_street_vide() -> None:
    assert normalize_street("") == ""


def test_normalize_number() -> None:
    assert normalize_number("12", "bis") == "12 bis"
    assert normalize_number("12.0") == "12"
    assert normalize_number("") == ""
    assert normalize_number(None) == ""


def test_address_key() -> None:
    assert address_key("12", "Rue des Lilas") == "12 rue lilas"
    assert address_key(None, "Rue X") == "rue x"
    assert address_key("12", "") == ""


def test_parse_ban_address_ote_le_code_postal_et_la_commune() -> None:
    assert parse_ban_address("7 bis Rue du Test 31200 Blagnac") == "7 bis rue test"


def test_parse_ban_address_vide() -> None:
    assert parse_ban_address("") == ""
    assert parse_ban_address(None) == ""


def test_normalize_number_variantes() -> None:
    assert normalize_number("12bis") == "12"
    assert normalize_number("abc") == ""
    assert normalize_number("nan") == ""
    assert normalize_number("None") == ""
    assert normalize_number("12", "nan") == "12"
    assert normalize_number("12", "None") == "12"
    assert normalize_number("12", "BIS") == "12 bis"
    assert normalize_number("12", None) == "12"


def test_parse_ban_address_sans_numero() -> None:
    assert parse_ban_address("Rue des Fleurs 31000 Toulouse") == "rue fleurs"


def test_parse_ban_address_avec_suffixe() -> None:
    assert parse_ban_address("7 ter Avenue Jean 75000 Paris") == "7 ter avenue jean"

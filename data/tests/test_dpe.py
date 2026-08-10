"""Tests de l'index DPE (lookup) et de l'appariement aux mutations (hors-ligne)."""

from __future__ import annotations

from valeur_verte_data.dpe import DpeInfo, build_dpe_lookup


def _dpe(**overrides: str) -> dict[str, str]:
    record = {
        "code_postal": "31000",
        "adresse_ban": "12 Rue des Fleurs 31000 Toulouse",
        "etiquette_dpe": "D",
        "periode_construction": "1948-1974",
    }
    record.update(overrides)
    return record


def test_indexe_par_code_postal_et_adresse() -> None:
    lookup = build_dpe_lookup([_dpe()])

    assert lookup[("31000", "12 rue fleurs")] == DpeInfo(
        etiquette_dpe="D", periode_construction="1948-1974"
    )


def test_retient_l_etiquette_la_plus_frequente() -> None:
    records = [_dpe(etiquette_dpe="D"), _dpe(etiquette_dpe="D"), _dpe(etiquette_dpe="E")]

    lookup = build_dpe_lookup(records)

    assert lookup[("31000", "12 rue fleurs")].etiquette_dpe == "D"


def test_ignore_les_etiquettes_invalides() -> None:
    lookup = build_dpe_lookup([_dpe(etiquette_dpe="Z")])

    assert lookup == {}


def test_ignore_les_adresses_vides() -> None:
    lookup = build_dpe_lookup([_dpe(adresse_ban="")])

    assert lookup == {}


def test_periode_absente_donne_none() -> None:
    lookup = build_dpe_lookup([_dpe(periode_construction="")])

    assert lookup[("31000", "12 rue fleurs")].periode_construction is None

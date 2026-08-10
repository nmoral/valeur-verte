"""Tests de l'index DPE (lookup) et de l'appariement aux mutations (hors-ligne)."""

from __future__ import annotations

from valeur_verte_data.dpe import (
    DpeInfo,
    attach_dpe,
    build_dpe_lookup,
    matching_rate,
)
from valeur_verte_data.dvf import Mutation, aggregate_mutations


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


def _mutation(numero: str, voie: str, code_postal: str) -> Mutation:
    rows = [
        {
            "id_mutation": "m",
            "valeur_fonciere": "200000",
            "code_commune": "31555",
            "code_postal": code_postal,
            "surface_reelle_bati": "80",
            "adresse_numero": numero,
            "adresse_nom_voie": voie,
        }
    ]
    (mutation,) = aggregate_mutations(rows, millesime=2024)
    return mutation


def test_attach_dpe_renseigne_etiquette_et_periode() -> None:
    mutation = _mutation("12", "RUE DES FLEURS", "31000")
    lookup = {("31000", "12 rue fleurs"): DpeInfo("D", "1948-1974")}

    (result,) = attach_dpe([mutation], lookup)

    assert result.etiquette_dpe == "D"
    assert result.periode_construction == "1948-1974"


def test_attach_dpe_sans_correspondance_laisse_none() -> None:
    mutation = _mutation("12", "RUE DES FLEURS", "31000")

    (result,) = attach_dpe([mutation], {})

    assert result.etiquette_dpe is None
    assert result.periode_construction is None


def test_matching_rate_est_la_part_de_mutations_appariees() -> None:
    matched = _mutation("12", "RUE DES FLEURS", "31000")
    other = _mutation("99", "RUE INCONNUE", "31000")
    lookup = {("31000", "12 rue fleurs"): DpeInfo("D", "1948-1974")}

    result = attach_dpe([matched, other], lookup)

    assert matching_rate(result) == 0.5


def test_matching_rate_liste_vide() -> None:
    assert matching_rate([]) == 0.0

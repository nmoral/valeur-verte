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
        "date_etablissement_dpe": "2022-06-15",
        "version_dpe": "2.2",
    }
    record.update(overrides)
    return record


def test_indexe_par_code_postal_et_adresse() -> None:
    lookup = build_dpe_lookup([_dpe()])

    assert lookup[("31000", "12 rue fleurs")] == DpeInfo(
        etiquette_dpe="D",
        periode_construction="1948-1974",
        date_etablissement_dpe="2022-06-15",
        version_dpe="2.2",
    )


def test_retient_le_dpe_le_plus_recent() -> None:
    # #211 : le DPE le plus récent (date d'établissement max) fait foi — étiquette,
    # période, date et version viennent tous du même diagnostic, pas d'un mode.
    records = [
        _dpe(etiquette_dpe="E", date_etablissement_dpe="2019-03-01"),
        _dpe(etiquette_dpe="D", date_etablissement_dpe="2023-07-10"),
        _dpe(etiquette_dpe="F", date_etablissement_dpe="2021-01-05"),
    ]

    lookup = build_dpe_lookup(records)

    assert lookup[("31000", "12 rue fleurs")].etiquette_dpe == "D"


def test_dpe_date_l_emporte_sur_un_dpe_sans_date() -> None:
    # Un diagnostic daté prime sur un diagnostic sans date (moins récent par défaut).
    records = [
        _dpe(etiquette_dpe="D", date_etablissement_dpe="2022-05-01"),
        _dpe(etiquette_dpe="G", date_etablissement_dpe=""),
    ]

    lookup = build_dpe_lookup(records)

    assert lookup[("31000", "12 rue fleurs")].etiquette_dpe == "D"


def test_porte_la_date_et_la_version_du_dpe() -> None:
    lookup = build_dpe_lookup([_dpe(date_etablissement_dpe="2023-07-10", version_dpe="2.3")])

    info = lookup[("31000", "12 rue fleurs")]
    assert info.date_etablissement_dpe == "2023-07-10"
    assert info.version_dpe == "2.3"


def test_date_et_version_absentes_donnent_none() -> None:
    lookup = build_dpe_lookup([_dpe(date_etablissement_dpe="", version_dpe="")])

    info = lookup[("31000", "12 rue fleurs")]
    assert info.date_etablissement_dpe is None
    assert info.version_dpe is None


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


def test_retient_la_periode_du_dpe_le_plus_recent() -> None:
    records = [
        _dpe(periode_construction="avant 1948", date_etablissement_dpe="2018-01-01"),
        _dpe(periode_construction="1948-1974", date_etablissement_dpe="2024-02-02"),
    ]

    lookup = build_dpe_lookup(records)

    assert lookup[("31000", "12 rue fleurs")].periode_construction == "1948-1974"


def test_normalise_l_etiquette_en_majuscule() -> None:
    lookup = build_dpe_lookup([_dpe(etiquette_dpe="d")])

    assert lookup[("31000", "12 rue fleurs")].etiquette_dpe == "D"


def test_code_postal_distinct_separe_les_cles() -> None:
    records = [
        _dpe(code_postal="31000", etiquette_dpe="D"),
        _dpe(code_postal="31200", etiquette_dpe="F"),
    ]

    lookup = build_dpe_lookup(records)

    assert lookup[("31000", "12 rue fleurs")].etiquette_dpe == "D"
    assert lookup[("31200", "12 rue fleurs")].etiquette_dpe == "F"


def test_attach_dpe_est_limite_au_code_postal() -> None:
    mutation = _mutation("12", "RUE DES FLEURS", "31200")
    lookup = {("31000", "12 rue fleurs"): DpeInfo("D", "1948-1974")}

    (result,) = attach_dpe([mutation], lookup)

    assert result.etiquette_dpe is None


def test_matching_rate_tout_apparie() -> None:
    mutation = _mutation("12", "RUE DES FLEURS", "31000")
    lookup = {("31000", "12 rue fleurs"): DpeInfo("D", None)}

    result = attach_dpe([mutation], lookup)

    assert matching_rate(result) == 1.0


def test_attach_dpe_utilise_le_suffixe_dans_la_cle() -> None:
    rows = [
        {
            "id_mutation": "m",
            "valeur_fonciere": "200000",
            "code_commune": "31555",
            "code_postal": "31000",
            "surface_reelle_bati": "80",
            "adresse_numero": "12",
            "adresse_nom_voie": "RUE DES FLEURS",
            "adresse_suffixe": "bis",
        }
    ]
    (mutation,) = aggregate_mutations(rows, millesime=2024)
    lookup = {("31000", "12 bis rue fleurs"): DpeInfo("D", None)}

    (result,) = attach_dpe([mutation], lookup)

    assert result.etiquette_dpe == "D"

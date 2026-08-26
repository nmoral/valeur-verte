"""Tests du parsing du référentiel communes (API Découpage Administratif)."""

from __future__ import annotations

from valeur_verte_data.decoupage import (
    Commune,
    index_by_insee,
    parse_commune,
    parse_communes,
)


def _commune(**overrides: object) -> dict[str, object]:
    payload: dict[str, object] = {
        "nom": "Amiens",
        "code": "80021",
        "codeDepartement": "80",
        "codeEpci": "200069250",
        "codesPostaux": ["80000", "80080", "80090"],
        "centre": {"type": "Point", "coordinates": [2.2957, 49.8942]},
    }
    payload.update(overrides)
    return payload


def test_parse_commune_extrait_centroide_et_epci() -> None:
    commune = parse_commune(_commune())

    assert commune == Commune(
        code_insee="80021",
        nom="Amiens",
        code_epci="200069250",
        codes_postaux=("80000", "80080", "80090"),
        centre_longitude=2.2957,
        centre_latitude=49.8942,
        code_departement="80",
    )


def test_parse_commune_sans_code_est_ignoree() -> None:
    assert parse_commune(_commune(code="")) is None


def test_parse_commune_sans_centre_donne_coords_none() -> None:
    commune = parse_commune(_commune(centre=None))

    assert commune is not None
    assert commune.centre_longitude is None
    assert commune.centre_latitude is None


def test_parse_communes_filtre_les_invalides() -> None:
    communes = parse_communes([_commune(), _commune(code="")])

    assert [c.code_insee for c in communes] == ["80021"]


def test_index_by_insee() -> None:
    commune = parse_commune(_commune())
    assert commune is not None

    index = index_by_insee([commune])

    assert index["80021"].nom == "Amiens"


def test_parse_commune_centre_incomplet_donne_coords_none() -> None:
    commune = parse_commune(_commune(centre={"type": "Point", "coordinates": [2.29]}))

    assert commune is not None
    assert commune.centre_longitude is None
    assert commune.centre_latitude is None


def test_parse_commune_sans_epci_donne_none() -> None:
    payload = _commune()
    del payload["codeEpci"]

    commune = parse_commune(payload)

    assert commune is not None
    assert commune.code_epci is None


def test_parse_commune_extrait_le_nom_d_epci() -> None:
    """geo.api renvoie l'objet imbriqué ``epci`` quand on demande ce champ : son
    ``nom`` alimente le référentiel EPCI (#366), source du slug d'URL du hub EPCI."""
    payload = _commune(epci={"code": "200069250", "nom": "Amiens Métropole"})

    commune = parse_commune(payload)

    assert commune is not None
    assert commune.nom_epci == "Amiens Métropole"


def test_parse_commune_sans_objet_epci_donne_nom_epci_none() -> None:
    """Le nom d'EPCI est auxiliaire : son absence (champ non demandé, ou EPCI
    manquant) ne casse pas le parsing — ``None``, pas d'erreur."""
    commune = parse_commune(_commune())

    assert commune is not None
    assert commune.nom_epci is None


def test_parse_commune_sans_nom_donne_chaine_vide() -> None:
    payload = _commune()
    del payload["nom"]

    commune = parse_commune(payload)

    assert commune is not None
    assert commune.nom == ""

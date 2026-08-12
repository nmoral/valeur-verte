"""INV-3 (#121) — parsing du contour communal (polygone geo.api.gouv.fr).

Le contour (GeoJSON) alimente la dérivation de contiguïté commune↔commune
(PostGIS ``ST_Touches``, sous-cycle 3c). On l'extrait et on le valide **à la
frontière** de l'API ; la géométrie elle-même est traitée en base. On conserve
une chaîne JSON normalisée, directement consommable par ``ST_GeomFromGeoJSON``.
"""

from __future__ import annotations

import json
from collections.abc import Mapping

from valeur_verte_data.decoupage import CommuneContour, parse_contour

_POLYGON: Mapping[str, object] = {
    "type": "Polygon",
    "coordinates": [[[1.0, 43.0], [1.1, 43.0], [1.1, 43.1], [1.0, 43.0]]],
}


def _payload(contour: object) -> dict[str, object]:
    return {
        "code": "31555",
        "nom": "Toulouse",
        "codeDepartement": "31",
        "contour": contour,
    }


def test_parse_contour_extrait_le_code_et_la_geometrie() -> None:
    contour = parse_contour(_payload(_POLYGON))

    assert isinstance(contour, CommuneContour)
    assert contour.code_insee == "31555"
    assert json.loads(contour.contour) == _POLYGON


def test_parse_contour_accepte_un_multipolygone() -> None:
    multi = {
        "type": "MultiPolygon",
        "coordinates": [[[[1.0, 43.0], [1.1, 43.0], [1.1, 43.1], [1.0, 43.0]]]],
    }

    contour = parse_contour(_payload(multi))

    assert contour is not None
    assert json.loads(contour.contour)["type"] == "MultiPolygon"


def test_parse_contour_sans_contour_retourne_none() -> None:
    assert parse_contour({"code": "31555", "nom": "Toulouse"}) is None


def test_parse_contour_sans_code_retourne_none() -> None:
    assert parse_contour({"contour": _POLYGON}) is None


def test_parse_contour_type_non_surfacique_retourne_none() -> None:
    # Un point ou une ligne n'est pas un contour communal exploitable.
    ligne = {"type": "LineString", "coordinates": [[1.0, 43.0], [1.1, 43.1]]}

    assert parse_contour(_payload(ligne)) is None

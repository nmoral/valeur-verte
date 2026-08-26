"""Client du référentiel communes (API Découpage Administratif, geo.api.gouv.fr).

Récupère une commune (ou toutes celles d'un département) avec son centroïde
(§10.6) et son ``codeEpci`` (§5.1), puis délègue le parsing à ``decoupage``.
Testable hors-ligne via un ``Fetcher``.
"""

from __future__ import annotations

from collections.abc import Mapping

from .decoupage import Commune, CommuneContour, parse_commune, parse_communes, parse_contour
from .http import Fetcher
from .schema_source import COLONNES_COMMUNE, verifier_schema_json

GEO_API_URL = "https://geo.api.gouv.fr"
# ``epci`` (#366) demande l'objet imbriqué {code, nom} : le nom de l'EPCI voyage
# ainsi avec l'appel commune déjà fait, sans endpoint /epcis séparé. Auxiliaire —
# il n'entre pas dans la porte de schéma DAT-1 (COLONNES_COMMUNE).
_FIELDS = "nom,code,codeDepartement,codeEpci,epci,codesPostaux,centre"
_QUERY = {"fields": _FIELDS, "geometry": "centre", "format": "json"}

# Contours : polygones communaux pour la contiguïté INV-3 (#121). Requête à part,
# la géométrie ``contour`` étant bien plus lourde que le centroïde.
_CONTOUR_FIELDS = "code,codeDepartement,contour"
_CONTOUR_QUERY = {"fields": _CONTOUR_FIELDS, "geometry": "contour", "format": "json"}


def fetch_commune(fetcher: Fetcher, code_insee: str) -> Commune | None:
    """Récupère une commune par code INSEE, ou ``None`` si réponse inattendue."""
    payload = fetcher.get_json(f"{GEO_API_URL}/communes/{code_insee}", dict(_QUERY))
    return parse_commune(payload) if isinstance(payload, Mapping) else None


def fetch_communes_by_departement(fetcher: Fetcher, departement: str) -> list[Commune]:
    """Récupère toutes les communes d'un département (référentiel batch, §10.6)."""
    payload = fetcher.get_json(f"{GEO_API_URL}/departements/{departement}/communes", dict(_QUERY))
    if not isinstance(payload, list):
        return []
    # DAT-1 (#124) : un champ du référentiel renommé (codeEpci, centre) casserait
    # le repli EPCI ou le centroïde en silence — on le nomme, sur l'union des clés.
    verifier_schema_json(
        "Découpage", COLONNES_COMMUNE, [c for c in payload if isinstance(c, Mapping)]
    )
    return parse_communes(payload)


def fetch_contours_by_departement(fetcher: Fetcher, departement: str) -> list[CommuneContour]:
    """Récupère les contours communaux d'un département pour la contiguïté (#121)."""
    payload = fetcher.get_json(
        f"{GEO_API_URL}/departements/{departement}/communes", dict(_CONTOUR_QUERY)
    )
    if not isinstance(payload, list):
        return []
    # Pas de porte de schéma DAT-1 ici : les contours sont **auxiliaires** (INV-3,
    # #121) et chargés en mode résilient. Un champ `contour` renommé dégrade la
    # contiguïté, ne corrompt pas les coefficients — le référentiel communes (même
    # API) porte déjà la porte sur les champs du modèle (`code`, `centre`, `codeEpci`).
    contours = (parse_contour(item) for item in payload if isinstance(item, Mapping))
    return [contour for contour in contours if contour is not None]

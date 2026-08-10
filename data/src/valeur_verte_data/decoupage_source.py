"""Client du référentiel communes (API Découpage Administratif, geo.api.gouv.fr).

Récupère une commune (ou toutes celles d'un département) avec son centroïde
(§10.6) et son ``codeEpci`` (§5.1), puis délègue le parsing à ``decoupage``.
Testable hors-ligne via un ``Fetcher``.
"""

from __future__ import annotations

from collections.abc import Mapping

from .decoupage import Commune, parse_commune, parse_communes
from .http import Fetcher

GEO_API_URL = "https://geo.api.gouv.fr"
_FIELDS = "nom,code,codeDepartement,codeEpci,codesPostaux,centre"
_QUERY = {"fields": _FIELDS, "geometry": "centre", "format": "json"}


def fetch_commune(fetcher: Fetcher, code_insee: str) -> Commune | None:
    """Récupère une commune par code INSEE, ou ``None`` si réponse inattendue."""
    payload = fetcher.get_json(f"{GEO_API_URL}/communes/{code_insee}", dict(_QUERY))
    return parse_commune(payload) if isinstance(payload, Mapping) else None


def fetch_communes_by_departement(fetcher: Fetcher, departement: str) -> list[Commune]:
    """Récupère toutes les communes d'un département (référentiel batch, §10.6)."""
    payload = fetcher.get_json(f"{GEO_API_URL}/departements/{departement}/communes", dict(_QUERY))
    return parse_communes(payload) if isinstance(payload, list) else []

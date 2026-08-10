"""Client de géocodage BAN : adresse → meilleur résultat (code INSEE + coords).

Interroge l'API BAN (``api-adresse.data.gouv.fr/search``) et délègue le parsing
GeoJSON à ``ban``. Le ``citycode`` (code INSEE) restreint la recherche à une
commune, ce qui fiabilise l'appariement. Testable hors-ligne via un ``Fetcher``.
"""

from __future__ import annotations

from .ban import GeocodeResult, best_result
from .http import Fetcher

BAN_SEARCH_URL = "https://api-adresse.data.gouv.fr/search/"


def geocode(
    fetcher: Fetcher, query: str, *, code_insee: str | None = None, limit: int = 1
) -> GeocodeResult | None:
    """Géocode une adresse et retourne le meilleur résultat, ou ``None``."""
    params = {"q": query, "limit": str(limit)}
    if code_insee:
        params["citycode"] = code_insee
    return best_result(fetcher.get_json(BAN_SEARCH_URL, params))

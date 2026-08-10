"""Client DPE : récupère les diagnostics ADEME d'une commune et les indexe.

Interroge l'API data-fair de l'ADEME (dataset ``dpe-v2-logements-existants``),
suit la pagination via le lien ``next`` de chaque page, normalise les champs vers
les clés attendues par ``dpe.build_dpe_lookup``.

⚠️ Les noms de champs ADEME (``_FIELD_MAP``) sont une **hypothèse** : le schéma
exact n'est pas accessible hors-ligne. Ils sont centralisés ici pour être
corrigés en un seul point après vérification sur l'API réelle (schéma discovery).
"""

from __future__ import annotations

from collections.abc import Mapping

from .dpe import DpeInfo, build_dpe_lookup
from .http import Fetcher

ADEME_LINES_URL = "https://data.ademe.fr/data-fair/api/v1/datasets/dpe-v2-logements-existants/lines"

# Clé interne → nom de champ ADEME (à confirmer sur l'API réelle).
_FIELD_MAP = {
    "etiquette_dpe": "Etiquette_DPE",
    "adresse_ban": "Adresse_(BAN)",
    "code_postal": "Code_postal_(BAN)",
    "periode_construction": "Période_construction",
}
_INSEE_FIELD = "Code_INSEE_(BAN)"
_DEFAULT_PAGE_SIZE = 1000


def _normalize(record: Mapping[str, object]) -> dict[str, str]:
    return {key: str(record.get(ademe, "")) for key, ademe in _FIELD_MAP.items()}


def fetch_dpe_records(
    fetcher: Fetcher, code_insee: str, *, page_size: int = _DEFAULT_PAGE_SIZE
) -> list[dict[str, str]]:
    """Récupère tous les DPE d'une commune en suivant la pagination ADEME."""
    query: dict[str, str] | None = {
        "size": str(page_size),
        "qs": f'{_INSEE_FIELD}:"{code_insee}"',
        "select": ",".join(_FIELD_MAP.values()),
    }
    url = ADEME_LINES_URL
    records: list[dict[str, str]] = []
    while True:
        payload = fetcher.get_json(url, query)
        results = payload.get("results", []) if isinstance(payload, Mapping) else []
        rows = results if isinstance(results, list) else []
        records.extend(_normalize(row) for row in rows if isinstance(row, Mapping))
        next_url = payload.get("next") if isinstance(payload, Mapping) else None
        if not rows or not next_url:
            return records
        url = str(next_url)
        query = None


def dpe_lookup(fetcher: Fetcher, code_insee: str) -> dict[tuple[str, str], DpeInfo]:
    """Index DPE ``(code_postal, clé d'adresse) → DpeInfo`` pour une commune."""
    return build_dpe_lookup(fetch_dpe_records(fetcher, code_insee))

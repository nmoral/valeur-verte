"""Client DPE : récupère les diagnostics ADEME d'une commune et les indexe.

Interroge l'API data-fair de l'ADEME (dataset ``dpe-v2-logements-existants``),
suit la pagination via le lien ``next`` de chaque page, normalise les champs vers
les clés attendues par ``dpe.build_dpe_lookup``.

⚠️ Les noms de champs ADEME (``_FIELD_MAP``) sont une **hypothèse** : le schéma
exact n'est pas accessible hors-ligne. Ils sont centralisés ici pour être
corrigés en un seul point après vérification sur l'API réelle (schéma discovery).
"""

from __future__ import annotations

import logging
from collections.abc import Mapping

from .dpe import DpeInfo, build_dpe_lookup
from .http import Fetcher
from .schema_source import COLONNES_DPE, periodes_inconnues, verifier_schema_json

logger = logging.getLogger("valeur_verte_data")

# Champ ADEME portant la tranche de construction, contrôlé par DAT-1 (#124).
_CHAMP_PERIODE = "periode_construction"

# Jeu « DPE Logements existants (depuis juillet 2021) ». L'identifiant est un
# slug opaque : le nom lisible « dpe-v2-logements-existants » n'existe plus.
ADEME_DATASET = "meg-83tjwtg8dyz4vv7h1dqe"
ADEME_LINES_URL = f"https://data.ademe.fr/data-fair/api/v1/datasets/{ADEME_DATASET}/lines"

# Clé interne → nom de champ ADEME, relevés sur le schéma publié du jeu de
# données et vérifiés par tests/integration/test_dpe_source_reel.py.
_FIELD_MAP = {
    "etiquette_dpe": "etiquette_dpe",
    "adresse_ban": "adresse_ban",
    "code_postal": "code_postal_ban",
    "periode_construction": "periode_construction",
    # #211 : date d'établissement et version de méthode — datent le diagnostic pour
    # la gestion des ruptures de réforme DPE (#208/#127).
    "date_etablissement_dpe": "date_etablissement_dpe",
    "version_dpe": "version_dpe",
}
_INSEE_FIELD = "code_insee_ban"
# Toulouse compte 186 000 DPE : par pages de 1 000 il faudrait 187 requêtes pour
# une seule commune. 10 000 est le maximum accepté par l'API.
_DEFAULT_PAGE_SIZE = 10000


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
    bruts: list[Mapping[str, object]] = []
    while True:
        payload = fetcher.get_json(url, query)
        results = payload.get("results", []) if isinstance(payload, Mapping) else []
        rows = results if isinstance(results, list) else []
        lignes = [row for row in rows if isinstance(row, Mapping)]
        bruts.extend(lignes)
        records.extend(_normalize(row) for row in lignes)
        next_url = payload.get("next") if isinstance(payload, Mapping) else None
        if not rows or not next_url:
            break
        url = str(next_url)
        query = None

    # DAT-1 (#124), au bord : le schéma est jugé sur les enregistrements bruts (les
    # noms de champs ADEME), avant la normalisation qui masquerait un renommage.
    verifier_schema_json("DPE", COLONNES_DPE, bruts)
    _signaler_periodes_inconnues(bruts, code_insee)
    return records


def _signaler_periodes_inconnues(bruts: list[Mapping[str, object]], code_insee: str) -> None:
    """Journalise toute tranche ``periode_construction`` hors vocabulaire (DAT-1).

    Signalé, non bloquant : une tranche nouvelle est un simple niveau catégoriel de
    plus dans la régression, pas une corruption du schéma.
    """
    valeurs = [str(brut.get(_CHAMP_PERIODE, "")) for brut in bruts]
    inconnues = periodes_inconnues(valeurs)
    if inconnues:
        logger.warning(
            "Commune %s : periode_construction hors vocabulaire connu : %s",
            code_insee,
            ", ".join(sorted(inconnues)),
        )


def dpe_lookup(fetcher: Fetcher, code_insee: str) -> dict[tuple[str, str], DpeInfo]:
    """Index DPE ``(code_postal, clé d'adresse) → DpeInfo`` pour une commune."""
    return build_dpe_lookup(fetch_dpe_records(fetcher, code_insee))

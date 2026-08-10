"""Index DPE (ADEME) pour l'appariement aux ventes DVF.

Construit un index ``(code_postal, clé d'adresse) → étiquette + période de
construction`` à partir des enregistrements DPE. En cas de DPE multiples pour une
même adresse, on retient l'étiquette et la période **les plus fréquentes** (ce qui
lisse les diagnostics successifs d'un même logement). Logique pure, hors-ligne.
"""

from __future__ import annotations

from collections import Counter
from collections.abc import Iterable, Mapping
from dataclasses import dataclass

from .matching import parse_ban_address

VALID_LABELS = ("A", "B", "C", "D", "E", "F", "G")


@dataclass(frozen=True)
class DpeInfo:
    """Résultat d'appariement DPE : étiquette et période de construction."""

    etiquette_dpe: str
    periode_construction: str | None


def build_dpe_lookup(
    records: Iterable[Mapping[str, str]],
) -> dict[tuple[str, str], DpeInfo]:
    """Indexe les DPE par ``(code_postal, clé d'adresse)``."""
    labels: dict[tuple[str, str], Counter[str]] = {}
    periodes: dict[tuple[str, str], Counter[str]] = {}
    for record in records:
        etiquette = str(record.get("etiquette_dpe", "")).strip().upper()
        if etiquette not in VALID_LABELS:
            continue
        adresse_key = parse_ban_address(record.get("adresse_ban"))
        if not adresse_key:
            continue
        key = (str(record.get("code_postal", "")).strip(), adresse_key)
        labels.setdefault(key, Counter())[etiquette] += 1
        periode = str(record.get("periode_construction", "")).strip()
        if periode:
            periodes.setdefault(key, Counter())[periode] += 1

    lookup: dict[tuple[str, str], DpeInfo] = {}
    for key, label_counts in labels.items():
        periode_counts = periodes.get(key)
        lookup[key] = DpeInfo(
            etiquette_dpe=label_counts.most_common(1)[0][0],
            periode_construction=(periode_counts.most_common(1)[0][0] if periode_counts else None),
        )
    return lookup

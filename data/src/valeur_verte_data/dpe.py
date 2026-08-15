"""Index DPE (ADEME) pour l'appariement aux ventes DVF.

Construit un index ``(code_postal, clé d'adresse) → DpeInfo`` à partir des
enregistrements DPE. En cas de DPE multiples pour une même adresse, on retient le
**diagnostic le plus récent** (date d'établissement max) : étiquette, période, date
et version viennent tous du même DPE — cohérent pour dater les ruptures de réforme
(#211, prérequis #208/#127). Un DPE daté prime sur un DPE sans date. Logique pure,
hors-ligne.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from dataclasses import dataclass, replace

from .dvf import Mutation
from .matching import address_key, parse_ban_address

VALID_LABELS = ("A", "B", "C", "D", "E", "F", "G")


@dataclass(frozen=True)
class DpeInfo:
    """Résultat d'appariement DPE : étiquette, période, date et version (#211)."""

    etiquette_dpe: str
    periode_construction: str | None
    date_etablissement_dpe: str | None = None
    version_dpe: str | None = None


def build_dpe_lookup(
    records: Iterable[Mapping[str, str]],
) -> dict[tuple[str, str], DpeInfo]:
    """Indexe les DPE par ``(code_postal, clé d'adresse)`` — le plus récent fait foi.

    À date d'établissement égale, le **premier rencontré** est conservé (tri stable,
    reproductible pour un même jeu d'entrée). Une date absente est traitée comme la
    moins récente.
    """
    best: dict[tuple[str, str], tuple[str, DpeInfo]] = {}
    for record in records:
        etiquette = str(record.get("etiquette_dpe", "")).strip().upper()
        if etiquette not in VALID_LABELS:
            continue
        adresse_key = parse_ban_address(record.get("adresse_ban"))
        if not adresse_key:
            continue
        key = (str(record.get("code_postal", "")).strip(), adresse_key)
        date = str(record.get("date_etablissement_dpe", "")).strip()
        periode = str(record.get("periode_construction", "")).strip()
        version = str(record.get("version_dpe", "")).strip()
        existant = best.get(key)
        # Les dates ADEME sont au format ISO (YYYY-MM-DD…) : le tri lexicographique
        # ordonne les diagnostics ; "" (date absente) est le plus petit → le moins récent.
        if existant is None or date > existant[0]:
            best[key] = (
                date,
                DpeInfo(
                    etiquette_dpe=etiquette,
                    periode_construction=periode or None,
                    date_etablissement_dpe=date or None,
                    version_dpe=version or None,
                ),
            )
    return {key: info for key, (_, info) in best.items()}


def attach_dpe(
    mutations: Iterable[Mutation], lookup: Mapping[tuple[str, str], DpeInfo]
) -> list[Mutation]:
    """Renseigne étiquette et période par appariement ``(code_postal, adresse)``."""
    result: list[Mutation] = []
    for mutation in mutations:
        key = (
            mutation.code_postal or "",
            address_key(
                mutation.adresse_numero,
                mutation.adresse_nom_voie,
                mutation.adresse_suffixe,
            ),
        )
        info = lookup.get(key)
        if info is None:
            result.append(mutation)
        else:
            result.append(
                replace(
                    mutation,
                    etiquette_dpe=info.etiquette_dpe,
                    periode_construction=info.periode_construction,
                )
            )
    return result


def matching_rate(mutations: Iterable[Mutation]) -> float:
    """Part de mutations appariées à un DPE (étiquette renseignée), 0 si vide."""
    items = list(mutations)
    if not items:
        return 0.0
    matched = sum(1 for mutation in items if mutation.etiquette_dpe is not None)
    return matched / len(items)

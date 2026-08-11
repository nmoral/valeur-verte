"""Agrégation des lignes DVF géolocalisées en mutations (§7).

Le fichier geo-dvf d'Etalab compte une ligne par lot ; une vente (mutation) peut
porter sur plusieurs lots. On agrège en une ligne par ``id_mutation`` :

- le prix (``valeur_fonciere``) est répété sur chaque ligne : on le prend une fois ;
- la surface bâtie est la somme des lots ;
- les champs scalaires (commune, type, adresse, coordonnées) viennent du local de
  plus grande surface bâtie.

La clé de rattachement commune est ``code_insee`` (= ``code_commune``), jamais le
code postal (§7). L'agrégation de la surface de terrain est traitée séparément.
"""

from __future__ import annotations

import csv
import io
from collections.abc import Iterable, Mapping
from dataclasses import dataclass


@dataclass(frozen=True)
class Mutation:
    """Une vente agrégée, prête à alimenter ``data.vente``."""

    id_mutation: str
    date_mutation: str | None
    millesime: int
    code_insee: str
    code_postal: str | None
    nom_commune: str | None
    type_local: str | None
    nombre_pieces_principales: int | None
    prix: float | None
    surface_bati: float | None
    surface_terrain: float | None
    prix_m2: float | None
    adresse_numero: str | None
    adresse_suffixe: str | None
    adresse_nom_voie: str | None
    longitude: float | None
    latitude: float | None
    # Renseignés par l'appariement DPE (voir dpe.attach_dpe), None sinon.
    etiquette_dpe: str | None = None
    periode_construction: str | None = None


def _to_float(value: object) -> float | None:
    text = "" if value is None else str(value).strip()
    if not text:
        return None
    try:
        return float(text)
    except ValueError:
        return None


def _to_int(value: object) -> int | None:
    number = _to_float(value)
    return None if number is None else int(number)


def _clean(value: object) -> str | None:
    text = "" if value is None else str(value).strip()
    return text or None


def _largest(rows: list[Mapping[str, str]]) -> Mapping[str, str]:
    return max(rows, key=lambda row: _to_float(row.get("surface_reelle_bati")) or 0.0)


def _sum_bati(rows: list[Mapping[str, str]]) -> float | None:
    surfaces = [
        surface
        for surface in (_to_float(row.get("surface_reelle_bati")) for row in rows)
        if surface is not None
    ]
    return sum(surfaces) if surfaces else None


# Code de nature de culture des sols. On filtre sur le **code** et non sur le
# libellé : celui-ci varie en orthographe et en accentuation dans la source
# (« terrains a bâtir » sans accent), et c'est en l'interrogeant à la place du
# code que toutes les surfaces de terrain ont été perdues (#136).
CODE_NATURE_SOLS = "S"


def _sum_terrain(rows: list[Mapping[str, str]]) -> float | None:
    """Somme des surfaces de terrain par parcelle distincte, sols uniquement (§7).

    ``surface_terrain`` est répétée sur chaque ligne d'une même parcelle : on
    déduplique par ``id_parcelle``. Seules les parcelles de nature « sols » sont
    comptées, pour exclure les terres agricoles qui polluent le modèle.
    """
    per_parcelle: dict[str, float] = {}
    for row in rows:
        if _clean(row.get("code_nature_culture")) != CODE_NATURE_SOLS:
            continue
        parcelle = _clean(row.get("id_parcelle"))
        surface = _to_float(row.get("surface_terrain"))
        if parcelle is None or surface is None:
            continue
        per_parcelle[parcelle] = surface
    return sum(per_parcelle.values()) if per_parcelle else None


def _price(rows: list[Mapping[str, str]]) -> float | None:
    for row in rows:
        prix = _to_float(row.get("valeur_fonciere"))
        if prix is not None:
            return prix
    return None


def _prix_m2(prix: float | None, surface_bati: float | None) -> float | None:
    if prix is None or not surface_bati:
        return None
    return round(prix / surface_bati, 2)


def aggregate_mutations(rows: Iterable[Mapping[str, str]], millesime: int) -> list[Mutation]:
    """Agrège des lignes DVF brutes en une liste de mutations."""
    groups: dict[str, list[Mapping[str, str]]] = {}
    for row in rows:
        key = _clean(row.get("id_mutation"))
        if key is None:
            continue
        groups.setdefault(key, []).append(row)

    mutations: list[Mutation] = []
    for id_mutation, group in groups.items():
        principal = _largest(group)
        prix = _price(group)
        surface_bati = _sum_bati(group)
        mutations.append(
            Mutation(
                id_mutation=id_mutation,
                date_mutation=_clean(principal.get("date_mutation")),
                millesime=millesime,
                code_insee=_clean(principal.get("code_commune")) or "",
                code_postal=_clean(principal.get("code_postal")),
                nom_commune=_clean(principal.get("nom_commune")),
                type_local=_clean(principal.get("type_local")),
                nombre_pieces_principales=_to_int(principal.get("nombre_pieces_principales")),
                prix=prix,
                surface_bati=surface_bati,
                surface_terrain=_sum_terrain(group),
                prix_m2=_prix_m2(prix, surface_bati),
                adresse_numero=_clean(principal.get("adresse_numero")),
                adresse_suffixe=_clean(principal.get("adresse_suffixe")),
                adresse_nom_voie=_clean(principal.get("adresse_nom_voie")),
                longitude=_to_float(principal.get("longitude")),
                latitude=_to_float(principal.get("latitude")),
            )
        )
    return mutations


def parse_dvf_csv(text: str) -> list[dict[str, str]]:
    """Parse un CSV geo-dvf (en-tête + lignes) en une liste de dictionnaires."""
    reader = csv.DictReader(io.StringIO(text))
    return [
        {str(key): "" if value is None else str(value) for key, value in row.items()}
        for row in reader
    ]


def mutations_from_csv(text: str, millesime: int) -> list[Mutation]:
    """Chemin complet : CSV geo-dvf brut → mutations agrégées (§7)."""
    return aggregate_mutations(parse_dvf_csv(text), millesime)

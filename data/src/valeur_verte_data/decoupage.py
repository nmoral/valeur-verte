"""Parsing du référentiel communes (API Découpage Administratif, geo.api.gouv.fr).

Chaque commune expose son code INSEE (``code``), son libellé (``nom``), son
EPCI (``codeEpci``), ses codes postaux et son centroïde (``centre`` GeoJSON).
Deux usages en aval :

- le **centroïde** sert de point de départ au rayon adaptatif au batch (§10.6) ;
- le ``code_epci`` permet le repli EPCI du mode dégradé (§5.1).

Logique pure, hors-ligne : l'appel HTTP est traité ailleurs.
"""

from __future__ import annotations

import json
from collections.abc import Iterable, Mapping
from dataclasses import dataclass

# Types GeoJSON surfaciques : seuls exploitables comme contour communal (#121).
_TYPES_SURFACIQUES = frozenset({"Polygon", "MultiPolygon"})


@dataclass(frozen=True)
class Commune:
    """Une commune du référentiel, réduite aux champs utiles au moteur."""

    code_insee: str
    nom: str
    code_epci: str | None
    codes_postaux: tuple[str, ...]
    centre_longitude: float | None
    centre_latitude: float | None
    code_departement: str | None
    # #366 : nom de l'EPCI de rattachement, porté par l'objet imbriqué ``epci`` de
    # geo.api. Auxiliaire (défaut ``None``) : sa présence dépend du champ demandé et
    # de l'existence d'un EPCI ; il alimente le référentiel des noms d'EPCI.
    nom_epci: str | None = None


def _clean(value: object) -> str | None:
    text = "" if value is None else str(value).strip()
    return text or None


def _centre(payload: Mapping[str, object]) -> tuple[float | None, float | None]:
    centre = payload.get("centre")
    if not isinstance(centre, Mapping):
        return None, None
    coordinates = centre.get("coordinates")
    if not isinstance(coordinates, (list, tuple)) or len(coordinates) < 2:
        return None, None
    return float(coordinates[0]), float(coordinates[1])


def _nom_epci(payload: Mapping[str, object]) -> str | None:
    epci = payload.get("epci")
    if not isinstance(epci, Mapping):
        return None
    return _clean(epci.get("nom"))


def _codes_postaux(payload: Mapping[str, object]) -> tuple[str, ...]:
    codes = payload.get("codesPostaux")
    if not isinstance(codes, (list, tuple)):
        return ()
    return tuple(str(code).strip() for code in codes if _clean(code))


def parse_commune(payload: Mapping[str, object]) -> Commune | None:
    """Parse une commune ; ``None`` si le code INSEE est absent."""
    code_insee = _clean(payload.get("code"))
    if code_insee is None:
        return None
    longitude, latitude = _centre(payload)
    return Commune(
        code_insee=code_insee,
        nom=str(payload.get("nom", "")),
        code_epci=_clean(payload.get("codeEpci")),
        codes_postaux=_codes_postaux(payload),
        centre_longitude=longitude,
        centre_latitude=latitude,
        code_departement=_clean(payload.get("codeDepartement")),
        nom_epci=_nom_epci(payload),
    )


@dataclass(frozen=True)
class CommuneContour:
    """Contour d'une commune (#121), en GeoJSON prêt pour ``ST_GeomFromGeoJSON``.

    ``contour`` est une chaîne JSON **normalisée** (clés triées, compacte) : la
    normalisation rend l'ingestion reproductible (INV-5) et évite de transporter
    un ``Mapping`` mutable jusqu'à la base.
    """

    code_insee: str
    code_departement: str
    contour: str


def parse_contour(payload: Mapping[str, object]) -> CommuneContour | None:
    """Extrait le contour surfacique d'une commune, ``None`` si absent/inexploitable."""
    code_insee = _clean(payload.get("code"))
    if code_insee is None:
        return None
    contour = payload.get("contour")
    if not isinstance(contour, Mapping) or contour.get("type") not in _TYPES_SURFACIQUES:
        return None
    if not contour.get("coordinates"):
        return None
    normalise = json.dumps(contour, sort_keys=True, separators=(",", ":"))
    # Le préfixe INSEE fait foi si le champ est absent (métropole, §7).
    departement = _clean(payload.get("codeDepartement")) or code_insee[:2]
    return CommuneContour(code_insee=code_insee, code_departement=departement, contour=normalise)


def parse_communes(payload: Iterable[Mapping[str, object]]) -> list[Commune]:
    """Parse une liste de communes, en écartant les entrées invalides."""
    communes: list[Commune] = []
    for item in payload:
        commune = parse_commune(item)
        if commune is not None:
            communes.append(commune)
    return communes


def index_by_insee(communes: Iterable[Commune]) -> dict[str, Commune]:
    """Indexe les communes par code INSEE."""
    return {commune.code_insee: commune for commune in communes}

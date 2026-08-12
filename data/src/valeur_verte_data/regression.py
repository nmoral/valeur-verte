"""Régression linéaire pondérée pour les coefficients DPE (§5.2).

Modèle : ``prix ~ surface_bati + surface_terrain + dummies_DPE + effets_fixes
commune + effets_fixes_annee``, estimé par moindres carrés **pondérés** (poids
gaussien décroissant avec la distance). Restriction au **bâti ancien (< 1989)** :
cœur méthodologique, on mesure le gain réel de rénovation sans taux de capture.
"""

from __future__ import annotations

import math
import re
from collections.abc import Callable, Iterable
from dataclasses import dataclass

import numpy as np
from numpy.typing import NDArray

from .dvf import Mutation
from .radius import distances_km

DEFAULT_SIGMA_KM = 10.0
DEFAULT_REFERENCE_DPE = "D"

# Nombre de ventes voisines résumant le niveau de prix d'un quartier. Assez
# large pour lisser le bruit, assez étroit pour rester dans le même secteur.
VOISINS_INDICE_LOCAL = 20

# Étendue géographique minimale, en degrés (~200 m), sous laquelle l'échantillon
# ne contient pas de quartiers à distinguer. L'indice y dégénérerait en moyenne
# des autres ventes — effet DPE compris — et absorberait le signal cherché.
ETENDUE_MINIMALE_INDICE = 0.002
_YEAR_RE = re.compile(r"\d{4}")


class InsufficientSampleError(Exception):
    """L'échantillon est vide après restriction au bâti ancien."""


@dataclass(frozen=True)
class _Row:
    """Ligne éligible à la régression (champs requis non nuls)."""

    prix: float
    bati: float
    terrain: float
    dpe: str
    periode: str
    insee: str
    annee: str
    lat: float
    lon: float


@dataclass(frozen=True)
class RegressionResult:
    """Coefficients estimés et qualité d'ajustement pondérée."""

    coef_dpe: dict[str, float]
    coef_m2_bati: float
    coef_m2_terrain: float
    n: int
    r2: float


def gaussian_weight(distance_km: float, sigma_km: float = DEFAULT_SIGMA_KM) -> float:
    """Poids gaussien ``exp(-d² / 2σ²)`` (1 au centre, décroissant avec la distance)."""
    return math.exp(-(distance_km**2) / (2 * sigma_km**2))


def is_pre_1989(periode: str | None) -> bool:
    """Vrai si la période de construction est **antérieure à 1989** (bâti ancien).

    Les périodes ADEME sont des tranches (« avant 1948 », « 1975-1977 »…) : on
    retient l'année la plus tardive et on la compare à 1989. Période absente ou
    non datée → ``False`` (on n'inclut que le bâti ancien avéré).
    """
    if not periode:
        return False
    years = [int(match) for match in _YEAR_RE.findall(periode)]
    if not years:
        return False
    return max(years) < 1989


def _eligible(sample: Iterable[Mutation], *, restreindre_bati_ancien: bool = True) -> list[_Row]:
    """Filtre les mutations exploitables (§5.2).

    ``restreindre_bati_ancien`` restreint au bâti < 1989 (mesure du gain de
    rénovation sans taux de capture) ; désactivé, le bâti récent entre aussi —
    un logement récent en E existe et doit compter (S20).
    """
    rows, _ = _eligible_avec_source(sample, restreindre_bati_ancien=restreindre_bati_ancien)
    return rows


def _eligible_avec_source(
    sample: Iterable[Mutation], *, restreindre_bati_ancien: bool = True
) -> tuple[list[_Row], list[Mutation]]:
    """Comme ``_eligible``, mais renvoie aussi les mutations sources **alignées**.

    L'écrêtage sur résidu (S32 V2) doit retirer des *mutations* de l'échantillon à
    partir de résidus calculés sur des ``_Row`` : garder la correspondance rang ↔
    mutation évite de dupliquer le prédicat d'éligibilité.
    """
    rows: list[_Row] = []
    sources: list[Mutation] = []
    for mutation in sample:
        if mutation.prix is None or mutation.surface_bati is None or mutation.surface_bati <= 0:
            continue
        if mutation.prix <= 0:  # le logarithme exige un prix strictement positif
            continue
        if mutation.etiquette_dpe is None:
            continue
        if mutation.latitude is None or mutation.longitude is None:
            continue
        if restreindre_bati_ancien and not is_pre_1989(mutation.periode_construction):
            continue
        rows.append(
            _Row(
                prix=mutation.prix,
                bati=mutation.surface_bati,
                terrain=mutation.surface_terrain or 0.0,
                dpe=mutation.etiquette_dpe,
                periode=mutation.periode_construction or "",
                insee=mutation.code_insee,
                annee=str(mutation.millesime),
                lat=mutation.latitude,
                lon=mutation.longitude,
            )
        )
        sources.append(mutation)
    return rows, sources


@dataclass(frozen=True)
class _Columns:
    """Niveaux retenus (hors référence) pour chaque variable catégorielle."""

    reference_dpe: str
    dpe: list[str]
    periode: list[str]
    commune: list[str]
    annee: list[str]


@dataclass(frozen=True)
class _Model:
    """Modèle ajusté : coefficients ``beta`` et structure de colonnes associée."""

    beta: NDArray[np.float64]
    columns: _Columns
    apprentissage: list[_Row]
    voisins: int
    etendue_minimale: float
    avec_indice: bool


def _build_columns(rows: list[_Row], reference_dpe: str, *, avec_commune: bool = True) -> _Columns:
    """Détermine les niveaux d'indicatrices (référence DPE + niveaux gardés)."""
    dpe_levels = sorted({row.dpe for row in rows})
    if reference_dpe in dpe_levels:
        ref = reference_dpe
    else:
        ref = dpe_levels[0] if dpe_levels else reference_dpe
    return _Columns(
        reference_dpe=ref,
        dpe=[level for level in dpe_levels if level != ref],
        # La période est corrélée au DPE : sans elle, l'étiquette capte
        # l'effet de l'époque de construction (#115).
        periode=sorted({row.periode for row in rows})[1:],
        # Effets fixes commune (S22) : désactivables, l'indice local et la
        # pondération portent déjà la localité.
        commune=sorted({row.insee for row in rows})[1:] if avec_commune else [],
        annee=sorted({row.annee for row in rows})[1:],
    )


def _encode(
    rows: list[_Row],
    columns: _Columns,
    apprentissage: list[_Row] | None = None,
    *,
    voisins: int = VOISINS_INDICE_LOCAL,
    etendue_minimale: float = ETENDUE_MINIMALE_INDICE,
    avec_indice: bool = True,
) -> NDArray[np.float64]:
    """Matrice de design à colonnes **fixées** (pour ré-encoder un jeu de test)."""
    n = len(rows)
    blocks = [
        np.ones((n, 1)),
        np.log(np.array([row.bati for row in rows], dtype=float))[:, None],
        np.log1p(np.array([row.terrain for row in rows], dtype=float))[:, None],
    ]
    if avec_indice:
        indice = _indice_local(
            rows, apprentissage, voisins=voisins, etendue_minimale=etendue_minimale
        )
        blocks.append(np.log(np.maximum(indice, 1.0))[:, None])
    extractors: tuple[tuple[list[str], Callable[[_Row], str]], ...] = (
        (columns.dpe, lambda row: row.dpe),
        (columns.periode, lambda row: row.periode),
        (columns.commune, lambda row: row.insee),
        (columns.annee, lambda row: row.annee),
    )
    for levels, value_of in extractors:
        block = np.zeros((n, len(levels)))
        index = {level: column for column, level in enumerate(levels)}
        for line, row in enumerate(rows):
            column = index.get(value_of(row))
            if column is not None:
                block[line, column] = 1.0
        blocks.append(block)
    return np.hstack(blocks)


def _indice_local(
    rows: list[_Row],
    reference: list[_Row] | None = None,
    *,
    voisins: int = VOISINS_INDICE_LOCAL,
    etendue_minimale: float = ETENDUE_MINIMALE_INDICE,
) -> NDArray[np.float64]:
    """Niveau de prix du quartier de chaque ligne, le bien lui-même exclu.

    Sans cette exclusion, l'indice contiendrait le prix qu'il sert à expliquer
    et le modèle s'auto-prédirait. Les voisines sont cherchées dans
    ``reference`` (l'échantillon d'apprentissage) pour qu'une ligne de test ne
    s'appuie jamais sur d'autres lignes de test.
    """
    voisinage = rows if reference is None else reference
    if not voisinage:
        return np.ones(len(rows))

    etendue = max(
        max(row.lat for row in voisinage) - min(row.lat for row in voisinage),
        max(row.lon for row in voisinage) - min(row.lon for row in voisinage),
    )
    if etendue < etendue_minimale:
        # Pas de quartiers à distinguer : indice neutre (log 1 = 0).
        return np.ones(len(rows))

    prix_m2 = np.array([row.prix / row.bati for row in voisinage], dtype=float)
    lat_v = np.array([row.lat for row in voisinage], dtype=float)
    lon_v = np.array([row.lon for row in voisinage], dtype=float)
    lat = np.array([row.lat for row in rows], dtype=float)
    lon = np.array([row.lon for row in rows], dtype=float)

    # Distances euclidiennes approchées : le facteur corrige l'écrasement des
    # longitudes aux latitudes métropolitaines. Un ordre suffit ici.
    ecarts = (lat[:, None] - lat_v[None, :]) ** 2 + ((lon[:, None] - lon_v[None, :]) * 0.73) ** 2
    if reference is None:
        np.fill_diagonal(ecarts, np.inf)

    retenus = min(voisins, ecarts.shape[1] - (1 if reference is None else 0))
    if retenus < 1:
        return np.ones(len(rows))

    indices_voisins = np.argsort(ecarts, axis=1)[:, :retenus]
    return np.asarray(np.median(prix_m2[indices_voisins], axis=1), dtype=float)


def _weights(
    rows: list[_Row], center_lat: float, center_lon: float, sigma_km: float
) -> NDArray[np.float64]:
    """Poids gaussien de chaque ligne, **vectorisé** (exp(-d²/2σ²))."""
    lats = np.array([row.lat for row in rows], dtype=np.float64)
    lons = np.array([row.lon for row in rows], dtype=np.float64)
    distances = distances_km(center_lat, center_lon, lats, lons)
    return np.asarray(np.exp(-(distances**2) / (2.0 * sigma_km**2)), dtype=np.float64)


def _fit_model(
    rows: list[_Row],
    center_lat: float,
    center_lon: float,
    sigma_km: float,
    reference_dpe: str,
    *,
    voisins: int = VOISINS_INDICE_LOCAL,
    etendue_minimale: float = ETENDUE_MINIMALE_INDICE,
    avec_commune: bool = True,
    avec_indice: bool = True,
) -> _Model:
    """Ajuste le modèle pondéré et renvoie ``beta`` + la structure de colonnes."""
    columns = _build_columns(rows, reference_dpe, avec_commune=avec_commune)
    design = _encode(
        rows, columns, voisins=voisins, etendue_minimale=etendue_minimale, avec_indice=avec_indice
    )
    y = np.log(np.array([row.prix for row in rows], dtype=float))
    sqrt_w = np.sqrt(_weights(rows, center_lat, center_lon, sigma_km))
    beta, _, _, _ = np.linalg.lstsq(design * sqrt_w[:, None], y * sqrt_w, rcond=None)
    return _Model(
        beta=beta,
        columns=columns,
        apprentissage=rows,
        voisins=voisins,
        etendue_minimale=etendue_minimale,
        avec_indice=avec_indice,
    )


def _predict(model: _Model, rows: list[_Row]) -> NDArray[np.float64]:
    """Prédit le **prix** des lignes ; le modèle travaille en logarithme."""
    predictions: NDArray[np.float64] = np.exp(
        _encode(
            rows,
            model.columns,
            model.apprentissage,
            voisins=model.voisins,
            etendue_minimale=model.etendue_minimale,
            avec_indice=model.avec_indice,
        )
        @ model.beta
    )
    return predictions


def fit(
    sample: Iterable[Mutation],
    center_lat: float,
    center_lon: float,
    *,
    sigma_km: float = DEFAULT_SIGMA_KM,
    reference_dpe: str = DEFAULT_REFERENCE_DPE,
    voisins_indice_local: int = VOISINS_INDICE_LOCAL,
    etendue_minimale_indice: float = ETENDUE_MINIMALE_INDICE,
    restreindre_bati_ancien: bool = True,
    effets_fixes_commune: bool = True,
    indice_local: bool = True,
) -> RegressionResult:
    """Estime les coefficients DPE par moindres carrés pondérés (§5.2)."""
    rows = _eligible(sample, restreindre_bati_ancien=restreindre_bati_ancien)
    n = len(rows)
    if n == 0:
        raise InsufficientSampleError("échantillon vide après filtrage d'éligibilité")

    model = _fit_model(
        rows,
        center_lat,
        center_lon,
        sigma_km,
        reference_dpe,
        voisins=voisins_indice_local,
        etendue_minimale=etendue_minimale_indice,
        avec_commune=effets_fixes_commune,
        avec_indice=indice_local,
    )
    # Le R² est mesuré sur l'échelle d'ajustement du modèle : le logarithme.
    y = np.log(np.array([row.prix for row in rows], dtype=float))
    weights = _weights(rows, center_lat, center_lon, sigma_km)

    # Colonnes fixes : const, log(bâti), log1p(terrain), [log(indice)]. Les DPE
    # commencent après, donc leur position dépend de la présence de l'indice.
    dpe_debut = 4 if indice_local else 3
    coef_dpe = {model.columns.reference_dpe: 0.0}
    for offset, level in enumerate(model.columns.dpe):
        coef_dpe[level] = float(model.beta[dpe_debut + offset])

    predictions = np.log(_predict(model, rows))
    weighted_mean = float(np.sum(weights * y) / np.sum(weights))
    ss_res = float(np.sum(weights * (y - predictions) ** 2))
    ss_tot = float(np.sum(weights * (y - weighted_mean) ** 2))
    r2 = 1.0 - ss_res / ss_tot if ss_tot > 0 else 0.0

    return RegressionResult(
        coef_dpe=coef_dpe,
        coef_m2_bati=float(model.beta[1]),
        coef_m2_terrain=float(model.beta[2]),
        n=n,
        r2=r2,
    )

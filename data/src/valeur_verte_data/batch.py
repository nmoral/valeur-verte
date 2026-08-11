"""Pré-calcul des coefficients par maille commune (§10.6, décision D7).

Assemble la chaîne du moteur pour une commune : sélection par rayon adaptatif →
régression pondérée → erreur médiane (validation croisée) → décision de mode.
Le résultat (`MailleCoefficient`) alimente une ligne de ``coefficient_maille`` ;
une requête widget devient alors un simple ``SELECT`` sur index.

Sous le seuil local, on bascule sur un ajustement **départemental** (quasi non
pondéré, grand σ) avec bandeau d'alerte ; si le département est aussi trop
peu peuplé, on refuse (``None``).
"""

from __future__ import annotations

from collections.abc import Iterable, Iterator, Sequence
from dataclasses import dataclass

from .cross_validation import median_relative_error
from .degraded import MIN_LOCAL_SAMPLE, CoverageMode, decide_mode
from .dvf import Mutation
from .radius import MIN_SAMPLE, adaptive_selection
from .regression import DEFAULT_SIGMA_KM, RegressionResult, _eligible, fit

# σ « large » : pour l'ajustement départemental, toutes les ventes comptent ~également.
DEPARTEMENTAL_SIGMA_KM = 1000.0


@dataclass(frozen=True)
class MailleCoefficient:
    """Coefficients pré-calculés d'une commune, prêts pour ``coefficient_maille``."""

    code_insee: str
    millesime: int
    mode: CoverageMode
    rayon_retenu_km: int | None
    n_transactions: int
    r2: float
    erreur_mediane: float | None
    coef_m2_bati: float
    coef_m2_terrain: float
    coef_dpe: dict[str, float]
    ic_dpe: dict[str, tuple[float, float]]
    alerte: str | None


def _intervals(
    coef_dpe: dict[str, float], erreur_mediane: float | None
) -> dict[str, tuple[float, float]]:
    """Intervalle de confiance empirique par classe, calibré sur l'erreur médiane."""
    if erreur_mediane is None:
        return {}
    fraction = erreur_mediane / 100.0
    return {
        classe: (coef - abs(coef) * fraction, coef + abs(coef) * fraction)
        for classe, coef in coef_dpe.items()
    }


def _maille(
    code_insee: str,
    millesime: int,
    mode: CoverageMode,
    rayon_km: int | None,
    result: RegressionResult,
    erreur: float | None,
    alerte: str | None,
) -> MailleCoefficient:
    return MailleCoefficient(
        code_insee=code_insee,
        millesime=millesime,
        mode=mode,
        rayon_retenu_km=rayon_km,
        n_transactions=result.n,
        r2=result.r2,
        erreur_mediane=erreur,
        coef_m2_bati=result.coef_m2_bati,
        coef_m2_terrain=result.coef_m2_terrain,
        coef_dpe=result.coef_dpe,
        ic_dpe=_intervals(result.coef_dpe, erreur),
        alerte=alerte,
    )


def compute_maille(
    code_insee: str,
    centroid_lat: float,
    centroid_lon: float,
    millesime: int,
    pool: Sequence[Mutation],
    *,
    seuil: int = MIN_LOCAL_SAMPLE,
    min_sample: int = MIN_SAMPLE,
    sigma_km: float = DEFAULT_SIGMA_KM,
) -> MailleCoefficient | None:
    """Calcule la ligne ``coefficient_maille`` d'une commune, ou ``None`` (refus)."""
    selection = adaptive_selection(centroid_lat, centroid_lon, pool, min_sample=min_sample)
    n_local = len(_eligible(selection.sample))
    n_departement = len(_eligible(pool))
    decision = decide_mode(n_local, n_departement, seuil=seuil)

    if decision.mode is CoverageMode.REFUS:
        return None

    if decision.mode is CoverageMode.LOCAL:
        sample: Sequence[Mutation] = selection.sample
        rayon_km: int | None = selection.rayon_km
        fit_sigma = sigma_km
    else:
        sample = pool
        rayon_km = None
        fit_sigma = DEPARTEMENTAL_SIGMA_KM

    result = fit(sample, centroid_lat, centroid_lon, sigma_km=fit_sigma)
    erreur = median_relative_error(sample, centroid_lat, centroid_lon, sigma_km=fit_sigma)
    return _maille(code_insee, millesime, decision.mode, rayon_km, result, erreur, decision.alerte)


def compute_communes(
    communes: Iterable[tuple[str, float, float]],
    pool: Sequence[Mutation],
    millesime: int,
    *,
    seuil: int = MIN_LOCAL_SAMPLE,
    min_sample: int = MIN_SAMPLE,
    sigma_km: float = DEFAULT_SIGMA_KM,
) -> Iterator[MailleCoefficient]:
    """Calcule les mailles de plusieurs communes sur un pool chargé une seule fois.

    Motif §10.6 : on charge les ventes du département (+ limitrophes) une fois en
    mémoire, puis on itère les communes. Les communes en refus sont omises.
    """
    for code_insee, lat, lon in communes:
        maille = compute_maille(
            code_insee,
            lat,
            lon,
            millesime,
            pool,
            seuil=seuil,
            min_sample=min_sample,
            sigma_km=sigma_km,
        )
        if maille is not None:
            yield maille

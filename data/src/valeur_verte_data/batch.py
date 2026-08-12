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

from collections import Counter
from collections.abc import Iterable, Iterator, Sequence
from dataclasses import dataclass, field

from .config import DEFAULT_CONFIG, CalculConfig
from .cross_validation import median_relative_error
from .degraded import CoverageMode, decide_mode
from .dvf import Mutation
from .radius import RadiusSelection, adaptive_selection
from .regression import RegressionResult, _eligible, fit
from .trimming import ecreter_residu

# σ « large » : pour l'ajustement départemental, toutes les ventes comptent ~également.
DEPARTEMENTAL_SIGMA_KM = 1000.0

# Effectif minimal d'une classe DPE pour que son coefficient soit publié (#115).
# En deçà, le coefficient est extrapolé sur quelques ventes sans que l'intervalle
# de confiance — calibré sur l'erreur médiane de la maille — ne le signale.
MIN_CLASS_SAMPLE = 15

# Une maille doit porter la référence *et* au moins une autre classe : sinon
# aucun écart de valeur n'est calculable et le coefficient ne dit rien.
MIN_CLASSES_PUBLIEES = 2


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
    # Effectif observé par classe DPE publiée (INV-1, #119) : porte la tolérance
    # de monotonie. Défaut vide pour les mailles construites hors du moteur (tests).
    effectifs_dpe: dict[str, int] = field(default_factory=dict)


def _meme_departement(code_insee: str, pool: Sequence[Mutation]) -> list[Mutation]:
    """Ventes du département de cette commune, le pool débordant sur les voisins (#18).

    Le préfixe à deux caractères suffit en métropole, seul territoire couvert par
    le MVP ; l'outre-mer (codes en 97x) demandera trois caractères.
    """
    departement = code_insee[:2]
    return [mutation for mutation in pool if mutation.code_insee[:2] == departement]


def _effectifs_dpe(
    sample: Sequence[Mutation], *, restreindre_bati_ancien: bool = True
) -> Counter[str]:
    """Effectif observé par classe DPE dans l'échantillon éligible (§5.2)."""
    return Counter(
        row.dpe for row in _eligible(sample, restreindre_bati_ancien=restreindre_bati_ancien)
    )


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
    publiables: set[str] | None = None,
    effectifs: Counter[str] | None = None,
) -> MailleCoefficient:
    coef_dpe = (
        result.coef_dpe
        if publiables is None
        else {classe: coef for classe, coef in result.coef_dpe.items() if classe in publiables}
    )
    effectifs_dpe = {} if effectifs is None else {classe: effectifs[classe] for classe in coef_dpe}
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
        coef_dpe=coef_dpe,
        ic_dpe=_intervals(coef_dpe, erreur),
        alerte=alerte,
        effectifs_dpe=effectifs_dpe,
    )


def _sigma_local(config: CalculConfig, selection: RadiusSelection) -> float:
    """σ de pondération en mode local selon ``sigma_mode`` (S23), borné par ``sigma_min``.

    - ``rayon`` : σ = rayon retenu / diviseur (proxy quantifié de densité).
    - ``densite`` : σ = distance à la min_sample-ième vente / diviseur (densité
      continue, distingue une ville très dense d'une ville dense).
    - sinon : σ fixe.
    """
    if config.sigma_mode == "rayon":
        sigma = selection.rayon_km / config.sigma_diviseur
    elif config.sigma_mode == "densite":
        distance = selection.distance_min_sample
        sigma = distance / config.sigma_diviseur if distance > 0 else config.sigma_km
    else:
        return config.sigma_km
    return max(sigma, config.sigma_min)


def compute_maille(
    code_insee: str,
    centroid_lat: float,
    centroid_lon: float,
    millesime: int,
    pool: Sequence[Mutation],
    *,
    config: CalculConfig = DEFAULT_CONFIG,
) -> MailleCoefficient | None:
    """Calcule la ligne ``coefficient_maille`` d'une commune, ou ``None`` (refus)."""
    # INV-5 (#123) : l'ordre du pool n'est pas garanti (SELECT sans ORDER BY),
    # or il pilote l'affectation des plis de CV et l'ordre de sommation OLS.
    # On fige un ordre canonique pour que le résultat ne dépende que du jeu.
    pool = sorted(pool, key=lambda mutation: mutation.id_mutation)
    selection = adaptive_selection(
        centroid_lat, centroid_lon, pool, min_sample=config.min_sample, radii=config.radii_km
    )
    departemental = _meme_departement(code_insee, pool)
    n_local = len(
        _eligible(selection.sample, restreindre_bati_ancien=config.restreindre_bati_ancien)
    )
    n_departement = len(
        _eligible(departemental, restreindre_bati_ancien=config.restreindre_bati_ancien)
    )
    decision = decide_mode(n_local, n_departement, seuil=config.min_local_sample)

    if decision.mode is CoverageMode.REFUS:
        return None

    if decision.mode is CoverageMode.LOCAL:
        sample: Sequence[Mutation] = selection.sample
        rayon_km: int | None = selection.rayon_km
        fit_sigma = _sigma_local(config, selection)
    else:
        sample = departemental
        rayon_km = None
        fit_sigma = config.departemental_sigma_km

    if config.ecretage == "residu":
        sample = ecreter_residu(
            sample,
            centroid_lat,
            centroid_lon,
            k=config.ecretage_k,
            seuil_n=config.ecretage_seuil_n,
            sigma_km=fit_sigma,
            reference_dpe=config.reference_dpe,
            voisins_indice_local=config.voisins_indice_local,
            etendue_minimale_indice=config.etendue_minimale_indice,
            restreindre_bati_ancien=config.restreindre_bati_ancien,
        )

    effectifs = _effectifs_dpe(sample, restreindre_bati_ancien=config.restreindre_bati_ancien)
    publiables = {
        classe for classe, effectif in effectifs.items() if effectif >= config.min_class_sample
    }
    if len(publiables) < config.min_classes_publiees:
        return None

    result = fit(
        sample,
        centroid_lat,
        centroid_lon,
        sigma_km=fit_sigma,
        reference_dpe=config.reference_dpe,
        voisins_indice_local=config.voisins_indice_local,
        etendue_minimale_indice=config.etendue_minimale_indice,
        restreindre_bati_ancien=config.restreindre_bati_ancien,
        effets_fixes_commune=config.effets_fixes_commune,
        indice_local=config.indice_local,
    )
    erreur = median_relative_error(
        sample,
        centroid_lat,
        centroid_lon,
        folds=config.cv_folds,
        sigma_km=fit_sigma,
        reference_dpe=config.reference_dpe,
        voisins_indice_local=config.voisins_indice_local,
        etendue_minimale_indice=config.etendue_minimale_indice,
        restreindre_bati_ancien=config.restreindre_bati_ancien,
        effets_fixes_commune=config.effets_fixes_commune,
        indice_local=config.indice_local,
    )
    return _maille(
        code_insee,
        millesime,
        decision.mode,
        rayon_km,
        result,
        erreur,
        decision.alerte,
        publiables,
        effectifs,
    )


def compute_communes(
    communes: Iterable[tuple[str, float, float]],
    pool: Sequence[Mutation],
    millesime: int,
    *,
    config: CalculConfig = DEFAULT_CONFIG,
) -> Iterator[MailleCoefficient]:
    """Calcule les mailles de plusieurs communes sur un pool chargé une seule fois.

    Motif §10.6 : on charge les ventes du département (+ limitrophes) une fois en
    mémoire, puis on itère les communes. Les communes en refus sont omises.
    """
    for code_insee, lat, lon in communes:
        maille = compute_maille(code_insee, lat, lon, millesime, pool, config=config)
        if maille is not None:
            yield maille

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
from dataclasses import dataclass, field, replace

from .bandes import bandes_monotones
from .config import DEFAULT_CONFIG, CalculConfig
from .cross_validation import median_relative_error
from .degraded import CoverageMode, PalierFiabilite, decide_mode, palier_erreur
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
    # Palier de fiabilité (#169) : STANDARD (erreur < seuil standard) ou DEGRADE
    # (erreur entre seuil standard et seuil dégradé, servi avec bandeau côté
    # restitution). Premier maillon de la cascade — le tier ouvre le LOCAL aux
    # erreurs 20-30 % au lieu du refus sec. Défaut STANDARD pour les mailles
    # construites hors du moteur (tests).
    palier: PalierFiabilite = PalierFiabilite.STANDARD
    # Mapping classe DPE → représentant de bande (#169), pour les crans EPCI/DÉPT
    # où des classes adjacentes ont été fusionnées (ex. ``{"F": "F", "G": "F"}``).
    # **Vide au cran LOCAL** (classes pleines). Indispensable à la restitution : sans
    # lui, une maison d'une classe fusionnée (G) n'aurait aucun coefficient servable.
    bandes: dict[str, str] = field(default_factory=dict)
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


def _centroide_moyen(sample: Sequence[Mutation]) -> tuple[float, float]:
    """Barycentre de l'échantillon (moyenne lat/lon des ventes géolocalisées) — #175.

    Aux crans EPCI/DÉPARTEMENT la pondération est **uniforme** (σ départemental) :
    le résultat est indépendant du centre choisi. On prend donc le barycentre des
    **données** plutôt que le centroïde de la commune. C'est ce qui rend le calcul
    identique pour toutes les communes du périmètre — déterministe (INV-5, sans
    dépendre de l'ordre d'itération) et donc mémoïsable (un seul calcul réutilisé).
    """
    points = [
        (mutation.latitude, mutation.longitude)
        for mutation in sample
        if mutation.latitude is not None and mutation.longitude is not None
    ]
    if not points:
        return 0.0, 0.0
    return (
        sum(lat for lat, _ in points) / len(points),
        sum(lon for _, lon in points) / len(points),
    )


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
    palier: PalierFiabilite,
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
        palier=palier,
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


def _compute_from_sample(
    code_insee: str,
    centroid_lat: float,
    centroid_lon: float,
    millesime: int,
    sample: Sequence[Mutation],
    *,
    mode: CoverageMode,
    rayon_km: int | None,
    fit_sigma: float,
    alerte: str | None,
    config: CalculConfig,
) -> MailleCoefficient | None:
    """Chaîne commune post-sélection, partagée par tous les crans de la cascade.

    Une fois l'échantillon et la pondération (``fit_sigma``) choisis — cran LOCAL,
    EPCI ou DÉPARTEMENT — le traitement est identique : écrêtage → gate de
    publication par classe → régression → erreur médiane → palier de fiabilité.
    Renvoie ``None`` si moins de ``min_classes_publiees`` classes tiennent le seuil.
    L'appelant fournit un ``sample`` déjà en ordre canonique (INV-5).
    """
    # #208 : la pondération temporelle décroissante s'applique à l'estimateur
    # publié (fit) et à son erreur mesurée (CV), pas à l'écrêtage — un outlier
    # ancien reste un outlier ; la détection de points aberrants est un pré-filtre
    # géométrique/robuste indépendant de l'emphase de récence.
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
        decroissance_temporelle_tau=config.decroissance_temporelle_tau,
        annee_reference=millesime,
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
        decroissance_temporelle_tau=config.decroissance_temporelle_tau,
        annee_reference=millesime,
    )
    palier = palier_erreur(
        erreur,
        seuil_standard=config.erreur_mediane_max_pct,
        seuil_degrade=config.erreur_mediane_max_pct_degrade,
    )
    return _maille(
        code_insee,
        millesime,
        mode,
        rayon_km,
        result,
        erreur,
        alerte,
        palier,
        publiables,
        effectifs,
    )


def _maille_departementale(
    code_insee: str,
    millesime: int,
    echantillon: Sequence[Mutation],
    alerte: str | None,
    config: CalculConfig,
) -> MailleCoefficient | None:
    """Maille du mode **DÉPARTEMENTAL dégradé** (#22) — fonction pure des ventes du dept.

    Pondération uniforme (σ départemental) centrée sur le **barycentre** de l'échantillon
    (#200, `_centroide_moyen`) : le résultat ne dépend ni de la commune ni de l'ordre
    d'itération (INV-5). Il est donc **identique pour toutes les communes du département**
    et peut être calculé une seule fois puis réutilisé (mémoïsation côté `compute_maille`
    / `compute_communes`), seul `code_insee` variant d'une ligne à l'autre.
    """
    centroid_lat, centroid_lon = _centroide_moyen(echantillon)
    return _compute_from_sample(
        code_insee,
        centroid_lat,
        centroid_lon,
        millesime,
        echantillon,
        mode=CoverageMode.DEPARTEMENTAL,
        rayon_km=None,
        fit_sigma=config.departemental_sigma_km,
        alerte=alerte,
        config=config,
    )


def compute_maille(
    code_insee: str,
    centroid_lat: float,
    centroid_lon: float,
    millesime: int,
    pool: Sequence[Mutation],
    *,
    config: CalculConfig = DEFAULT_CONFIG,
    memo_departemental: dict[str, MailleCoefficient | None] | None = None,
) -> MailleCoefficient | None:
    """Calcule la ligne ``coefficient_maille`` d'une commune, ou ``None`` (refus).

    ``memo_departemental`` (#200) mémoïse la régression du mode DÉPARTEMENTAL par
    département sur la durée d'un batch : identique pour toutes les communes du dept,
    elle n'est calculée qu'une fois puis réutilisée (seul ``code_insee`` change). Le
    mode LOCAL reste par commune (voisinage propre).
    """
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
        return _compute_from_sample(
            code_insee,
            centroid_lat,
            centroid_lon,
            millesime,
            selection.sample,
            mode=CoverageMode.LOCAL,
            rayon_km=selection.rayon_km,
            fit_sigma=_sigma_local(config, selection),
            alerte=decision.alerte,
            config=config,
        )

    # Mode DÉPARTEMENTAL dégradé (#22) : mémoïsé par département (#200).
    departement = code_insee[:2]
    if memo_departemental is not None and departement in memo_departemental:
        base = memo_departemental[departement]
    else:
        base = _maille_departementale(code_insee, millesime, departemental, decision.alerte, config)
        if memo_departemental is not None:
            memo_departemental[departement] = base
    return None if base is None else replace(base, code_insee=code_insee)


def _maille_par_bandes(
    code_insee: str,
    centroid_lat: float,
    centroid_lon: float,
    millesime: int,
    sample: Sequence[Mutation],
    *,
    mode: CoverageMode,
    sigma: float,
    config: CalculConfig,
) -> MailleCoefficient | None:
    """Cœur commun des crans **EPCI** et **DÉPARTEMENT** de la cascade (#169).

    Applique les **bandes DPE monotones** (fusion des classes adjacentes
    sous-effectif + anti-inversion, ``bandes_monotones``) à l'échantillon fourni —
    déjà filtré et trié par l'appelant — puis relabellise et enchaîne la chaîne
    standard à pondération uniforme. ``rayon=None`` (cran non local), ``alerte=None``
    (le ``mode`` porte le signal de granularité). ``None`` si moins de deux bandes.
    """
    bandes = bandes_monotones(sample, centroid_lat, centroid_lon, sigma, config)
    if bandes is None:
        return None
    releve = [
        replace(mutation, etiquette_dpe=bandes[mutation.etiquette_dpe])
        if mutation.etiquette_dpe in bandes
        else mutation
        for mutation in sample
    ]
    maille = _compute_from_sample(
        code_insee,
        centroid_lat,
        centroid_lon,
        millesime,
        releve,
        mode=mode,
        rayon_km=None,
        fit_sigma=sigma,
        alerte=None,
        config=config,
    )
    # Le mapping des bandes voyage avec la maille : la restitution en a besoin pour
    # relier une classe fusionnée (G) au coefficient de son représentant (F).
    return maille if maille is None else replace(maille, bandes=bandes)


def compute_maille_epci(
    code_insee: str,
    millesime: int,
    communes_epci: Sequence[str],
    pool: Sequence[Mutation],
    *,
    config: CalculConfig = DEFAULT_CONFIG,
) -> MailleCoefficient | None:
    """Calcule la maille d'une commune au cran EPCI de la cascade (#169).

    Cran intermédiaire entre le local et le département : on poole les ventes des
    communes de ``communes_epci`` (fournies par l'appelant depuis le référentiel
    ``commune_epci``) en **une régression par EPCI**, à pondération uniforme (σ
    départemental) — tous les biens de l'EPCI comptent également, la géographie
    fine cède la place à un échantillon plus fourni. ``mode=EPCI``, sans rayon.

    Le centre de pondération est le **barycentre des ventes de l'EPCI** (#175) : à
    σ départemental la pondération est uniforme, donc le résultat ne dépend pas de
    la commune — il est **identique pour toutes les communes de l'EPCI** et peut
    être calculé une seule fois puis réutilisé (mémoïsation côté orchestration).

    Les classes DPE adjacentes sous-effectif sont fusionnées en bandes adaptatives
    (le pool plus large garde un DPE plus fin que le département, sans exiger deux
    classes pleines). ``None`` si moins de deux bandes (le département prend le relais).
    """
    membres = set(communes_epci)
    sample = sorted(
        (mutation for mutation in pool if mutation.code_insee in membres),
        key=lambda mutation: mutation.id_mutation,
    )
    centroid_lat, centroid_lon = _centroide_moyen(sample)
    return _maille_par_bandes(
        code_insee,
        centroid_lat,
        centroid_lon,
        millesime,
        sample,
        mode=CoverageMode.EPCI,
        sigma=config.departemental_sigma_km,
        config=config,
    )


def compute_maille_departement(
    code_insee: str,
    millesime: int,
    pool: Sequence[Mutation],
    *,
    config: CalculConfig = DEFAULT_CONFIG,
) -> MailleCoefficient | None:
    """Calcule la maille d'une commune au cran DÉPARTEMENT (#169) — dernier filet.

    Poole les ventes du **seul département** de la commune (``_meme_departement`` :
    le débordement sur les voisins nourrit le calcul *local*, pas ce repli — #91), à
    pondération uniforme (σ départemental), avec bandes DPE adaptatives +
    anti-inversion. ``mode=DEPARTEMENTAL``, sans rayon. Renvoie ``None`` si moins de
    deux bandes subsistent (la commune sort alors hors zone).

    Le centre de pondération est le **barycentre des ventes du département** (#175) :
    à σ départemental la maille est identique pour toutes les communes du département,
    donc calculable une seule fois puis réutilisée (mémoïsation côté orchestration),
    seul ``code_insee`` variant d'une ligne à l'autre.
    """
    sample = sorted(
        _meme_departement(code_insee, pool),
        key=lambda mutation: mutation.id_mutation,
    )
    centroid_lat, centroid_lon = _centroide_moyen(sample)
    return _maille_par_bandes(
        code_insee,
        centroid_lat,
        centroid_lon,
        millesime,
        sample,
        mode=CoverageMode.DEPARTEMENTAL,
        sigma=config.departemental_sigma_km,
        config=config,
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

    La régression du mode DÉPARTEMENTAL est **mémoïsée par département** (#200) : elle
    est identique pour toutes les communes du dept, donc calculée une seule fois.
    """
    memo_departemental: dict[str, MailleCoefficient | None] = {}
    for code_insee, lat, lon in communes:
        maille = compute_maille(
            code_insee,
            lat,
            lon,
            millesime,
            pool,
            config=config,
            memo_departemental=memo_departemental,
        )
        if maille is not None:
            yield maille

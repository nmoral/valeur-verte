"""Configuration de calcul du moteur, résolue depuis l'environnement.

Toutes les constantes réglables du moteur (seuils d'échantillon, σ de pondération,
bornes de nettoyage, segment de biens) sont centralisées ici et surchargeables par
variable d'environnement ``VV_MOTEUR_*``. Même motif que ``resolve_dsn`` : l'env
est **injecté** (un ``Mapping``), donc la config est testable hors-ligne et se
règle par expérience sans modifier le code. Sans variable, les valeurs par défaut
reproduisent exactement le comportement historique du pipeline.
"""

from __future__ import annotations

from collections.abc import Mapping
from dataclasses import dataclass

_PREFIX = "VV_MOTEUR_"


@dataclass(frozen=True)
class CalculConfig:
    """Constantes de calcul du moteur.

    Les défauts sont la **config gagnante** validée sur le 31 (voir
    ``docs/lab/RECAP.md``) : σ piloté par la densité (S23), écrêtage sur résidu
    (S32), bâti récent inclus (S20), sans effets fixes commune (S22), seuil de
    classe à 20 (S21). Surchargeables par ``VV_MOTEUR_*``.
    """

    radii_km: tuple[int, ...] = (10, 15, 20, 30, 50)
    # INV-3 (#121) : plafond du rayon retenu (hors repli départemental). Source
    # unique configurable — une maille dont le rayon dépasse ce plafond n'est
    # pas publiée (garde défensive contre un palier ``radii_km`` mal configuré).
    rayon_max_km: int = 50
    # INV-3 (#121) : rapport maximal de coef_m2_bati toléré entre deux communes
    # limitrophes. Au-delà, artefact de bord probable (§3.2). Borne stricte.
    rupture_facteur_max: float = 2.0
    min_sample: int = 80
    min_local_sample: int = 30
    min_class_sample: int = 20  # S21
    min_classes_publiees: int = 2
    sigma_km: float = 10.0  # utilisé seulement si sigma_mode="fixe"
    departemental_sigma_km: float = 1000.0
    # σ en mode local (S23) : "fixe" (σ ci-dessus) | "rayon" (σ = rayon_retenu /
    # diviseur, quantifié) | "densite" (σ = distance à la min_sample-ième vente /
    # diviseur — mesure de densité continue, distingue Paris de Toulouse).
    sigma_mode: str = "densite"  # S23
    sigma_diviseur: float = 1.0
    sigma_min: float = 3.0  # plancher de σ : protège les mailles fragiles d'un σ trop serré.
    reference_dpe: str = "D"
    voisins_indice_local: int = 20
    etendue_minimale_indice: float = 0.002
    # Effets fixes commune dans la régression (S22) : retirés par défaut, ils
    # sur-ajustaient (indice local + pondération portent déjà la localité).
    effets_fixes_commune: bool = False  # S22
    # Indice local de quartier comme régresseur (S24) : gardé (signal réel).
    indice_local: bool = True  # S24
    cv_folds: int = 5
    # INV-4 (#122) : seuils de publication d'une maille. n minimal = min_local_sample
    # (seuil #22, source unique). Ces bornes sont configurables, pas codées en dur.
    erreur_mediane_max_pct: float = 20.0
    r2_min: float = 0.30
    price_min: float = 30_000.0
    price_max: float = 900_000.0
    bati_min: float = 50.0
    bati_max: float = 250.0
    terrain_min: float = 50.0
    terrain_max: float = 5_000.0
    segment: str = "Maison"
    restreindre_bati_ancien: bool = False  # S20
    # Écrêtage de l'échantillon au calcul (S32) : "off" | "residu".
    ecretage: str = "residu"  # S32 V2
    ecretage_seuil_n: int = 100
    ecretage_k: float = 3.0  # seuil en nombre de MAD sur le résidu pondéré.


# Config historique par défaut, partagée comme valeur par défaut des signatures :
# ``CalculConfig`` est gelée, une instance unique est donc sûre (et évite un appel
# dans les arguments par défaut, cf. flake8-bugbear B008).
DEFAULT_CONFIG = CalculConfig()


def resolve_config(env: Mapping[str, str]) -> CalculConfig:
    """Construit la config depuis l'environnement, défaut historique si absent/vide."""
    defaut = CalculConfig()
    return CalculConfig(
        radii_km=_tuple(env, "RADII_KM", defaut.radii_km),
        rayon_max_km=_int(env, "RAYON_MAX_KM", defaut.rayon_max_km),
        rupture_facteur_max=_float(env, "RUPTURE_FACTEUR_MAX", defaut.rupture_facteur_max),
        min_sample=_int(env, "MIN_SAMPLE", defaut.min_sample),
        min_local_sample=_int(env, "MIN_LOCAL_SAMPLE", defaut.min_local_sample),
        min_class_sample=_int(env, "MIN_CLASS_SAMPLE", defaut.min_class_sample),
        min_classes_publiees=_int(env, "MIN_CLASSES_PUBLIEES", defaut.min_classes_publiees),
        sigma_km=_float(env, "SIGMA_KM", defaut.sigma_km),
        departemental_sigma_km=_float(env, "DEPARTEMENTAL_SIGMA_KM", defaut.departemental_sigma_km),
        sigma_mode=_str(env, "SIGMA_MODE", defaut.sigma_mode),
        sigma_diviseur=_float(env, "SIGMA_DIVISEUR", defaut.sigma_diviseur),
        sigma_min=_float(env, "SIGMA_MIN", defaut.sigma_min),
        reference_dpe=_str(env, "REFERENCE_DPE", defaut.reference_dpe),
        voisins_indice_local=_int(env, "VOISINS_INDICE_LOCAL", defaut.voisins_indice_local),
        etendue_minimale_indice=_float(
            env, "ETENDUE_MINIMALE_INDICE", defaut.etendue_minimale_indice
        ),
        effets_fixes_commune=_bool(env, "EFFETS_FIXES_COMMUNE", defaut.effets_fixes_commune),
        indice_local=_bool(env, "INDICE_LOCAL", defaut.indice_local),
        cv_folds=_int(env, "CV_FOLDS", defaut.cv_folds),
        erreur_mediane_max_pct=_float(env, "ERREUR_MEDIANE_MAX_PCT", defaut.erreur_mediane_max_pct),
        r2_min=_float(env, "R2_MIN", defaut.r2_min),
        price_min=_float(env, "PRICE_MIN", defaut.price_min),
        price_max=_float(env, "PRICE_MAX", defaut.price_max),
        bati_min=_float(env, "BATI_MIN", defaut.bati_min),
        bati_max=_float(env, "BATI_MAX", defaut.bati_max),
        terrain_min=_float(env, "TERRAIN_MIN", defaut.terrain_min),
        terrain_max=_float(env, "TERRAIN_MAX", defaut.terrain_max),
        segment=_str(env, "SEGMENT", defaut.segment),
        restreindre_bati_ancien=_bool(
            env, "RESTREINDRE_BATI_ANCIEN", defaut.restreindre_bati_ancien
        ),
        ecretage=_str(env, "ECRETAGE", defaut.ecretage),
        ecretage_seuil_n=_int(env, "ECRETAGE_SEUIL_N", defaut.ecretage_seuil_n),
        ecretage_k=_float(env, "ECRETAGE_K", defaut.ecretage_k),
    )


def _raw(env: Mapping[str, str], name: str) -> str | None:
    """Valeur brute de ``VV_MOTEUR_<name>``, ou ``None`` si absente ou vide."""
    value = env.get(_PREFIX + name)
    if value is None:
        return None
    stripped = value.strip()
    return stripped or None


def _int(env: Mapping[str, str], name: str, default: int) -> int:
    raw = _raw(env, name)
    return default if raw is None else int(raw)


def _float(env: Mapping[str, str], name: str, default: float) -> float:
    raw = _raw(env, name)
    return default if raw is None else float(raw)


def _str(env: Mapping[str, str], name: str, default: str) -> str:
    raw = _raw(env, name)
    return default if raw is None else raw


def _bool(env: Mapping[str, str], name: str, default: bool) -> bool:
    raw = _raw(env, name)
    if raw is None:
        return default
    return raw.lower() not in {"false", "0", "no", "non", "off"}


def _tuple(env: Mapping[str, str], name: str, default: tuple[int, ...]) -> tuple[int, ...]:
    raw = _raw(env, name)
    if raw is None:
        return default
    return tuple(int(part.strip()) for part in raw.split(",") if part.strip())

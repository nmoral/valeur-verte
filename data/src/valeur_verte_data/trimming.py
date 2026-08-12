"""Écrêtage de l'échantillon au calcul (S32) — troncature robuste sur résidu.

Retire de l'échantillon *réellement utilisé* pour une maille les biens au **résidu
pondéré** aberrant : ceux que la régression explique le plus mal vu leurs
caractéristiques (surface, DPE, période, quartier). Impact faible sur la taille,
potentiellement décisif sur les coefficients — ce sont les points à fort levier
qui déstabilisent l'ajustement. N'agit qu'au-dessus d'un seuil d'effectif.
"""

from __future__ import annotations

from collections.abc import Sequence

import numpy as np

from .dvf import Mutation
from .regression import (
    DEFAULT_REFERENCE_DPE,
    DEFAULT_SIGMA_KM,
    ETENDUE_MINIMALE_INDICE,
    VOISINS_INDICE_LOCAL,
    _eligible_avec_source,
    _fit_model,
    _predict,
    _weights,
)

_MAD_A_ECART_TYPE = 1.4826  # rend la MAD comparable à un écart-type sous loi normale.


def ecreter_residu(
    sample: Sequence[Mutation],
    center_lat: float,
    center_lon: float,
    *,
    k: float = 3.0,
    seuil_n: int = 100,
    sigma_km: float = DEFAULT_SIGMA_KM,
    reference_dpe: str = DEFAULT_REFERENCE_DPE,
    voisins_indice_local: int = VOISINS_INDICE_LOCAL,
    etendue_minimale_indice: float = ETENDUE_MINIMALE_INDICE,
    restreindre_bati_ancien: bool = True,
) -> list[Mutation]:
    """Écrêtage V2 : retire les biens au **résidu pondéré** aberrant (|z| > k), si n ≥ seuil.

    Un premier ajustement donne, pour chaque vente, l'écart en log entre son prix
    et celui prédit vu ses caractéristiques (surface, DPE, période, quartier). On
    pondère ce résidu par le poids gaussien — cibler le point mal ajusté **et**
    influent (proche), pas le lointain déjà négligeable — et on le normalise par la
    MAD (robuste au masquage). Au-delà de ``k`` MAD, la vente est retirée.
    """
    rows, sources = _eligible_avec_source(sample, restreindre_bati_ancien=restreindre_bati_ancien)
    if len(rows) < seuil_n:
        return list(sample)

    model = _fit_model(
        rows,
        center_lat,
        center_lon,
        sigma_km,
        reference_dpe,
        voisins=voisins_indice_local,
        etendue_minimale=etendue_minimale_indice,
    )
    y = np.log(np.array([row.prix for row in rows], dtype=float))
    residus = y - np.log(_predict(model, rows))
    poids = _weights(rows, center_lat, center_lon, sigma_km)
    residus_ponderes = np.sqrt(poids) * residus

    mediane = float(np.median(residus_ponderes))
    mad = _MAD_A_ECART_TYPE * float(np.median(np.abs(residus_ponderes - mediane)))
    if mad == 0.0:
        return list(sample)

    z = (residus_ponderes - mediane) / mad
    exclus = {id(sources[i]) for i in range(len(sources)) if abs(z[i]) > k}
    if not exclus:
        return list(sample)
    return [mutation for mutation in sample if id(mutation) not in exclus]

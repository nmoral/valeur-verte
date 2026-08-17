"""REG-3 (#56) — garde de couverture des profils.

Le jeu de cas de référence n'a de valeur que s'il **éprouve les quatre régimes de
publication** : solide, correcte, fragile, repli départemental (articulation
#122/INV-4 — un cas « fragile » doit exister pour vérifier qu'il bascule bien en
tier dégradé). Le repli **EPCI** est un bonus, pas une exigence. Fonction pure :
liste de cas → profils requis absents.
"""

from __future__ import annotations

from valeur_verte_data.degraded import CoverageMode, PalierFiabilite
from valeur_verte_data.reg3 import (
    PROFILS_REQUIS,
    CasReference,
    Profil,
    profils_manquants,
)


def _cas(profil: Profil) -> CasReference:
    """Cas minimal — seul le ``profil`` compte pour la couverture."""
    return CasReference(
        code_insee="31000",
        profil=profil,
        mode_attendu=CoverageMode.LOCAL,
        palier_attendu=PalierFiabilite.STANDARD,
        n_min=30,
        erreur_max_pct=20.0,
        gain_ec_pct=(10.0, 25.0),
    )


_QUATRE_REQUIS = (
    Profil.SOLIDE,
    Profil.CORRECTE,
    Profil.FRAGILE,
    Profil.REPLI_DEPARTEMENTAL,
)


def test_jeu_couvrant_les_quatre_profils_requis_ne_manque_rien() -> None:
    assert profils_manquants([_cas(p) for p in _QUATRE_REQUIS]) == frozenset()


def test_un_profil_requis_absent_est_signale() -> None:
    cas = [_cas(Profil.SOLIDE), _cas(Profil.CORRECTE), _cas(Profil.REPLI_DEPARTEMENTAL)]
    assert profils_manquants(cas) == frozenset({Profil.FRAGILE})


def test_plusieurs_profils_requis_absents_sont_tous_signales() -> None:
    assert profils_manquants([_cas(Profil.SOLIDE)]) == frozenset(
        {Profil.CORRECTE, Profil.FRAGILE, Profil.REPLI_DEPARTEMENTAL}
    )


def test_jeu_vide_manque_les_quatre_profils() -> None:
    assert profils_manquants([]) == PROFILS_REQUIS


def test_le_repli_epci_n_est_pas_requis() -> None:
    # Les 4 requis présents, aucun repli EPCI → rien ne manque (EPCI est un bonus).
    assert Profil.REPLI_EPCI not in PROFILS_REQUIS
    assert profils_manquants([_cas(p) for p in _QUATRE_REQUIS]) == frozenset()


def test_un_repli_epci_ne_compense_pas_un_profil_requis_absent() -> None:
    cas = [
        _cas(Profil.SOLIDE),
        _cas(Profil.CORRECTE),
        _cas(Profil.REPLI_DEPARTEMENTAL),
        _cas(Profil.REPLI_EPCI),  # bonus, ne remplace pas FRAGILE
    ]
    assert profils_manquants(cas) == frozenset({Profil.FRAGILE})


def test_les_doublons_ne_faussent_pas_la_couverture() -> None:
    # Plusieurs cas d'un même profil comptent comme une seule couverture.
    cas = [
        _cas(Profil.SOLIDE),
        _cas(Profil.SOLIDE),
        _cas(Profil.CORRECTE),
        _cas(Profil.FRAGILE),
        _cas(Profil.REPLI_DEPARTEMENTAL),
    ]
    assert profils_manquants(cas) == frozenset()

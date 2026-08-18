"""Exclusion des mutations multi-communes du pool moteur.

Une vente DVF à cheval sur plusieurs communes est ingérée en **une ligne par
commune** (ingestion par commune), chacune portant le **prix total** de la vente
mais la **surface de sa seule commune** : le rapport prix/surface est faux. On
écarte donc toutes les copies d'une même (id_mutation, millesime) présente sous
plus d'une commune, avant la régression. Détecté au niveau du pool (les communes
d'une même vente sont adjacentes, donc toutes présentes — dept + limitrophes).
"""

from __future__ import annotations

from dataclasses import replace

from valeur_verte_data.dvf import Mutation, aggregate_mutations
from valeur_verte_data.multi_communes import filtrer_multi_communes


def _vente(id_mutation: str, code_insee: str, *, bati: str = "80") -> Mutation:
    (mutation,) = aggregate_mutations(
        [
            {
                "id_mutation": id_mutation,
                "valeur_fonciere": "200000",
                "code_commune": code_insee,
                "surface_reelle_bati": bati,
            }
        ],
        millesime=2024,
    )
    return mutation


def test_ecarte_une_mutation_repartie_sur_deux_communes() -> None:
    pool = [_vente("m1", "31555"), _vente("m1", "31149", bati="120")]

    assert filtrer_multi_communes(pool) == []


def test_conserve_une_mutation_mono_commune() -> None:
    pool = [_vente("m1", "31555"), _vente("m2", "31555")]

    assert len(filtrer_multi_communes(pool)) == 2


def test_ecarte_toutes_les_copies_et_garde_les_mono_communes() -> None:
    pool = [_vente("multi", "31555"), _vente("solo", "31555"), _vente("multi", "31149")]

    restant = filtrer_multi_communes(pool)

    assert [m.id_mutation for m in restant] == ["solo"]


def test_preserve_l_ordre_du_pool() -> None:
    # INV-5 : le filtre ne réordonne pas le pool.
    pool = [_vente("b", "31555"), _vente("a", "31555")]

    assert [m.id_mutation for m in filtrer_multi_communes(pool)] == ["b", "a"]


def test_meme_id_sur_millesimes_distincts_n_est_pas_multi_communes() -> None:
    # La clé est (id_mutation, millesime) : deux millésimes = deux ventes distinctes,
    # même id_mutation, chacune mono-commune → conservées.
    a = replace(_vente("m1", "31555"), millesime=2023)
    b = replace(_vente("m1", "31149"), millesime=2024)

    assert len(filtrer_multi_communes([a, b])) == 2

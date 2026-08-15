"""Tests du filtre de régime DPE (#215) — ne pas pooler à travers une réforme."""

from __future__ import annotations

from dataclasses import replace

from valeur_verte_data.dvf import Mutation, aggregate_mutations
from valeur_verte_data.ruptures import derniere_reforme, filtrer_regime_dpe

_REFORME_2024 = "2024-07-01"


def _vente(id_mutation: str, *, date_dpe: str | None) -> Mutation:
    (base,) = aggregate_mutations(
        [
            {
                "id_mutation": id_mutation,
                "valeur_fonciere": "200000",
                "code_commune": "31555",
                "surface_reelle_bati": "80",
            }
        ],
        millesime=2024,
    )
    return replace(base, etiquette_dpe="D", date_etablissement_dpe=date_dpe)


def test_derniere_reforme_retient_la_plus_recente_avant_fin_de_n() -> None:
    boundaries = ("2021-07-01", "2024-07-01", "2026-01-01")
    # N=2024 : 2026 est future (ignorée), la borne applicable est 2024-07-01.
    assert derniere_reforme(2024, boundaries) == "2024-07-01"


def test_derniere_reforme_aucune_borne_applicable_donne_none() -> None:
    assert derniere_reforme(2023, ("2024-07-01",)) is None
    assert derniere_reforme(2024, ()) is None


def test_sans_calendrier_ne_filtre_rien() -> None:
    ventes = [_vente("a", date_dpe="2019-01-01"), _vente("b", date_dpe="2024-09-01")]

    assert filtrer_regime_dpe(ventes, 2024, ()) == ventes


def test_ecarte_les_dpe_anterieurs_a_la_derniere_reforme() -> None:
    ancien = _vente("ancien", date_dpe="2023-05-01")  # avant la réforme 2024-07
    recent = _vente("recent", date_dpe="2024-09-01")  # après

    filtres = filtrer_regime_dpe([ancien, recent], 2024, (_REFORME_2024,))

    assert [v.id_mutation for v in filtres] == ["recent"]


def test_conserve_les_dpe_sans_date() -> None:
    # On n'exclut que ce qu'on peut positivement dater avant la borne.
    sans_date = _vente("sans_date", date_dpe=None)

    filtres = filtrer_regime_dpe([sans_date], 2024, (_REFORME_2024,))

    assert [v.id_mutation for v in filtres] == ["sans_date"]


def test_une_reforme_future_ne_filtre_rien() -> None:
    ancien = _vente("ancien", date_dpe="2023-05-01")

    # Réforme 2026 : > fin de N=2024 → non applicable, rien n'est écarté.
    assert filtrer_regime_dpe([ancien], 2024, ("2026-01-01",)) == [ancien]


def test_dpe_exactement_a_la_borne_est_conserve() -> None:
    pile = _vente("pile", date_dpe=_REFORME_2024)  # le jour de la réforme = nouveau régime

    filtres = filtrer_regime_dpe([pile], 2024, (_REFORME_2024,))

    assert [v.id_mutation for v in filtres] == ["pile"]

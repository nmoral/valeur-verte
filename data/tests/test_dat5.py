"""DAT-5 (#128) — la porte : lire la date la plus récente puis juger la fraîcheur.

À la fin de l'ingestion, ``porte_dat5`` lit la date de mutation la plus récente du
département (``date_mutation_max`` sur ``data.vente``) et la confronte à la **date
d'exécution** via ``evaluer_fraicheur``. Aucune lecture d'archive n'est nécessaire (le
verdict se juge sur données en main + la référence) ; la porte rend **toujours** un
verdict. Le lecteur SQL est mocké ici (validé en intégration).
"""

from __future__ import annotations

from datetime import date

import pytest

from valeur_verte_data import dat5

_REFERENCE = date(2026, 3, 15)


def test_source_fraiche_ne_declenche_pas_d_alerte(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat5, "date_mutation_max", lambda *a: date(2026, 1, 10))
    verdict = dat5.porte_dat5(object(), "31", 2025, _REFERENCE, max_mois=12)
    assert verdict.perimee is False


def test_source_perimee_alerte(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat5, "date_mutation_max", lambda *a: date(2023, 6, 1))
    verdict = dat5.porte_dat5(object(), "31", 2025, _REFERENCE, max_mois=12)
    assert verdict.perimee is True


def test_sans_vente_datee_est_neutre(monkeypatch: pytest.MonkeyPatch) -> None:
    # Aucune vente datée → la porte rend un verdict neutre, jamais une alerte infondée.
    monkeypatch.setattr(dat5, "date_mutation_max", lambda *a: None)
    verdict = dat5.porte_dat5(object(), "31", 2025, _REFERENCE, max_mois=12)
    assert verdict.perimee is False
    assert verdict.date_mutation_max is None


def test_le_verdict_porte_la_date_lue(monkeypatch: pytest.MonkeyPatch) -> None:
    # La date lue est transportée par le verdict (archivée par la CLI ensuite).
    monkeypatch.setattr(dat5, "date_mutation_max", lambda *a: date(2025, 12, 1))
    assert dat5.porte_dat5(object(), "31", 2025, _REFERENCE, max_mois=12).date_mutation_max == date(
        2025, 12, 1
    )


def test_porte_transmet_conn_departement_et_millesime_au_lecteur(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    conn = object()
    recu: dict[str, tuple[object, str, int]] = {}

    def _lecteur(c: object, dept: str, mil: int) -> date:
        recu["lecteur"] = (c, dept, mil)
        return date(2026, 1, 1)

    monkeypatch.setattr(dat5, "date_mutation_max", _lecteur)

    dat5.porte_dat5(conn, "09", 2025, _REFERENCE, max_mois=12)

    assert recu["lecteur"] == (conn, "09", 2025)


def test_porte_applique_la_reference_et_le_seuil_recus(monkeypatch: pytest.MonkeyPatch) -> None:
    # Une date de 2024 est fraîche à 24 mois mais périmée à 12 : le seuil transmis mord.
    monkeypatch.setattr(dat5, "date_mutation_max", lambda *a: date(2024, 6, 1))
    assert dat5.porte_dat5(object(), "31", 2025, _REFERENCE, max_mois=24).perimee is False
    assert dat5.porte_dat5(object(), "31", 2025, _REFERENCE, max_mois=12).perimee is True

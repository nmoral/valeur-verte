"""DAT-2 (#125) — la porte : lire la volumétrie précédente puis alerter (jamais bloquer).

À la différence de DAT-3, la volumétrie **courante** est en main en fin d'ingestion
(le ``RapportIngestion``) : ``porte_dat2`` ne relit que celle du **millésime
précédent** (archivée par #131 dans ``data.rapport_batch``) avant de déléguer à
``evaluer_volumetrie``. Le lecteur SQL est mocké ici (validé en intégration) ; on
teste la logique de la porte, mutée sans base.
"""

from __future__ import annotations

import pytest

from valeur_verte_data import dat2

_ECART = {"ecart_max": 0.30}


def test_volumetrie_stable_ne_declenche_pas_d_alerte(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat2, "lire_volumetrie_precedent", lambda *a: 1000)
    verdict = dat2.porte_dat2(object(), "31", 2025, 1050, **_ECART)
    assert verdict.alerte is False


def test_ecart_au_dela_du_seuil_alerte(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat2, "lire_volumetrie_precedent", lambda *a: 1000)
    verdict = dat2.porte_dat2(object(), "31", 2025, 1450, **_ECART)
    assert verdict.alerte is True


def test_premier_chargement_sans_precedent_est_neutre(monkeypatch: pytest.MonkeyPatch) -> None:
    # Pas de millésime précédent → la porte rend un verdict neutre, pas None :
    # DAT-2 ne barre rien, elle produit toujours un verdict (journalisable).
    monkeypatch.setattr(dat2, "lire_volumetrie_precedent", lambda *a: None)
    verdict = dat2.porte_dat2(object(), "31", 2025, 1450, **_ECART)
    assert verdict.alerte is False


def test_le_departement_du_verdict_est_celui_demande(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(dat2, "lire_volumetrie_precedent", lambda *a: None)
    assert dat2.porte_dat2(object(), "09", 2025, 1000, **_ECART).departement == "09"


def test_porte_transmet_conn_departement_et_millesime_au_lecteur(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    # La porte doit interroger le lecteur avec la bonne connexion, le bon département
    # et le bon millésime — sinon elle comparerait à une autre maille.
    conn = object()
    recu: dict[str, tuple[object, str, int]] = {}

    def _precedent(c: object, dept: str, mil: int) -> int:
        recu["precedent"] = (c, dept, mil)
        return 1000

    monkeypatch.setattr(dat2, "lire_volumetrie_precedent", _precedent)

    dat2.porte_dat2(conn, "31", 2025, 1050, **_ECART)

    assert recu["precedent"] == (conn, "31", 2025)


def test_porte_evalue_la_volumetrie_courante_recue(monkeypatch: pytest.MonkeyPatch) -> None:
    # La volumétrie courante vient de l'appelant (le rapport en main), pas d'une
    # relecture : c'est bien l'argument transmis qui est confronté au précédent.
    monkeypatch.setattr(dat2, "lire_volumetrie_precedent", lambda *a: 1000)
    assert dat2.porte_dat2(object(), "31", 2025, 2000, **_ECART).volumetrie == 2000

"""REG-3 (#56) — cas de référence rejoués sur PostGIS réel : la porte de déploiement.

Sur un jeu de données **figé** (dump de prod nettoyé, `fixtures/reg3/*.copy.gz`), on
recalcule les 13 communes de référence via ``compute_departement`` — offline et
déterministe : le fetcher réseau est remplacé par les centroïdes figés — puis on
confronte chaque maille à sa fourchette calibrée (`cas_reference.json`). À données
figées, seule une dérive du **code/modèle** peut faire sortir un coefficient de sa
fourchette : le test rouge dit « quelque chose a bougé, comprends pourquoi » (régression
réelle *ou* évolution volontaire à recalibrer), jamais « mets à jour pour faire vert ».

Joué en CI contre le service ``postgis/postgis`` (job ``data-integration``) ; ignoré
sans ``DATABASE_URL``. Chargé en pur psycopg (COPY), sans dépendre de ``pg_restore``.
"""

from __future__ import annotations

import gzip
import os
from pathlib import Path
from typing import Any

import psycopg
import pytest

from valeur_verte_data import orchestration
from valeur_verte_data.batch import MailleCoefficient
from valeur_verte_data.db import migrate as cli
from valeur_verte_data.db.jetable import exiger_base_jetable
from valeur_verte_data.decoupage import Commune
from valeur_verte_data.degraded import CoverageMode, PalierFiabilite
from valeur_verte_data.reg3 import charger_cas_reference, profils_manquants, verifier_cas

DSN = os.environ.get("DATABASE_URL", "")

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
]

_FIXTURES = Path(__file__).resolve().parent.parent / "fixtures" / "reg3"
_TABLES = ("vente", "commune_epci", "commune_limitrophe")
_MILLESIME = 2025

# Centroïdes figés des 13 communes de référence (référentiel geo.api.gouv.fr, dépt 31).
# Gelés ici pour que le calcul ne dépende d'aucun appel réseau — sinon un centroïde qui
# bougerait déplacerait la pondération spatiale et casserait le déterminisme.
_CENTROIDES: dict[str, tuple[float, float]] = {
    "31555": (43.6007, 1.4328),  # Toulouse
    "31557": (43.5747, 1.3338),  # Tournefeuille
    "31044": (43.6108, 1.5037),  # Balma
    "31163": (43.592, 1.5996),  # Drémil-Lafage
    "31572": (43.4363, 1.4629),  # Venerque
    "31358": (43.7209, 1.5899),  # Montastruc-la-Conseillère
    "31585": (43.121, 0.6681),  # Villeneuve-de-Rivière
    "31021": (43.0694, 0.7181),  # Aspret-Sarrat
    "31247": (43.0837, 0.6786),  # Labarthe-Rivière
    "31011": (42.9925, 0.9036),  # Arbas
    "31059": (43.1326, 1.0273),  # Belbèze-en-Comminges
    "31069": (43.641, 1.377),  # Blagnac
    "31150": (43.649, 1.3197),  # Cornebarrieu
}


def _communes_figees(_fetcher: object, _departement: str) -> list[Commune]:
    """Remplace le fetch réseau : les 13 communes de référence, centroïdes gelés."""
    return [
        Commune(
            code_insee=code,
            nom="",
            code_epci=None,
            codes_postaux=(),
            centre_longitude=lon,
            centre_latitude=lat,
            code_departement="31",
        )
        for code, (lat, lon) in _CENTROIDES.items()
    ]


def _charger_fixture(conn: psycopg.Connection[Any]) -> None:
    """Vide puis recharge les tables d'entrée depuis les COPY gzippés (pur psycopg)."""
    exiger_base_jetable(os.environ)
    conn.execute(
        "TRUNCATE data.vente, data.commune_epci, data.commune_limitrophe, "
        "data.coefficient_maille_historique, data.maille_refusee"
    )
    for table in _TABLES:
        with (
            gzip.open(_FIXTURES / f"{table}.copy.gz", "rb") as fichier,
            conn.cursor().copy(f"COPY data.{table} FROM STDIN") as copie,
        ):
            while bloc := fichier.read(65536):
                copie.write(bloc)
    conn.commit()


def _lire_maille(conn: psycopg.Connection[Any], code_insee: str) -> MailleCoefficient:
    """Reconstruit la maille archivée d'une commune (millésime de référence)."""
    ligne = conn.execute(
        "SELECT mode, palier, rayon_retenu_km, n_transactions, r2, erreur_mediane, "
        "coef_m2_bati, coef_m2_terrain, coef_dpe, alerte "
        "FROM data.coefficient_maille_historique WHERE code_insee = %s AND millesime = %s",
        (code_insee, _MILLESIME),
    ).fetchone()
    assert ligne is not None, f"{code_insee} : aucune maille archivée"
    mode, palier, rayon, n, r2, erreur, bati, terrain, coef_dpe, alerte = ligne
    return MailleCoefficient(
        code_insee=code_insee,
        millesime=_MILLESIME,
        mode=CoverageMode(mode),
        palier=PalierFiabilite(palier),
        rayon_retenu_km=rayon,
        n_transactions=n,
        r2=float(r2),
        erreur_mediane=float(erreur) if erreur is not None else None,
        coef_m2_bati=float(bati),
        coef_m2_terrain=float(terrain),
        coef_dpe={classe: float(effet) for classe, effet in coef_dpe.items()},
        ic_dpe={},
        alerte=alerte,
    )


def test_les_cas_de_reference_restent_dans_leurs_fourchettes(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    cli.run(os.environ)  # garantit le schéma courant

    with psycopg.connect(DSN) as conn:
        _charger_fixture(conn)

    # Le fetch réseau des communes est figé : calcul offline et reproductible.
    monkeypatch.setattr(orchestration, "fetch_communes_by_departement", _communes_figees)
    with psycopg.connect(DSN, autocommit=False) as conn:
        orchestration.compute_departement(None, conn, "31", _MILLESIME)  # type: ignore[arg-type]

    with (_FIXTURES / "cas_reference.json").open(encoding="utf-8") as fichier:
        cas = charger_cas_reference(fichier)

    # Le jeu doit toujours éprouver les quatre profils (sinon il ne prouve rien sur
    # ceux qu'il omet) — garde de couverture (#122/INV-4).
    assert profils_manquants(cas) == frozenset()

    with psycopg.connect(DSN) as conn:
        non_conformes = {
            reference.code_insee: verdict.ecarts
            for reference in cas
            for verdict in (verifier_cas(reference, _lire_maille(conn, reference.code_insee)),)
            if not verdict.conforme
        }
    assert not non_conformes, f"cas hors fourchette : {non_conformes}"

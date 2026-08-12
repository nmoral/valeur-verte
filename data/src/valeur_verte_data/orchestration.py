"""Orchestration du batch par département : ingestion et calcul (#91).

Ces fonctions pilotent les transactions (une par commune), chargent le pool une
seule fois (§10.6) et appliquent les invariants de publication (INV-4, #122).
Elles sont isolées des points d'entrée CLI (``cli.py``) pour rester testables sans
argparse ni connexion réelle.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field, replace
from typing import Protocol

import httpx

from .batch import compute_communes
from .config import DEFAULT_CONFIG, CalculConfig
from .db.coefficient_repository import delete_coefficient, upsert_coefficient
from .db.limitrophe_repository import derive_limitrophes, read_adjacence, upsert_contour
from .db.pool_repository import load_pool
from .db.runner import Connection
from .decoupage_source import fetch_communes_by_departement, fetch_contours_by_departement
from .http import Fetcher
from .ingest import ingest_commune
from .invariants import (
    alertes_de_bord,
    detecter_ruptures_limitrophes,
    evaluer_coherence_geographique,
    evaluer_invariants_publication,
)
from .limitrophes import limitrophes

logger = logging.getLogger("valeur_verte_data")


class ConnexionTransactionnelle(Connection, Protocol):
    """Connexion dont l'appelant pilote les transactions, une par commune.

    Le runner de migrations n'a pas besoin de ces méthodes : elles restent hors
    du protocole ``Connection``, qui décrit le strict nécessaire.
    """

    def commit(self) -> None: ...

    def rollback(self) -> None: ...


@dataclass
class RapportIngestion:
    """Ce qu'une exécution a réellement chargé, et ce qu'elle a manqué."""

    departement: str
    millesime: int
    communes_traitees: int = 0
    mutations_inserees: int = 0
    contours_charges: int = 0
    echecs: list[str] = field(default_factory=list)
    sans_donnees: list[str] = field(default_factory=list)

    @property
    def est_en_echec(self) -> bool:
        """Un import qui n'a rien chargé ne doit pas passer pour un succès."""
        return self.communes_traitees == 0

    def resume(self) -> str:
        return (
            f"Département {self.departement}, millésime {self.millesime} : "
            f"{self.communes_traitees} commune(s), "
            f"{self.mutations_inserees} mutation(s), "
            f"{len(self.sans_donnees)} sans transaction publiée, "
            f"{len(self.echecs)} échec(s)"
        )


@dataclass
class RapportCalcul:
    """Ce qu'une exécution a calculé, refusé, ou raté."""

    departement: str
    millesime: int
    communes_calculees: int = 0
    refus: list[str] = field(default_factory=list)
    echecs: list[str] = field(default_factory=list)
    # INV-4 (#122) : mailles calculées mais non publiées faute de tenir les seuils
    # de qualité (erreur médiane, R², n). Journalisées pour distinguer plus tard
    # une faiblesse chronique d'une dérive nouvelle.
    rejets_invariants: list[str] = field(default_factory=list)
    # INV-3 (#121) : communes publiées mais portant un bandeau d'alerte de bord —
    # écart de coef_m2_bati > facteur 2 avec une commune limitrophe (artefact de
    # bord probable, §3.2). La maille reste servie, le doute est signalé.
    alertes_bord: list[str] = field(default_factory=list)
    # INV-5 (#123) : clé d'ordre canonique qui rend le batch déterministe. On la
    # journalise pour qu'un millésime publié soit auditable — REG-1 compare des
    # batches dont on sait qu'ils étaient reproductibles.
    ordre_canonique: str = "id_mutation"

    @property
    def est_en_echec(self) -> bool:
        """Aucun coefficient : pool vide ou millésime erroné, pas un territoire creux."""
        return self.communes_calculees == 0

    def resume(self) -> str:
        return (
            f"Département {self.departement}, millésime {self.millesime} : "
            f"{self.communes_calculees} coefficient(s), "
            f"{len(self.refus)} refus sous seuil, "
            f"{len(self.rejets_invariants)} rejet(s) invariant, "
            f"{len(self.alertes_bord)} alerte(s) de bord, "
            f"{len(self.echecs)} échec(s) ; "
            f"ordre canonique : {self.ordre_canonique}"
        )


def ingest_departement(
    fetcher: Fetcher,
    conn: ConnexionTransactionnelle,
    departement: str,
    millesime: int,
    *,
    config: CalculConfig = DEFAULT_CONFIG,
) -> RapportIngestion:
    """Ingère les ventes DVF de toutes les communes d'un département.

    Chaque commune est validée séparément : une interruption laisse en base le
    travail déjà fait, et une commune en échec est annulée plutôt que laissée
    à demi écrite — l'ingestion vide la commune avant de la réécrire.
    """
    rapport = RapportIngestion(departement=departement, millesime=millesime)
    communes = fetch_communes_by_departement(fetcher, departement)
    total = len(communes)

    for rang, commune in enumerate(communes, 1):
        try:
            inserees = ingest_commune(fetcher, conn, commune.code_insee, millesime, config=config)
        except httpx.HTTPStatusError as erreur:
            conn.rollback()
            if erreur.response.status_code == 404:
                # DVF ne publie pas de fichier pour une commune sans transaction.
                rapport.sans_donnees.append(commune.code_insee)
                logger.info(
                    "[%d/%d] %s : aucune transaction publiée", rang, total, commune.code_insee
                )
                continue
            rapport.echecs.append(commune.code_insee)
            logger.warning("[%d/%d] %s : échec (%s)", rang, total, commune.code_insee, erreur)
            continue
        except Exception as erreur:  # noqa: BLE001 — une commune ne doit pas tout arrêter
            conn.rollback()
            rapport.echecs.append(commune.code_insee)
            logger.warning("[%d/%d] %s : échec (%s)", rang, total, commune.code_insee, erreur)
            continue

        conn.commit()
        rapport.communes_traitees += 1
        rapport.mutations_inserees += inserees
        logger.info("[%d/%d] %s : %d mutation(s)", rang, total, commune.code_insee, inserees)

    _charger_contours(fetcher, conn, departement, rapport)
    return rapport


def compute_departement(
    fetcher: Fetcher,
    conn: ConnexionTransactionnelle,
    departement: str,
    millesime: int,
    *,
    config: CalculConfig = DEFAULT_CONFIG,
) -> RapportCalcul:
    """Calcule et écrit les coefficients de toutes les communes d'un département.

    Le pool est chargé une seule fois (§10.6) ; les communes refusées sous le
    seuil sont normales et n'interrompent rien.

    Il déborde sur les départements limitrophes (#18) : sans eux, une commune du
    bord calcule sur un demi-disque. Les voisins **nourrissent** le calcul sans
    être calculés — la liste des communes vient du découpage administratif du
    département demandé, et publier une maille voisine reviendrait à propager le
    défaut que ce débordement corrige, ses propres voisins n'étant pas chargés.
    """
    rapport = RapportCalcul(departement=departement, millesime=millesime)
    communes = fetch_communes_by_departement(fetcher, departement)
    voisins = limitrophes(departement)
    pool = load_pool(conn, departement, millesime, limitrophes=voisins, type_local=config.segment)
    logger.info(
        "Pool du département %s (+ %s) : %d vente(s)",
        departement,
        ", ".join(voisins) if voisins else "aucun limitrophe",
        len(pool),
    )

    coordonnees = [
        (commune.code_insee, commune.centre_latitude, commune.centre_longitude)
        for commune in communes
        if commune.centre_latitude is not None and commune.centre_longitude is not None
    ]
    calculees = {
        maille.code_insee: maille
        for maille in compute_communes(coordonnees, pool, millesime, config=config)
    }

    # INV-3 (#121) : les ruptures de bord se jugent sur l'ensemble des mailles du
    # département une fois toutes calculées, à partir de la contiguïté dérivée à
    # l'ingestion (les contours du département et de ses voisins sont alors là).
    adjacence = read_adjacence(conn, departement)
    alertes = alertes_de_bord(detecter_ruptures_limitrophes(calculees.values(), adjacence, config))

    for code_insee, _, _ in coordonnees:
        maille = calculees.get(code_insee)
        if maille is None:
            # Le refus doit effacer une éventuelle valeur antérieure, sinon un
            # coefficient invalidé par ce calcul continuerait d'être servi.
            delete_coefficient(conn, code_insee)
            conn.commit()
            rapport.refus.append(code_insee)
            continue
        raisons = (
            evaluer_invariants_publication(maille, config).raisons
            + evaluer_coherence_geographique(maille, config).raisons
        )
        if raisons:
            # INV-3/INV-4 : une maille hors seuils de qualité (erreur, R², n) ou
            # au rayon aberrant n'est pas publiée, et son éventuel coefficient
            # antérieur est effacé — #22 prend le relais. Le motif est journalisé.
            delete_coefficient(conn, code_insee)
            conn.commit()
            rapport.rejets_invariants.append(code_insee)
            logger.warning("%s : rejetée (invariant) — %s", code_insee, "; ".join(raisons))
            continue
        alerte_bord = alertes.get(code_insee)
        if alerte_bord is not None:
            # INV-3 #3 : la maille reste publiée, mais un bandeau signale l'écart
            # de bord avec une commune limitrophe (décision « publier avec alerte »).
            maille = replace(maille, alerte=alerte_bord)
            rapport.alertes_bord.append(code_insee)
        try:
            upsert_coefficient(conn, maille)
        except Exception as erreur:  # noqa: BLE001 — une commune ne doit pas tout arrêter
            conn.rollback()
            rapport.echecs.append(code_insee)
            logger.warning("%s : écriture impossible (%s)", code_insee, erreur)
            continue
        conn.commit()
        rapport.communes_calculees += 1

    return rapport


def _charger_contours(
    fetcher: Fetcher,
    conn: ConnexionTransactionnelle,
    departement: str,
    rapport: RapportIngestion,
) -> None:
    """Charge les contours communaux (INV-3, #121) en fin d'ingestion.

    Les contours sont **auxiliaires** aux ventes DVF : une panne de geo.api ne
    doit pas emporter une ingestion de plusieurs heures (§5). L'échec est annulé
    et journalisé, sans marquer l'ingestion en erreur. La contiguïté est dérivée
    dans la foulée (``ST_Touches``), une fois les nouveaux contours en base.
    """
    try:
        contours = fetch_contours_by_departement(fetcher, departement)
        if not contours:
            return
        for contour in contours:
            upsert_contour(conn, contour.code_insee, contour.code_departement, contour.contour)
        derive_limitrophes(conn)
        conn.commit()
        rapport.contours_charges = len(contours)
    except Exception as erreur:  # noqa: BLE001 — les contours ne doivent pas emporter les ventes
        conn.rollback()
        logger.warning("%s : contours non chargés (%s)", departement, erreur)

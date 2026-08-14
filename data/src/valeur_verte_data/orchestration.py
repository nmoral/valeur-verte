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

from .batch import (
    MailleCoefficient,
    compute_communes,
    compute_maille_departement,
    compute_maille_epci,
)
from .config import DEFAULT_CONFIG, CalculConfig
from .db.coefficient_repository import (
    archiver_coefficient,
    delete_coefficient,
    lire_coefficient_precedent,
    upsert_coefficient,
)
from .db.epci_repository import communes_de_meme_epci, upsert_epci
from .db.limitrophe_repository import derive_limitrophes, read_adjacence, upsert_contour
from .db.pool_repository import load_pool
from .db.runner import Connection
from .decoupage_source import fetch_communes_by_departement, fetch_contours_by_departement
from .degraded import CoverageMode
from .ecarts import ClasseEcart, RapportEcarts, ecart_maille
from .http import Fetcher
from .ingest import ingest_commune
from .invariants import (
    ResultatMonotonie,
    alertes_de_bord,
    detecter_ruptures_limitrophes,
    evaluer_coherence_geographique,
    evaluer_invariants_publication,
    evaluer_monotonie_dpe,
    evaluer_signe_ordre_grandeur,
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
    epci_charges: int = 0
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
    # INV-1 (#119) : communes publiées malgré une inversion de monotonie DPE
    # tolérée (classes adjacentes, la moins fournie sous le seuil). Journalisées
    # pour distinguer plus tard une faiblesse chronique d'une dérive nouvelle.
    inversions_dpe: list[str] = field(default_factory=list)
    # REG-1 (#129) : écarts des mailles au millésime précédent — objet dédié, une
    # ligne par maille comparée, alimentant l'écran de supervision (#45). Les mailles
    # en blocage n'ont pas été publiées (leur ancienne valeur reste vive).
    ecarts: RapportEcarts = field(default_factory=RapportEcarts)
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
            f"{len(self.inversions_dpe)} inversion(s) DPE tolérée(s), "
            f"{len(self.ecarts.blocages)} publication(s) bloquée(s) (REG-1), "
            f"{len(self.ecarts.alertes)} alerte(s) d'écart, "
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
    _charger_epci(fetcher, conn, departement, rapport)
    return rapport


def _raisons_de_rejet(
    maille: MailleCoefficient, monotonie: ResultatMonotonie, config: CalculConfig
) -> tuple[str, ...]:
    """Motifs de non-publication **par maille** (INV-4, INV-3 rayon, INV-2, INV-1).

    Batterie d'invariants par maille consommée à **chaque cran** de la cascade
    (#169) : une maille qui en viole un ne publie pas à ce cran, on descend d'un
    cran. L'INV-3 de **bord** (rupture entre communes limitrophes) est inter-maille
    et reste jugé après coup, sur l'ensemble des mailles locales.
    """
    return (
        evaluer_invariants_publication(maille, config).raisons
        + evaluer_coherence_geographique(maille, config).raisons
        + evaluer_signe_ordre_grandeur(maille, config).raisons
        + monotonie.raisons
    )


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
    pool = load_pool(
        conn,
        departement,
        millesime,
        fenetre=config.fenetre_millesimes,
        limitrophes=voisins,
        type_local=config.segment,
    )
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

    # #175 : aux crans EPCI/DÉPARTEMENT, la régression est **identique** pour toutes
    # les communes d'un même périmètre (échantillon partagé, pondération uniforme σ
    # départemental). On la calcule **une seule fois** puis on la réutilise — seul
    # ``code_insee`` change — au lieu de refaire fit + ``bandes_monotones`` par commune
    # (coût O(communes × fit) → O(régressions distinctes × fit)).
    epci_par_appartenance: dict[tuple[str, ...], MailleCoefficient | None] = {}
    maille_departementale: MailleCoefficient | None = None
    departement_calcule = False

    for code_insee, _latitude, _longitude in coordonnees:
        local = calculees.get(code_insee)
        retenue: MailleCoefficient | None = None
        monotonie: ResultatMonotonie | None = None
        if local is not None:
            monotonie_locale = evaluer_monotonie_dpe(local, config)
            if not _raisons_de_rejet(local, monotonie_locale, config):
                retenue, monotonie = local, monotonie_locale

        if retenue is None:
            # Cascade #169 : le cran LOCAL ne publie rien de conforme (absent, ou
            # calculé mais hors invariants par-maille) → on descend au cran EPCI
            # (une régression par EPCI avec bandes) avant de refuser.
            membres = communes_de_meme_epci(conn, code_insee)
            epci = None
            if membres:
                # Mémoïsation par appartenance EPCI (#175) : une régression par EPCI.
                cle = tuple(membres)
                if cle not in epci_par_appartenance:
                    epci_par_appartenance[cle] = compute_maille_epci(
                        code_insee, millesime, membres, pool, config=config
                    )
                partagee = epci_par_appartenance[cle]
                if partagee is not None:
                    epci = replace(partagee, code_insee=code_insee)
            if epci is not None:
                monotonie_epci = evaluer_monotonie_dpe(epci, config)
                if not _raisons_de_rejet(epci, monotonie_epci, config):
                    retenue, monotonie = epci, monotonie_epci

        if retenue is None:
            # Dernier filet #169 : le cran DÉPARTEMENT (ventes du seul département,
            # avec bandes) garantit une estimation là où le local et l'EPCI ont cédé.
            # Mémoïsé (#175) : une seule régression départementale pour tout le batch.
            if not departement_calcule:
                maille_departementale = compute_maille_departement(
                    code_insee, millesime, pool, config=config
                )
                departement_calcule = True
            dept = (
                replace(maille_departementale, code_insee=code_insee)
                if maille_departementale is not None
                else None
            )
            if dept is not None:
                monotonie_dept = evaluer_monotonie_dpe(dept, config)
                if not _raisons_de_rejet(dept, monotonie_dept, config):
                    retenue, monotonie = dept, monotonie_dept

        if retenue is None:
            # Aucun cran ne publie : on efface une éventuelle valeur antérieure
            # (sinon un coefficient invalidé continuerait d'être servi, #115). On
            # distingue le refus sec (rien de calculable) du rejet d'invariant
            # (calculé mais hors seuils) pour garder le rapport lisible.
            delete_coefficient(conn, code_insee)
            conn.commit()
            if local is None:
                rapport.refus.append(code_insee)
            else:
                rapport.rejets_invariants.append(code_insee)
                logger.warning("%s : rejetée (invariant), aucun cran de repli", code_insee)
            continue

        assert monotonie is not None  # posé conjointement à ``retenue``
        if monotonie.inversions_tolerees:
            # INV-1 : inversion admise (petit échantillon) mais tracée malgré tout.
            rapport.inversions_dpe.append(code_insee)
            logger.info(
                "%s : inversion DPE tolérée — %s",
                code_insee,
                "; ".join(monotonie.inversions_tolerees),
            )
        # INV-3 #3 : l'alerte de bord se juge sur l'adjacence des mailles **locales**
        # (décision « publier avec alerte ») ; un repli EPCI, plus grossier, ne la porte pas.
        alerte_bord = alertes.get(code_insee) if retenue.mode is CoverageMode.LOCAL else None
        if alerte_bord is not None:
            retenue = replace(retenue, alerte=alerte_bord)
            rapport.alertes_bord.append(code_insee)

        # REG-1 (#129) : archiver le millésime calculé, puis comparer au précédent.
        # Un écart au-delà du seuil de blocage **suspend la publication de la maille**
        # (l'ancienne valeur vive reste servie jusqu'à validation manuelle — REG-2) ;
        # la maille reste archivée pour la revue. Sans précédent, rien à comparer.
        archiver_coefficient(conn, retenue)
        precedent = lire_coefficient_precedent(conn, code_insee, millesime)
        if precedent is not None:
            ecart = ecart_maille(
                precedent,
                retenue,
                seuil_alerte=config.ecart_alerte_pct / 100,
                seuil_blocage=config.ecart_blocage_pct / 100,
            )
            rapport.ecarts.ajouter(code_insee, ecart)
            if ecart.classe is ClasseEcart.BLOCAGE:
                conn.commit()  # persiste l'archive ; la table vive garde l'ancienne valeur
                logger.warning(
                    "%s : publication bloquée — écart %.0f %% sur %s (REG-1)",
                    code_insee,
                    ecart.ecart * 100,
                    ecart.coefficient,
                )
                continue

        try:
            upsert_coefficient(conn, retenue)
        except Exception as erreur:  # noqa: BLE001 — une commune ne doit pas tout arrêter
            conn.rollback()
            rapport.echecs.append(code_insee)
            logger.warning("%s : écriture impossible (%s)", code_insee, erreur)
            continue
        conn.commit()
        rapport.communes_calculees += 1

    return rapport


def load_contours_departement(
    fetcher: Fetcher, conn: ConnexionTransactionnelle, departement: str
) -> int:
    """Charge les contours communaux d'un département et dérive la contiguïté (#121).

    Récupère les contours (geo.api), les enregistre, puis dérive la limitrophie
    (``ST_Touches``) sur l'ensemble des contours chargés. Retourne le nombre de
    contours chargés. **Lève** en cas d'échec : c'est le travail demandé, pas un
    accessoire — l'appelant décide s'il l'absorbe (ingestion) ou non (commande
    dédiée). Sans contour (département inconnu de geo.api), ne dérive ni ne
    committe.
    """
    contours = fetch_contours_by_departement(fetcher, departement)
    if not contours:
        return 0
    for contour in contours:
        upsert_contour(conn, contour.code_insee, contour.code_departement, contour.contour)
    derive_limitrophes(conn)
    conn.commit()
    return len(contours)


def charger_epci_departement(
    fetcher: Fetcher, conn: ConnexionTransactionnelle, departement: str
) -> int:
    """Charge l'appartenance EPCI des communes d'un département (#169).

    Comme les contours, l'EPCI vient de geo.api.gouv.fr (champ ``codeEpci`` porté
    par ``fetch_communes_by_departement``). Les communes sans EPCI (rare) sont
    ignorées ; une seule transaction est committée s'il y a eu un enregistrement.
    Chargeable après coup sur un département déjà ingéré, sans re-télécharger DVF.
    """
    enregistres = 0
    for commune in fetch_communes_by_departement(fetcher, departement):
        if commune.code_epci is None:
            continue
        upsert_epci(conn, commune.code_insee, commune.code_epci)
        enregistres += 1
    if enregistres:
        conn.commit()
    return enregistres


def _charger_contours(
    fetcher: Fetcher,
    conn: ConnexionTransactionnelle,
    departement: str,
    rapport: RapportIngestion,
) -> None:
    """Charge les contours (INV-3, #121) en fin d'ingestion, en mode **résilient**.

    Les contours sont **auxiliaires** aux ventes DVF : une panne de geo.api ne
    doit pas emporter une ingestion de plusieurs heures (§5). L'échec est annulé
    et journalisé, sans marquer l'ingestion en erreur.
    """
    try:
        rapport.contours_charges = load_contours_departement(fetcher, conn, departement)
    except Exception as erreur:  # noqa: BLE001 — les contours ne doivent pas emporter les ventes
        conn.rollback()
        logger.warning("%s : contours non chargés (%s)", departement, erreur)


def _charger_epci(
    fetcher: Fetcher,
    conn: ConnexionTransactionnelle,
    departement: str,
    rapport: RapportIngestion,
) -> None:
    """Charge l'EPCI des communes en fin d'ingestion, en mode **résilient**.

    L'EPCI est **auxiliaire** aux ventes DVF (comme les contours) : une panne de
    geo.api ne doit pas emporter une ingestion de plusieurs heures (§5). L'échec
    est annulé et journalisé, sans marquer l'ingestion en erreur.
    """
    try:
        rapport.epci_charges = charger_epci_departement(fetcher, conn, departement)
    except Exception as erreur:  # noqa: BLE001 — l'EPCI ne doit pas emporter les ventes
        conn.rollback()
        logger.warning("%s : EPCI non chargé (%s)", departement, erreur)

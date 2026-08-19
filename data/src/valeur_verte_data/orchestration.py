"""Orchestration du batch par département : ingestion et calcul (#91).

Ces fonctions pilotent les transactions (une par commune), chargent le pool une
seule fois (§10.6) et appliquent les invariants de publication (INV-4, #122).
Elles sont isolées des points d'entrée CLI (``cli.py``) pour rester testables sans
argparse ni connexion réelle.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field, replace
from datetime import date
from typing import Protocol

import httpx

from .batch import (
    MailleCoefficient,
    compute_communes,
    compute_maille_departement,
    compute_maille_epci,
    pool_hierarchique,
)
from .config import DEFAULT_CONFIG, CalculConfig
from .db.coefficient_repository import (
    archiver_coefficient,
    enregistrer_refus,
    lire_coefficient_precedent,
)
from .db.commune_repository import upsert_communes
from .db.epci_repository import communes_de_meme_epci, upsert_epci
from .db.limitrophe_repository import derive_limitrophes, read_adjacence, upsert_contour
from .db.pool_repository import load_pool
from .db.runner import Connection
from .decoupage_source import fetch_communes_by_departement, fetch_contours_by_departement
from .degraded import CoverageMode
from .distributions import Distribution
from .ecarts import ClasseEcart, MotifRefus, RapportEcarts, ecart_maille
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
from .multi_communes import filtrer_multi_communes
from .ruptures import filtrer_regime_dpe
from .schema_source import SchemaSourceError

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
    # #220 : communes dont le nom a été référencé. L'opérateur doit voir que le
    # référentiel a bien été rafraîchi, au même titre que contours et EPCI.
    communes_referencees: int = 0
    # DAT-3 (#131) : ventes insérées portant une étiquette DPE. Le moteur écarte les
    # ventes sans DPE (`regression._eligible`) — le taux d'appariement mesure la part
    # réellement exploitable, et sa chute est le premier symptôme à repérer plus tard.
    ventes_appariees: int = 0
    echecs: list[str] = field(default_factory=list)
    sans_donnees: list[str] = field(default_factory=list)
    # DAT-4 (#127) : distributions observées du département (prix/surface médianes,
    # terrain aberrant, histogramme DPE), mesurées en fin d'ingestion et archivées
    # (#131) pour que la porte au calcul les relise. None si non calculées.
    distribution: Distribution | None = None
    # DAT-5 (#128) : date de mutation la plus récente du département, mesurée en fin
    # d'ingestion et archivée (source de vérité de la fraîcheur/millésime, #46/#48).
    # None si aucune vente datée.
    mutation_la_plus_recente: date | None = None

    @property
    def est_en_echec(self) -> bool:
        """Un import qui n'a rien chargé ne doit pas passer pour un succès."""
        return self.communes_traitees == 0

    @property
    def taux_appariement(self) -> float:
        """Part des ventes insérées appariées à un DPE (DAT-3, #131).

        Rapportée aux ventes réellement en base, pas à un compteur parallèle. Une
        ingestion vide donne 0.0 plutôt qu'une division par zéro : l'absence
        d'appariement est un taux nul, pas une erreur.
        """
        if self.mutations_inserees == 0:
            return 0.0
        return self.ventes_appariees / self.mutations_inserees

    def resume(self) -> str:
        return (
            f"Département {self.departement}, millésime {self.millesime} : "
            f"{self.communes_traitees} commune(s), "
            f"{self.mutations_inserees} mutation(s), "
            f"{self.communes_referencees} commune(s) référencée(s), "
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
    # en blocage sont archivées comme les autres, mais **écartées de la publication**
    # du millésime (REG-2, #130) : leur ancienne valeur reste servie jusqu'à déblocage.
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
            f"{self.communes_calculees} coefficient(s) archivé(s), "
            f"{len(self.refus)} refus sous seuil, "
            f"{len(self.rejets_invariants)} rejet(s) invariant, "
            f"{len(self.alertes_bord)} alerte(s) de bord, "
            f"{len(self.inversions_dpe)} inversion(s) DPE tolérée(s), "
            f"{len(self.ecarts.blocages)} maille(s) écartée(s) de la publication (REG-1), "
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

    # Référentiel des noms (#220) écrit **avant** la boucle : une exécution
    # interrompue doit quand même laisser les noms en base. Ils ne dépendent pas
    # des ventes et servent aux écrans dès la première maille calculée.
    rapport.communes_referencees = upsert_communes(conn, communes)
    conn.commit()

    for rang, commune in enumerate(communes, 1):
        try:
            resultat = ingest_commune(fetcher, conn, commune.code_insee, millesime, config=config)
        except SchemaSourceError:
            # DAT-1 (#124) : une colonne source renommée est systémique (tout le
            # département), pas une panne d'une commune. On ne l'avale pas comme un
            # échec de plus : elle interrompt l'ingestion (le calcul ne tournera pas
            # sur des données muettes). L'ancien millésime reste servi.
            conn.rollback()
            raise
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
        rapport.mutations_inserees += resultat.inserees
        rapport.ventes_appariees += resultat.appariees
        logger.info(
            "[%d/%d] %s : %d mutation(s)", rang, total, commune.code_insee, resultat.inserees
        )

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
    # #215 : ne pas pooler à travers une réforme du DPE — on écarte les ventes dont
    # le diagnostic précède la dernière réforme ≤ N (calendrier config, vide = no-op).
    charge = len(pool)
    pool = filtrer_regime_dpe(pool, millesime, config.dpe_reform_boundaries)
    ecartes = charge - len(pool)
    # Mutations multi-communes : leur prix (total de la vente) n'est pas attribuable à
    # la surface d'une seule commune → prix/m² faussé, on les écarte de la régression.
    avant_multi = len(pool)
    pool = filtrer_multi_communes(pool)
    multi = avant_multi - len(pool)
    logger.info(
        "Pool du département %s (+ %s) : %d vente(s)%s%s",
        departement,
        ", ".join(voisins) if voisins else "aucun limitrophe",
        len(pool),
        f" ({ecartes} écartée(s) — régime DPE antérieur, #215)" if ecartes else "",
        f" ({multi} écartée(s) — mutation multi-communes)" if multi else "",
    )

    # #255 : un département dont le pool est **nourri uniquement par les limitrophes**
    # (non vide, mais aucune vente native — `code_insee` préfixé par le département) ne
    # produit aucune maille. Ses communes de bord publieraient sinon des mailles
    # « locales » fantômes — nocives car en N+1 une apparition réelle serait lue comme
    # un changement (REG-1). Le débordement (#18) nourrit le calcul, il ne le crée pas.
    # Un pool **vide** (aucune vente du tout) suit la voie normale : les communes sont
    # refusées (REG-2 retire leurs coefficients périmés), pas silencieusement ignorées.
    if pool and not any(vente.code_insee[:2] == departement for vente in pool):
        logger.info(
            "Département %s : aucune vente native (pool nourri seulement par les "
            "limitrophes) — aucune maille produite (#255).",
            departement,
        )
        return rapport

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

    # #249 : pooling partiel hiérarchique — tirer les effets DPE de chaque maille LOCAL
    # vers le fit poolé départemental, proportionnellement à leur précision (empirical
    # Bayes). Régulariseur **en amont** de la cascade : une petite maille bruitée,
    # rapprochée d'un parent monotone/plausible, passe alors les invariants au lieu de
    # basculer d'un cran. La cible est aussi le **parent du dernier cran** de la cascade
    # → on la calcule une fois ici et on la réutilise (mémoïsation #175). Opt-in ; sans
    # elle, `calculees` est inchangé (non-régression exacte).
    if config.pooling_hierarchique and coordonnees:
        maille_departementale = compute_maille_departement(
            coordonnees[0][0], millesime, pool, config=config
        )
        departement_calcule = True
        calculees = pool_hierarchique(
            calculees, maille_departementale, reference_dpe=config.reference_dpe
        )

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
            # REG-2 (#130) : le refus est **enregistré**, pas appliqué. La commune ne
            # sort du service qu'à la publication du millésime — sinon un millésime
            # calculé puis abandonné aurait déjà fait disparaître des communes. On
            # distingue le refus sec (rien de calculable) du rejet d'invariant
            # (calculé mais hors seuils) pour garder le rapport lisible.
            motif = MotifRefus.REFUS if local is None else MotifRefus.REJET_INVARIANT
            enregistrer_refus(conn, code_insee, millesime, motif)
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

        # REG-1 (#129) : comparer au précédent **avant** d'archiver, pour que le
        # verdict parte avec l'archive. C'est lui qui permettra à la publication
        # (REG-2, #130) d'écarter les mailles aberrantes : sans classe portée, une
        # projection « toutes les lignes du millésime » les publierait aussi. Sans
        # précédent, rien à comparer — la classe reste absente, ce qui n'est pas un
        # verdict favorable mais une absence de verdict.
        precedent = lire_coefficient_precedent(conn, code_insee, millesime)
        ecart = None
        if precedent is not None:
            ecart = ecart_maille(
                precedent,
                retenue,
                seuil_alerte=config.ecart_alerte_pct / 100,
                seuil_blocage=config.ecart_blocage_pct / 100,
            )
            rapport.ecarts.ajouter(code_insee, ecart)
            if ecart.classe is ClasseEcart.BLOCAGE:
                logger.warning(
                    "%s : écartée de la publication — écart %.0f %% sur %s (REG-1)",
                    code_insee,
                    ecart.ecart * 100,
                    ecart.coefficient,
                )

        # REG-2 (#130) : le batch archive, il ne publie pas. La table vive n'est
        # servie qu'après activation explicite depuis l'admin interne.
        try:
            archiver_coefficient(
                conn,
                retenue,
                classe_ecart=None if ecart is None else ecart.classe,
                ecart=None if ecart is None else ecart.ecart,
                ecart_coefficient=None if ecart is None else ecart.coefficient,
            )
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


def charger_communes_departement(
    fetcher: Fetcher, conn: ConnexionTransactionnelle, departement: str
) -> int:
    """Charge le référentiel des noms de communes d'un département (#220).

    ``data.commune`` est normalement peuplé à l'ingestion (``upsert_communes``) ;
    cette commande le (re)charge **sans re-télécharger DVF** — pour un département
    ingéré avant #220, ou après une fusion de communes. Idempotent (upsert) ; une
    seule transaction, committée s'il y a eu un enregistrement.
    """
    ecrites = upsert_communes(conn, fetch_communes_by_departement(fetcher, departement))
    if ecrites:
        conn.commit()
    return ecrites


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

"""Points d'entrée en ligne de commande, paramétrés par département (#91).

    python -m valeur_verte_data.cli ingest --departement 31 --millesime 2024

Le département est l'unité de travail : plusieurs instances peuvent tourner en
parallèle sur des départements distincts sans se gêner, chacune ne touchant que
les communes qu'elle possède. La logique d'orchestration vit dans
``orchestration.py`` ; ce module ne fait que l'analyse d'arguments et le câblage
de la connexion réelle.
"""

from __future__ import annotations

import argparse
import logging
import os
import sys
from collections.abc import Sequence
from datetime import datetime

from .config import resolve_config
from .controle_qualite import ecrire_controle_csv
from .dat2 import porte_dat2
from .dat3 import porte_dat3
from .dat4 import porte_dat4
from .dat5 import porte_dat5
from .db.controle_repository import auditer
from .db.rapport_batch_repository import (
    enregistrer_rapport_calcul,
    enregistrer_rapport_ingestion,
)
from .db.runner import Connection, resolve_dsn
from .db.vente_repository import calculer_distribution
from .ecarts import ecrire_ecarts_csv
from .http import HttpFetcher
from .orchestration import (
    RapportCalcul,
    RapportIngestion,
    charger_communes_departement,
    charger_epci_departement,
    charger_marqueurs_departement,
    compute_departement,
    ingest_departement,
    load_contours_departement,
)


def _analyser(argv: Sequence[str] | None) -> argparse.Namespace:
    analyseur = argparse.ArgumentParser(prog="valeur_verte_data.cli")
    commandes = analyseur.add_subparsers(dest="commande", required=True)

    ingest = commandes.add_parser("ingest", help="Ingérer les ventes DVF d'un département")
    ingest.add_argument("--departement", required=True, help="Code département (ex. 31)")
    ingest.add_argument("--millesime", required=True, type=int, help="Année DVF (ex. 2024)")

    compute = commandes.add_parser("compute", help="Calculer les coefficients d'un département")
    compute.add_argument("--departement", required=True, help="Code département (ex. 31)")
    compute.add_argument("--millesime", required=True, type=int, help="Année DVF (ex. 2024)")
    compute.add_argument(
        "--rapport-ecarts",
        help="Chemin d'un CSV où écrire le rapport d'écarts au millésime précédent (REG-1)",
    )

    contours = commandes.add_parser(
        "load-contours", help="Charger les contours communaux d'un département (INV-3)"
    )
    contours.add_argument("--departement", required=True, help="Code département (ex. 31)")

    epci = commandes.add_parser(
        "load-epci", help="Charger l'appartenance EPCI des communes d'un département (#169)"
    )
    epci.add_argument("--departement", required=True, help="Code département (ex. 52)")

    communes = commandes.add_parser(
        "load-communes",
        help="Charger le référentiel des noms de communes d'un département (#220)",
    )
    communes.add_argument("--departement", required=True, help="Code département (ex. 31)")

    marqueurs = commandes.add_parser(
        "load-marqueurs",
        help="Charger les marqueurs contextuels d'un département (#341)",
    )
    marqueurs.add_argument("--departement", required=True, help="Code département (ex. 31)")

    audit = commandes.add_parser(
        "audit", help="Contrôler la validité de data.vente (read-only, sans effet de bord)"
    )
    perimetre = audit.add_mutually_exclusive_group(required=True)
    perimetre.add_argument("--departement", help="Auditer un seul département (ex. 31)")
    perimetre.add_argument("--tous", action="store_true", help="Auditer toute la base")
    audit.add_argument("--millesime", type=int, help="Restreindre à un millésime (optionnel)")
    audit.add_argument(
        "--rapport", help="Chemin d'un CSV où écrire le détail des anomalies (optionnel)"
    )

    return analyseur.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    """Point d'entrée CLI ; retourne le code de sortie du processus."""
    arguments = _analyser(argv)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    import psycopg

    config = resolve_config(os.environ)
    with psycopg.connect(resolve_dsn(os.environ), autocommit=False) as conn:
        if arguments.commande == "load-contours":
            charges = load_contours_departement(HttpFetcher(), conn, arguments.departement)
            print(f"Département {arguments.departement} : {charges} contour(s) chargé(s)")
            return 0
        if arguments.commande == "load-epci":
            charges = charger_epci_departement(HttpFetcher(), conn, arguments.departement)
            print(f"Département {arguments.departement} : {charges} EPCI enregistré(s)")
            return 0
        if arguments.commande == "load-communes":
            charges = charger_communes_departement(HttpFetcher(), conn, arguments.departement)
            print(f"Département {arguments.departement} : {charges} commune(s) référencée(s)")
            return 0
        if arguments.commande == "load-marqueurs":
            charges = charger_marqueurs_departement(conn, arguments.departement)
            print(f"Département {arguments.departement} : {charges} marqueur(s) chargé(s)")
            return 0
        if arguments.commande == "audit":
            return _auditer(conn, arguments)
        rapport: RapportIngestion | RapportCalcul
        if arguments.commande == "compute":
            # DAT-3 (#126) : un appariement DVF↔DPE effondré produirait des coefficients
            # sur un échantillon biaisé, sans lever d'erreur. On barre le calcul du
            # département — l'ancien millésime reste servi (aucun calcul archivé).
            verdict = porte_dat3(
                conn,
                arguments.departement,
                arguments.millesime,
                seuil_min=config.taux_appariement_min_pct / 100,
                chute_max=config.chute_appariement_max_pct / 100,
            )
            if verdict is not None and verdict.bloque:
                motifs = " ; ".join(verdict.motifs)
                logging.getLogger(__name__).warning(
                    "Département %s : calcul bloqué (DAT-3) — %s", arguments.departement, motifs
                )
                print(
                    f"Département {arguments.departement}, millésime {arguments.millesime} : "
                    f"calcul BLOQUÉ (DAT-3) — {motifs}"
                )
                return 1
            # DAT-4 (#127) : distributions observées (relues de l'archive #131). La
            # plausibilité (prix/surface médianes, terrain aberrant) barre le calcul
            # comme DAT-3 ; la répartition DPE vs le millésime précédent ALERTE seule
            # (réforme légitime, décision humaine) — le calcul tourne quand même.
            distribution = porte_dat4(
                conn,
                arguments.departement,
                arguments.millesime,
                prix_median_min=config.prix_median_min,
                prix_median_max=config.prix_median_max,
                bati_median_min=config.bati_median_min,
                bati_median_max=config.bati_median_max,
                terrain_aberrant_max=config.terrain_aberrant_max_pct / 100,
                dpe_ecart_max=config.dpe_distribution_ecart_max_pct / 100,
            )
            if distribution is not None and distribution.alerte:
                alertes = " ; ".join(distribution.alertes)
                logging.getLogger(__name__).warning(
                    "Département %s : alerte distribution (DAT-4) — %s",
                    arguments.departement,
                    alertes,
                )
                print(
                    f"Département {arguments.departement}, millésime {arguments.millesime} : "
                    f"ALERTE distribution (DAT-4) — {alertes}"
                )
            if distribution is not None and distribution.bloque:
                motifs = " ; ".join(distribution.motifs)
                logging.getLogger(__name__).warning(
                    "Département %s : calcul bloqué (DAT-4) — %s", arguments.departement, motifs
                )
                print(
                    f"Département {arguments.departement}, millésime {arguments.millesime} : "
                    f"calcul BLOQUÉ (DAT-4) — {motifs}"
                )
                return 1
            # La CLI possède l'horloge : elle borne l'exécution (durée du rapport #131).
            debut = datetime.now()
            calcul = compute_departement(
                HttpFetcher(), conn, arguments.departement, arguments.millesime, config=config
            )
            enregistrer_rapport_calcul(conn, calcul, debut=debut, fin=datetime.now())
            conn.commit()
            rapport = calcul
            if arguments.rapport_ecarts:
                with open(arguments.rapport_ecarts, "w", encoding="utf-8", newline="") as fichier:
                    ecrire_ecarts_csv(calcul.ecarts, fichier)
                print(
                    f"Rapport d'écarts REG-1 : {arguments.rapport_ecarts} "
                    f"({len(calcul.ecarts.lignes)} maille(s) comparée(s))"
                )
        else:
            debut = datetime.now()
            ingestion = ingest_departement(
                HttpFetcher(), conn, arguments.departement, arguments.millesime, config=config
            )
            # DAT-4 (#127) : mesurer les distributions observées (prix/surface médianes,
            # terrain aberrant, histogramme DPE) sur les ventes ingérées et les attacher
            # au rapport. Elles sont archivées (#131) pour que la porte au calcul les
            # relise (plausibilité bloquante, répartition DPE alerte). « Aberrant » =
            # terrain nul ou hors bornes §7 (bornes de config passées au calcul).
            ingestion.distribution = calculer_distribution(
                conn,
                arguments.departement,
                arguments.millesime,
                terrain_min=config.terrain_min,
                terrain_max=config.terrain_max,
            )
            # DAT-5 (#128) : juger la fraîcheur (mutation la plus récente vs date
            # d'exécution) et attacher la date au rapport — source de vérité archivée
            # de la fraîcheur/millésime (#46/#48). Alerte non bloquante (plus bas).
            verdict_fraicheur = porte_dat5(
                conn,
                arguments.departement,
                arguments.millesime,
                debut.date(),
                max_mois=config.fraicheur_max_mois,
            )
            ingestion.mutation_la_plus_recente = verdict_fraicheur.date_mutation_max
            enregistrer_rapport_ingestion(conn, ingestion, debut=debut, fin=datetime.now())
            conn.commit()
            # DAT-2 (#125) : la volumétrie d'un département peut légitimement bouger,
            # mais un écart de plus de ± 30 % vs le millésime précédent est le symptôme
            # d'une source tronquée ou dédoublée. On ALERTE sans bloquer — seule
            # assertion DAT non bloquante : le calcul reste autorisé, le code de sortie
            # ne dépend que de l'ingestion elle-même.
            verdict_volumetrie = porte_dat2(
                conn,
                arguments.departement,
                arguments.millesime,
                ingestion.mutations_inserees,
                ecart_max=config.ecart_volumetrie_max_pct / 100,
            )
            if verdict_volumetrie.alerte:
                motifs = " ; ".join(verdict_volumetrie.motifs)
                logging.getLogger(__name__).warning(
                    "Département %s : alerte volumétrie (DAT-2) — %s",
                    arguments.departement,
                    motifs,
                )
                print(
                    f"Département {arguments.departement}, millésime {arguments.millesime} : "
                    f"ALERTE volumétrie (DAT-2) — {motifs}"
                )
            # DAT-5 (#128) : une source figée (mutation la plus récente > 12 mois) vieillit
            # en silence. On ALERTE sans bloquer — un backfill volontaire alerte
            # légitimement, la décision de recalculer reste humaine.
            if verdict_fraicheur.perimee:
                motifs = " ; ".join(verdict_fraicheur.motifs)
                logging.getLogger(__name__).warning(
                    "Département %s : alerte fraîcheur (DAT-5) — %s",
                    arguments.departement,
                    motifs,
                )
                print(
                    f"Département {arguments.departement}, millésime {arguments.millesime} : "
                    f"ALERTE fraîcheur (DAT-5) — {motifs}"
                )
            rapport = ingestion

    print(rapport.resume())
    if rapport.echecs:
        print("Communes en échec : " + ", ".join(rapport.echecs))
    return 1 if rapport.est_en_echec else 0


def _auditer(conn: Connection, arguments: argparse.Namespace) -> int:
    """Scanne data.vente et rend le code de sortie (≠ 0 si anomalie).

    ``--tous`` lève le filtre département (les doublons inter-départements ne sont
    complets que sur un scan global). Les ALERTES sont affichées mais ne changent
    pas le code de sortie — seules les ANOMALIES font échouer (cf. rapport.valide).
    """
    departement = None if arguments.tous else arguments.departement
    rapport = auditer(conn, departement=departement, millesime=arguments.millesime)

    print(rapport.resume())
    for anomalie in rapport.anomalies:
        print(
            f"  [{anomalie.severite.value}] {anomalie.reference} — "
            f"{anomalie.code} : {anomalie.message}"
        )
    if arguments.rapport:
        with open(arguments.rapport, "w", encoding="utf-8", newline="") as fichier:
            ecrire_controle_csv(rapport, fichier)
        print(f"Rapport CSV : {arguments.rapport} ({len(rapport.anomalies)} anomalie(s))")

    return 0 if rapport.valide else 1


if __name__ == "__main__":  # pragma: no cover
    sys.exit(main())

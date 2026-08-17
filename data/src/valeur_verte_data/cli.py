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
from .db.rapport_batch_repository import (
    enregistrer_rapport_calcul,
    enregistrer_rapport_ingestion,
)
from .db.runner import resolve_dsn
from .ecarts import ecrire_ecarts_csv
from .http import HttpFetcher
from .orchestration import (
    RapportCalcul,
    RapportIngestion,
    charger_epci_departement,
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
        rapport: RapportIngestion | RapportCalcul
        if arguments.commande == "compute":
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
            enregistrer_rapport_ingestion(conn, ingestion, debut=debut, fin=datetime.now())
            conn.commit()
            rapport = ingestion

    print(rapport.resume())
    if rapport.echecs:
        print("Communes en échec : " + ", ".join(rapport.echecs))
    return 1 if rapport.est_en_echec else 0


if __name__ == "__main__":  # pragma: no cover
    sys.exit(main())

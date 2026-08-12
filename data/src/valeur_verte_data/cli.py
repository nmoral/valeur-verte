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

from .config import resolve_config
from .db.runner import resolve_dsn
from .http import HttpFetcher
from .orchestration import RapportCalcul, RapportIngestion, compute_departement, ingest_departement


def _analyser(argv: Sequence[str] | None) -> argparse.Namespace:
    analyseur = argparse.ArgumentParser(prog="valeur_verte_data.cli")
    commandes = analyseur.add_subparsers(dest="commande", required=True)

    ingest = commandes.add_parser("ingest", help="Ingérer les ventes DVF d'un département")
    ingest.add_argument("--departement", required=True, help="Code département (ex. 31)")
    ingest.add_argument("--millesime", required=True, type=int, help="Année DVF (ex. 2024)")

    compute = commandes.add_parser("compute", help="Calculer les coefficients d'un département")
    compute.add_argument("--departement", required=True, help="Code département (ex. 31)")
    compute.add_argument("--millesime", required=True, type=int, help="Année DVF (ex. 2024)")

    return analyseur.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    """Point d'entrée CLI ; retourne le code de sortie du processus."""
    arguments = _analyser(argv)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    import psycopg

    config = resolve_config(os.environ)
    with psycopg.connect(resolve_dsn(os.environ), autocommit=False) as conn:
        if arguments.commande == "compute":
            rapport: RapportIngestion | RapportCalcul = compute_departement(
                HttpFetcher(), conn, arguments.departement, arguments.millesime, config=config
            )
        else:
            rapport = ingest_departement(
                HttpFetcher(), conn, arguments.departement, arguments.millesime, config=config
            )

    print(rapport.resume())
    if rapport.echecs:
        print("Communes en échec : " + ", ".join(rapport.echecs))
    return 1 if rapport.est_en_echec else 0


if __name__ == "__main__":  # pragma: no cover
    sys.exit(main())

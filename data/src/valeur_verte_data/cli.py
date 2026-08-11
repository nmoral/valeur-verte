"""Points d'entrée en ligne de commande, paramétrés par département (#91).

    python -m valeur_verte_data.cli ingest --departement 31 --millesime 2024

Le département est l'unité de travail : plusieurs instances peuvent tourner en
parallèle sur des départements distincts sans se gêner, chacune ne touchant que
les communes qu'elle possède.
"""

from __future__ import annotations

import argparse
import logging
import os
import sys
from collections.abc import Sequence
from dataclasses import dataclass, field
from typing import Protocol

import httpx

from .db.runner import Connection, resolve_dsn
from .decoupage_source import fetch_communes_by_departement
from .http import Fetcher, HttpFetcher
from .ingest import ingest_commune

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


def ingest_departement(
    fetcher: Fetcher, conn: ConnexionTransactionnelle, departement: str, millesime: int
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
            inserees = ingest_commune(fetcher, conn, commune.code_insee, millesime)
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

    return rapport


def _analyser(argv: Sequence[str] | None) -> argparse.Namespace:
    analyseur = argparse.ArgumentParser(prog="valeur_verte_data.cli")
    commandes = analyseur.add_subparsers(dest="commande", required=True)

    ingest = commandes.add_parser("ingest", help="Ingérer les ventes DVF d'un département")
    ingest.add_argument("--departement", required=True, help="Code département (ex. 31)")
    ingest.add_argument("--millesime", required=True, type=int, help="Année DVF (ex. 2024)")

    return analyseur.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    """Point d'entrée CLI ; retourne le code de sortie du processus."""
    arguments = _analyser(argv)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    import psycopg

    with psycopg.connect(resolve_dsn(os.environ), autocommit=False) as conn:
        rapport = ingest_departement(
            HttpFetcher(), conn, arguments.departement, arguments.millesime
        )

    print(rapport.resume())
    if rapport.echecs:
        print("Communes en échec : " + ", ".join(rapport.echecs))
    return 1 if rapport.est_en_echec else 0


if __name__ == "__main__":  # pragma: no cover
    sys.exit(main())

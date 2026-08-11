"""Invariants d'exploitation du compose de production (`infra/docker-compose.prod.yml`).

Ces tests n'inventorient pas les clés du fichier : chacun encode une règle dont
la violation a un coût réel — base PostGIS ouverte sur Internet, données perdues
au redéploiement, mot de passe de développement promu en production, impossibilité
de revenir en arrière faute de version épinglée.
"""

from __future__ import annotations

import re
from typing import Any

from .conftest import services

# Interpolation compose : ${VAR:-defaut} fournit une valeur de repli,
# ${VAR:?message} fait échouer le démarrage si la variable est absente.
DEFAUT_SILENCIEUX = re.compile(r"\$\{[A-Za-z_][A-Za-z0-9_]*:-")
OBLIGATOIRE = re.compile(r"\$\{[A-Za-z_][A-Za-z0-9_]*:\?")

VOLUME_POSTGRES = "/var/lib/postgresql/data"


def _valeurs_environnement(service: dict[str, Any]) -> list[str]:
    env: object = service.get("environment", {})
    if isinstance(env, dict):
        return [str(valeur) for valeur in env.values()]
    assert isinstance(env, list), "« environment » doit être un dictionnaire ou une liste."
    return [str(entree).partition("=")[2] for entree in env]


def test_la_base_n_est_jamais_publiee_sur_une_interface_publique(
    compose_prod: dict[str, Any],
) -> None:
    """Un port publié sans adresse écoute sur 0.0.0.0 : PostGIS exposé à Internet."""
    for nom, service in services(compose_prod).items():
        for publication in service.get("ports", []):
            adresse = str(publication).split(":")[0]
            assert adresse in {"127.0.0.1", "localhost"}, (
                f"Le service « {nom} » publie « {publication} » sur une interface publique ; "
                "les échanges passent par le réseau interne du compose."
            )


def test_les_donnees_survivent_a_la_recreation_du_conteneur(
    compose_prod: dict[str, Any],
) -> None:
    """« Une perte de base = la fin du produit » (épic #8) : volume nommé obligatoire."""
    db = services(compose_prod)["db"]
    montages = [str(montage) for montage in db.get("volumes", [])]
    persistants = [montage for montage in montages if montage.endswith(f":{VOLUME_POSTGRES}")]
    assert persistants, f"Le service « db » doit monter un volume sur {VOLUME_POSTGRES}."

    source = persistants[0].split(":")[0]
    assert not source.startswith((".", "/")), (
        f"Le stockage de « db » doit être un volume nommé, pas un chemin hôte ({source})."
    )
    declares: object = compose_prod.get("volumes", {})
    assert isinstance(declares, dict) and source in declares, (
        f"Le volume « {source} » doit être déclaré au niveau racine du compose."
    )


def test_aucune_valeur_de_repli_en_production(compose_prod: dict[str, Any]) -> None:
    """Une variable oubliée doit faire échouer le déploiement, pas retomber sur un défaut.

    Le compose de développement se permet ``${POSTGRES_PASSWORD:-valeur_verte}`` ;
    en production ce repli ferait tourner la base avec un mot de passe public.
    """
    for nom, service in services(compose_prod).items():
        for valeur in _valeurs_environnement(service):
            assert not DEFAUT_SILENCIEUX.search(valeur), (
                f"Le service « {nom} » définit une valeur de repli : {valeur}. "
                "Utiliser ${VAR:?message} pour échouer explicitement."
            )


def test_les_identifiants_de_la_base_sont_exiges_explicitement(
    compose_prod: dict[str, Any],
) -> None:
    """Mot de passe et DSN viennent de l'environnement du VPS, jamais du dépôt."""
    db = services(compose_prod)["db"]
    environnement: object = db.get("environment", {})
    assert isinstance(environnement, dict), "« environment » de db doit être un dictionnaire."
    mot_de_passe = str(environnement.get("POSTGRES_PASSWORD", ""))
    assert OBLIGATOIRE.search(mot_de_passe), (
        "POSTGRES_PASSWORD doit être exigé via ${POSTGRES_PASSWORD:?…} et rester hors du dépôt."
    )


def test_la_base_repart_apres_un_redemarrage_du_vps(compose_prod: dict[str, Any]) -> None:
    """Sans politique de redémarrage, un reboot OVH laisse le service à l'arrêt."""
    db = services(compose_prod)["db"]
    assert db.get("restart") in {"unless-stopped", "always"}, (
        "Le service « db » doit redémarrer automatiquement après un reboot."
    )


def test_l_image_data_est_epinglee_sur_une_version_publiee(
    compose_prod: dict[str, Any],
) -> None:
    """Le VPS tire une image publiée sur GHCR : pas de build sur place, pas de « latest »."""
    migrate = services(compose_prod)["migrate"]
    assert "build" not in migrate, (
        "Le VPS ne construit pas l'image : elle est publiée par la CI puis tirée depuis GHCR."
    )
    image = str(migrate.get("image", ""))
    assert image.startswith("ghcr.io/"), f"L'image doit provenir de GHCR, reçu « {image} »."

    tag = image.rpartition(":")[2]
    assert tag != "latest", (
        "Un tag « latest » interdit de savoir ce qui tourne et de revenir en arrière."
    )
    assert OBLIGATOIRE.search(tag), (
        "Le tag d'image doit être injecté au déploiement via ${…:?…} "
        "pour tracer la version déployée."
    )


def test_les_migrations_attendent_une_base_prete(compose_prod: dict[str, Any]) -> None:
    """Sans attente de healthcheck, le premier déploiement échoue sur une base non initialisée."""
    compose = services(compose_prod)
    assert "healthcheck" in compose["db"], "Le service « db » doit déclarer un healthcheck."

    depends: object = compose["migrate"].get("depends_on", {})
    assert isinstance(depends, dict), (
        "« depends_on » doit utiliser la forme longue avec condition, "
        "sinon l'attente est illusoire."
    )
    assert depends.get("db", {}).get("condition") == "service_healthy", (
        "Le service « migrate » doit attendre « service_healthy » sur « db »."
    )

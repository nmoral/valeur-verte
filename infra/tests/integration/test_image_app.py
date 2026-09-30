"""Test d'intégration de l'artefact déployé : l'image `app` (FrankenPHP) publiée sur GHCR.

On construit l'image depuis `app/Dockerfile`, on la lance et on vérifie qu'elle
sert réellement l'application Symfony (un 404 « No route found » prouve que le
runtime répond), qu'elle tourne sans privilège et qu'elle n'embarque pas
l'outillage de test.

Ignoré si Docker est absent (même convention que les autres tests d'intégration).
"""

from __future__ import annotations

import shutil
import subprocess
import time
import urllib.error
import urllib.request

import pytest

from ..conftest import APP_DOCKERFILE, COMPOSE_PROD, CRONTAB, REPO_ROOT, load_yaml, services

IMAGE = "valeur-verte-app:test"
CONTENEUR = "vv-app-itest"
CONTENEUR_SANTE = "vv-app-itest-sante"
PORT = 8901

# Base volontairement introuvable : le nom d'hôte ne résout pas dans le conteneur.
DSN_SANS_BASE = "postgresql://app:app@base-absente:5432/valeur_verte?serverVersion=16&charset=utf8"

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(shutil.which("docker") is None, reason="Docker indisponible"),
]


def _docker(*arguments: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["docker", *arguments],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=True,
    )


def _statut_http(url: str, tentatives: int = 30) -> int:
    """Interroge l'URL jusqu'à obtenir une réponse HTTP (le serveur met un instant à démarrer)."""
    derniere: Exception | None = None
    for _ in range(tentatives):
        try:
            with urllib.request.urlopen(url, timeout=1) as reponse:
                return int(reponse.status)
        except urllib.error.HTTPError as erreur:
            return int(erreur.code)
        except OSError as erreur:  # connexion refusée tant que le serveur démarre
            derniere = erreur
            time.sleep(0.5)
    raise AssertionError(f"Le serveur n'a jamais répondu sur {url} : {derniere}")


@pytest.fixture(scope="module")
def image() -> str:
    """Construit l'image de l'application depuis `app/Dockerfile`."""
    assert APP_DOCKERFILE.exists(), "app/Dockerfile est absent."
    _docker("build", "-f", str(APP_DOCKERFILE), "-t", IMAGE, str(REPO_ROOT / "app"))
    return IMAGE


def test_l_image_sert_l_application_symfony(image: str) -> None:
    """Un 404 « No route found » prouve que FrankenPHP sert bien le noyau Symfony."""
    subprocess.run(["docker", "rm", "-f", CONTENEUR], capture_output=True, text=True, check=False)
    _docker("run", "-d", "--name", CONTENEUR, "-p", f"127.0.0.1:{PORT}:80", image)
    try:
        statut = _statut_http(f"http://127.0.0.1:{PORT}/")
        assert statut == 404, f"Attendu 404 (aucune route définie), reçu {statut}."
    finally:
        subprocess.run(
            ["docker", "rm", "-f", CONTENEUR], capture_output=True, text=True, check=False
        )


def test_l_image_ne_tourne_pas_en_root(image: str) -> None:
    """Un conteneur root sur le VPS élargit inutilement la surface d'attaque."""
    identifiant = _docker("run", "--rm", "--entrypoint", "id", image, "-u").stdout.strip()
    assert identifiant != "0", "L'image doit s'exécuter sous un utilisateur non privilégié."


def test_l_image_n_embarque_pas_l_outillage_de_test(image: str) -> None:
    """PHPUnit, PHPStan & co. n'ont rien à faire en production (taille et surface d'attaque)."""
    resultat = subprocess.run(
        ["docker", "run", "--rm", "--entrypoint", "test", IMAGE, "-f", "/app/vendor/bin/phpunit"],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert resultat.returncode != 0, (
        "L'image de production ne doit pas contenir les dépendances de développement."
    )


def test_l_image_rend_le_dossier_de_sauvegarde_inscriptible(image: str) -> None:
    """Le volume `db_backups` (#55) est monté sur /sauvegardes et un volume nommé
    neuf hérite du propriétaire du point de montage dans l'image. Créé root, la
    tâche `app:sauvegarde:executer` échouait « Permission denied » sous l'utilisateur
    app (uid 10001) — aucune sauvegarde produite (#400). Le dossier doit donc
    appartenir à l'app dans l'image, pour qu'un volume neuf soit inscriptible."""
    resultat = subprocess.run(
        [
            "docker",
            "run",
            "--rm",
            "--entrypoint",
            "sh",
            IMAGE,
            "-c",
            "touch /sauvegardes/.essai && rm /sauvegardes/.essai && echo INSCRIPTIBLE",
        ],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert "INSCRIPTIBLE" in resultat.stdout, (
        "/sauvegardes doit être inscriptible par l'utilisateur non privilégié. "
        f"stdout={resultat.stdout!r} stderr={resultat.stderr!r}"
    )


def test_l_image_valide_le_crontab_des_taches_planifiees(image: str) -> None:
    """L'ordonnanceur embarqué relit lui-même le crontab versionné : c'est son propre
    analyseur qui tranche, pas une expression régulière de test. Un horaire invalide
    empêcherait le service « cron » de démarrer sur le VPS."""
    _docker(
        "run",
        "--rm",
        "-v",
        f"{CRONTAB}:/etc/supercronic/crontab:ro",
        "--entrypoint",
        "supercronic",
        image,
        "-test",
        "/etc/supercronic/crontab",
    )


def _sonde_de_production() -> str:
    """La commande de santé réellement déclarée par le compose de production.

    On exerce celle-là, pas une variante écrite pour le test : c'est le couple
    (commande, endpoint) qui doit tenir, et une sonde qui ne détecte rien ne se
    voit pas autrement qu'en la jouant sur une panne réelle.
    """
    sonde: object = services(load_yaml(COMPOSE_PROD))["app"].get("healthcheck", {})
    assert isinstance(sonde, dict), "Le service « app » doit déclarer un healthcheck."
    test: object = sonde.get("test")
    assert isinstance(test, list), "Le healthcheck doit déclarer sa commande sous forme de liste."
    arguments = [str(part) for part in test]
    if arguments and arguments[0] in {"CMD", "CMD-SHELL"}:
        arguments = arguments[1:]
    return " ".join(arguments)


def _etat_de_sante(conteneur: str, attendu: str, tentatives: int = 40) -> str:
    dernier = ""
    for _ in range(tentatives):
        dernier = _docker("inspect", "-f", "{{.State.Health.Status}}", conteneur).stdout.strip()
        if dernier == attendu:
            return dernier
        time.sleep(1)
    return dernier


def test_une_base_injoignable_rend_le_conteneur_malade(image: str) -> None:
    """Le critère d'acceptation de #313, joué sur l'artefact de production.

    Avec la sonde héritée de l'image (l'endpoint d'administration de FrankenPHP),
    ce conteneur — application debout, base injoignable, aucune requête servie —
    restait « healthy ». C'est précisément ce qu'on refuse.
    """
    subprocess.run(
        ["docker", "rm", "-f", CONTENEUR_SANTE], capture_output=True, text=True, check=False
    )
    _docker(
        "run",
        "-d",
        "--name",
        CONTENEUR_SANTE,
        "--health-cmd",
        _sonde_de_production(),
        "--health-interval",
        "2s",
        "--health-timeout",
        "3s",
        "--health-retries",
        "2",
        "--health-start-period",
        "1s",
        "-e",
        "APP_SECRET=integration",
        "-e",
        f"DATABASE_URL={DSN_SANS_BASE}",
        image,
    )
    try:
        assert _etat_de_sante(CONTENEUR_SANTE, "unhealthy") == "unhealthy", (
            "Une base injoignable doit rendre le conteneur « unhealthy »."
        )
        reponse = _docker("exec", CONTENEUR_SANTE, "curl", "-s", "http://127.0.0.1:80/sante").stdout
        assert '"base":"ko"' in reponse, (
            f"L'endpoint doit nommer le contrôle en cause, reçu : {reponse}."
        )
    finally:
        subprocess.run(
            ["docker", "rm", "-f", CONTENEUR_SANTE], capture_output=True, text=True, check=False
        )

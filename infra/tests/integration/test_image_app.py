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

from ..conftest import APP_DOCKERFILE, REPO_ROOT

IMAGE = "valeur-verte-app:test"
CONTENEUR = "vv-app-itest"
PORT = 8901

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

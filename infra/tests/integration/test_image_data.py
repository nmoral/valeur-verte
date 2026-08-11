"""Test d'intégration de l'artefact déployé : l'image `data` publiée sur GHCR.

C'est le seul test qui exerce ce qui tournera réellement sur le VPS : on
construit l'image, on la lance contre un PostGIS réel et on vérifie qu'elle
applique les migrations puis qu'un rejeu ne change rien (le déploiement rejoue
les migrations à chaque livraison).

Ignoré si Docker ou ``DATABASE_URL`` sont absents (même convention que les tests
d'intégration de ``data/``).
"""

from __future__ import annotations

import os
import shutil
import subprocess

import pytest

from ..conftest import DATA_DOCKERFILE, REPO_ROOT

DSN = os.environ.get("DATABASE_URL", "")
IMAGE = "valeur-verte-data:test"

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(not DSN, reason="DATABASE_URL non défini"),
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


@pytest.fixture(scope="module")
def image() -> str:
    """Construit l'image du pipeline depuis `data/Dockerfile`."""
    assert DATA_DOCKERFILE.exists(), "data/Dockerfile est absent."
    _docker("build", "-f", str(DATA_DOCKERFILE), "-t", IMAGE, str(REPO_ROOT / "data"))
    return IMAGE


def _migrer(image: str) -> str:
    """Lance les migrations depuis le conteneur, comme le fait le déploiement."""
    # DSN vue du conteneur : la base tourne sur l'hôte pendant le test.
    dsn = DSN.replace("localhost", "host.docker.internal").replace(
        "127.0.0.1", "host.docker.internal"
    )
    resultat = _docker(
        "run",
        "--rm",
        "--add-host=host.docker.internal:host-gateway",
        "-e",
        f"DATABASE_URL={dsn}",
        image,
    )
    return resultat.stdout


def test_l_image_applique_les_migrations_puis_devient_idempotente(image: str) -> None:
    """Le déploiement rejoue les migrations à chaque livraison : le rejeu ne doit rien casser."""
    premier = _migrer(image)
    assert "Migrations appliquées" in premier or "Base déjà à jour" in premier, (
        f"Sortie inattendue de la commande de migration : {premier!r}"
    )

    rejeu = _migrer(image)
    assert "Base déjà à jour" in rejeu, (
        f"Le rejeu des migrations doit être sans effet, reçu : {rejeu!r}"
    )


def test_l_image_ne_tourne_pas_en_root(image: str) -> None:
    """Un conteneur root sur le VPS élargit inutilement la surface d'attaque."""
    identifiant = _docker("run", "--rm", "--entrypoint", "id", image, "-u").stdout.strip()
    assert identifiant != "0", "L'image doit s'exécuter sous un utilisateur non privilégié."


def test_l_image_n_embarque_pas_l_outillage_de_developpement(image: str) -> None:
    """pytest, ruff & co. n'ont rien à faire en production (taille et surface d'attaque)."""
    resultat = subprocess.run(
        ["docker", "run", "--rm", "--entrypoint", "python", IMAGE, "-c", "import pytest"],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert resultat.returncode != 0, (
        "L'image de production ne doit pas contenir les dépendances de développement."
    )

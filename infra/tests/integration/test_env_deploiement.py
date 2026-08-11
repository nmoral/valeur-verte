"""Le fichier `.env` déposé sur le VPS doit rendre les identifiants **intacts**.

Compose interprète ``$`` dans un fichier ``.env`` comme une interpolation de
variable. Un mot de passe contenant ``$`` est donc silencieusement amputé — la
base démarre avec un identifiant qui n'est pas celui du secret, et le fragment
concerné s'affiche en clair dans les journaux.

On vérifie le comportement de bout en bout : composition du fichier, puis
résolution par Compose lui-même — le seul juge qui compte ici.
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

import pytest
from psycopg.conninfo import conninfo_to_dict

from ..conftest import COMPOSE_PROD, REPO_ROOT

ECRIRE_ENV = REPO_ROOT / "infra" / "ecrire-env.sh"

pytestmark = pytest.mark.skipif(
    shutil.which("docker") is None, reason="Docker Compose indisponible"
)

# Caractères qu'un générateur de mots de passe produit couramment et qui ont
# tous un sens particulier pour un shell ou pour Compose.
MOT_DE_PASSE_PIEGEUX = 'ab$ZtREY8628lPAC08W#c"d\'e\\f`g&h|i;j k$$l'


def _ecrire_env(destination: Path, mot_de_passe: str) -> None:
    resultat = subprocess.run(
        ["bash", str(ECRIRE_ENV)],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=True,
        env={
            "PATH": "/usr/bin:/bin",
            "POSTGRES_PASSWORD": mot_de_passe,
            "POSTGRES_USER": "valeur_verte",
            "POSTGRES_DB": "valeur_verte",
            "DATA_IMAGE_TAG": "essai",
        },
    )
    destination.write_text(resultat.stdout, encoding="utf-8")


def _resoudre(env_file: Path, service: str, variable: str) -> str:
    """Demande à Compose la valeur qu'il transmettrait réellement au conteneur."""
    resultat = subprocess.run(
        [
            "docker",
            "compose",
            "--env-file",
            str(env_file),
            "-f",
            str(COMPOSE_PROD),
            "config",
            "--format",
            "json",
        ],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=True,
    )
    import json

    configuration = json.loads(resultat.stdout)
    valeur: str = configuration["services"][service]["environment"][variable]
    return valeur


def test_un_mot_de_passe_a_caracteres_speciaux_arrive_intact(tmp_path: Path) -> None:
    """Le mot de passe transmis à la base doit être exactement celui du secret."""
    env_file = tmp_path / ".env"
    _ecrire_env(env_file, MOT_DE_PASSE_PIEGEUX)

    assert _resoudre(env_file, "db", "POSTGRES_PASSWORD") == MOT_DE_PASSE_PIEGEUX


def test_la_dsn_porte_le_meme_mot_de_passe_que_la_base(tmp_path: Path) -> None:
    """Si la DSN et la base divergent, les migrations échouent à se connecter."""
    env_file = tmp_path / ".env"
    _ecrire_env(env_file, MOT_DE_PASSE_PIEGEUX)

    dsn = _resoudre(env_file, "migrate", "DATABASE_URL")
    # Le format conninfo a son propre échappement (espaces, apostrophes) :
    # le seul contrôle qui vaille est de le faire relire par le client réel.
    assert conninfo_to_dict(dsn)["password"] == MOT_DE_PASSE_PIEGEUX


def test_aucun_fragment_de_mot_de_passe_dans_les_avertissements(tmp_path: Path) -> None:
    """Une interpolation non résolue recopie un morceau du mot de passe dans les journaux."""
    env_file = tmp_path / ".env"
    _ecrire_env(env_file, MOT_DE_PASSE_PIEGEUX)

    resultat = subprocess.run(
        [
            "docker",
            "compose",
            "--env-file",
            str(env_file),
            "-f",
            str(COMPOSE_PROD),
            "config",
        ],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=True,
    )
    assert "is not set" not in resultat.stderr, (
        f"Compose signale une variable non résolue — un fragment du mot de passe "
        f"a fuité dans les journaux : {resultat.stderr.strip()}"
    )

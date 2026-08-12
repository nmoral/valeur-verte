"""Le fichier `.env` déposé sur le VPS doit rendre les identifiants **intacts**.

Dans un fichier ``.env``, Compose interpole ``$VAR``. Un mot de passe contenant
``$`` est donc silencieusement amputé — la base démarre avec un identifiant qui
n'est pas celui du secret — et le fragment concerné s'affiche en clair dans les
journaux, où le masquage de GitHub ne le reconnaît pas.

On mesure la valeur **reçue par le conteneur**, jamais la sortie de
``docker compose config`` : celle-ci ré-échappe ``$`` en ``$$`` pour rester
ré-injectable, ce qui masquerait précisément le défaut recherché.
"""

from __future__ import annotations

import json
import shutil
import subprocess

import pytest
from psycopg.conninfo import conninfo_to_dict

from ..conftest import COMPOSE_PROD, REPO_ROOT, load_yaml

ECRIRE_ENV = REPO_ROOT / "infra" / "ecrire-env.sh"

pytestmark = pytest.mark.skipif(shutil.which("docker") is None, reason="Docker indisponible")

# Caractères qu'un générateur de mots de passe produit couramment, et qui ont
# tous un sens particulier pour un shell, pour Compose ou pour libpq.
# L'apostrophe et la barre oblique inverse en sont absentes : elles sont
# refusées explicitement (voir le test dédié).
MOT_DE_PASSE_PIEGEUX = 'ab$ZtREY8628lPAC08W#c"d e`f&g|h;i j$$k'


def _compose_temoin() -> str:
    """Compose minimal réutilisant les **vraies** expressions de la production.

    Les recopier ici les ferait diverger en silence ; le service `migrate` du
    compose de production n'est pas exécutable en test (image privée sur GHCR),
    d'où ce témoin qui emprunte son environnement tel quel.
    """
    production = load_yaml(COMPOSE_PROD)
    environnement = production["services"]["migrate"]["environment"]
    lignes = "\n".join(
        f"      {cle}: {json.dumps(str(valeur))}" for cle, valeur in environnement.items()
    )
    return (
        "services:\n"
        "  temoin:\n"
        "    image: busybox\n"
        "    environment:\n"
        '      POSTGRES_PASSWORD: "${POSTGRES_PASSWORD:?}"\n' + lignes + "\n"
    )


@pytest.fixture(scope="module")
def valeurs_recues(tmp_path_factory: pytest.TempPathFactory) -> dict[str, str]:
    """Valeurs telles qu'un conteneur les reçoit après passage par le .env."""
    repertoire = tmp_path_factory.mktemp("deploiement")
    (repertoire / "compose.yml").write_text(_compose_temoin(), encoding="utf-8")

    compose = subprocess.run(
        ["bash", str(ECRIRE_ENV)],
        capture_output=True,
        text=True,
        check=True,
        env={
            "PATH": "/usr/bin:/bin",
            "POSTGRES_PASSWORD": MOT_DE_PASSE_PIEGEUX,
            "POSTGRES_USER": "valeur_verte",
            "POSTGRES_DB": "valeur_verte",
            "DATA_IMAGE_TAG": "essai",
            "APP_IMAGE_TAG": "essai",
        },
    )
    (repertoire / ".env").write_text(compose.stdout, encoding="utf-8")

    execution = subprocess.run(
        ["docker", "compose", "run", "--rm", "-T", "temoin", "env"],
        cwd=repertoire,
        capture_output=True,
        text=True,
        check=True,
    )
    recues = {}
    for ligne in execution.stdout.splitlines():
        cle, separateur, valeur = ligne.partition("=")
        if separateur:
            recues[cle] = valeur
    recues["_avertissements"] = execution.stderr
    return recues


def test_le_mot_de_passe_arrive_intact_dans_le_conteneur(
    valeurs_recues: dict[str, str],
) -> None:
    """La base doit recevoir exactement le mot de passe du secret."""
    assert valeurs_recues["POSTGRES_PASSWORD"] == MOT_DE_PASSE_PIEGEUX


def test_la_dsn_porte_le_meme_mot_de_passe_que_la_base(
    valeurs_recues: dict[str, str],
) -> None:
    """Si la DSN et la base divergent, les migrations ne peuvent plus se connecter."""
    # Le format conninfo a son propre échappement : seul le client réel en juge.
    conninfo = conninfo_to_dict(valeurs_recues["DATABASE_URL"])
    assert conninfo["password"] == MOT_DE_PASSE_PIEGEUX
    assert conninfo["dbname"] == "valeur_verte"
    assert conninfo["host"] == "db"


@pytest.mark.parametrize("mot_de_passe", ["abc\\def", "abc'def"])
def test_un_caractere_intransmissible_est_refuse_explicitement(mot_de_passe: str) -> None:
    """Non transmissible sans ambiguïté : mieux vaut refuser que livrer un faux."""
    execution = subprocess.run(
        ["bash", str(ECRIRE_ENV)],
        capture_output=True,
        text=True,
        check=False,
        env={
            "PATH": "/usr/bin:/bin",
            "POSTGRES_PASSWORD": mot_de_passe,
            "POSTGRES_USER": "valeur_verte",
            "POSTGRES_DB": "valeur_verte",
            "DATA_IMAGE_TAG": "essai",
            "APP_IMAGE_TAG": "essai",
        },
    )
    assert execution.returncode != 0, "Le script doit refuser, pas produire un .env douteux."
    assert "apostrophe ou une barre oblique inverse" in execution.stderr
    assert "abc" not in execution.stdout, "Aucun identifiant ne doit être écrit en cas de refus."


def test_aucun_fragment_de_mot_de_passe_dans_les_avertissements(
    valeurs_recues: dict[str, str],
) -> None:
    """Une interpolation non résolue recopie un morceau du mot de passe en clair."""
    assert "is not set" not in valeurs_recues["_avertissements"], (
        "Compose signale une variable non résolue — un fragment du mot de passe "
        f"a fuité : {valeurs_recues['_avertissements'].strip()}"
    )

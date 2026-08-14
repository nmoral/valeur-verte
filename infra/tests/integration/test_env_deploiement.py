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
# Le secret Symfony subit les mêmes pièges d'interpolation que le mot de passe.
APP_SECRET_PIEGEUX = MOT_DE_PASSE_PIEGEUX
# Le mot de passe du rôle « app » est injecté dans une DSN URL : il doit rester
# URL-safe (le script refuse le reste, cf. le test dédié).
APP_DB_PASSWORD_SR = "app0123456789abcdefABCDEF_.~-"


def _identifiants_valides() -> dict[str, str]:
    """Jeu d'identifiants d'entrée d'`ecrire-env.sh` (mot de passe base piégeux)."""
    return {
        "PATH": "/usr/bin:/bin",
        "POSTGRES_PASSWORD": MOT_DE_PASSE_PIEGEUX,
        "POSTGRES_USER": "valeur_verte",
        "POSTGRES_DB": "valeur_verte",
        "DATA_IMAGE_TAG": "essai",
        "APP_IMAGE_TAG": "essai",
        "APP_SECRET": APP_SECRET_PIEGEUX,
        "APP_DB_PASSWORD": APP_DB_PASSWORD_SR,
        "BREVO_API_KEY": "xkeysib-essai",
    }


def _compose_temoin() -> str:
    """Compose minimal réutilisant les **vraies** expressions de la production.

    Les recopier ici les ferait diverger en silence ; les services `migrate` et
    `app` du compose de production ne sont pas exécutables en test (images privées
    sur GHCR), d'où ce témoin qui emprunte leur environnement tel quel. On expose
    aussi le secret applicatif et la DSN de l'app sous des noms dédiés (pour ne pas
    écraser la DSN conninfo du service `migrate`).
    """
    production = load_yaml(COMPOSE_PROD)
    environnement = production["services"]["migrate"]["environment"]
    app = production["services"]["app"]["environment"]
    lignes = "\n".join(
        f"      {cle}: {json.dumps(str(valeur))}" for cle, valeur in environnement.items()
    )
    lignes += f"\n      APP_SECRET_RECU: {json.dumps(str(app['APP_SECRET']))}"
    lignes += f"\n      APP_DSN_RECUE: {json.dumps(str(app['DATABASE_URL']))}"
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
        env=_identifiants_valides(),
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
    identifiants = _identifiants_valides()
    identifiants["POSTGRES_PASSWORD"] = mot_de_passe
    execution = subprocess.run(
        ["bash", str(ECRIRE_ENV)],
        capture_output=True,
        text=True,
        check=False,
        env=identifiants,
    )
    assert execution.returncode != 0, "Le script doit refuser, pas produire un .env douteux."
    assert "apostrophe ou une barre oblique inverse" in execution.stderr
    assert "abc" not in execution.stdout, "Aucun identifiant ne doit être écrit en cas de refus."


@pytest.mark.parametrize("mot_de_passe", ["abc@def", "abc:def", "abc/def", "abc#def", "a b"])
def test_un_mot_de_passe_app_non_url_safe_est_refuse(mot_de_passe: str) -> None:
    """Injecté dans « postgresql://app:<mot de passe>@… », un caractère réservé d'URL
    tronquerait silencieusement la DSN : le script refuse plutôt que de la livrer fausse."""
    identifiants = _identifiants_valides()
    identifiants["APP_DB_PASSWORD"] = mot_de_passe
    execution = subprocess.run(
        ["bash", str(ECRIRE_ENV)],
        capture_output=True,
        text=True,
        check=False,
        env=identifiants,
    )
    assert execution.returncode != 0, "Un mot de passe de rôle non URL-safe doit être refusé."
    assert "URL-safe" in execution.stderr
    assert "APP_DB_PASSWORD" not in execution.stdout, (
        "Aucun identifiant ne doit être écrit en cas de refus."
    )


def test_le_secret_applicatif_arrive_intact_dans_le_conteneur(
    valeurs_recues: dict[str, str],
) -> None:
    """APP_SECRET signe les jetons et le CSRF : une valeur amputée les casse en silence."""
    assert valeurs_recues["APP_SECRET_RECU"] == APP_SECRET_PIEGEUX


def test_la_dsn_de_l_app_porte_le_mot_de_passe_du_role(
    valeurs_recues: dict[str, str],
) -> None:
    """La DSN URL de l'app doit transporter exactement le mot de passe du rôle « app »
    (URL-safe, donc littéral) : sinon l'app ne se connecte pas à sa base."""
    dsn = valeurs_recues["APP_DSN_RECUE"]
    assert f"://app:{APP_DB_PASSWORD_SR}@db:5432/" in dsn


def test_aucun_fragment_de_mot_de_passe_dans_les_avertissements(
    valeurs_recues: dict[str, str],
) -> None:
    """Une interpolation non résolue recopie un morceau du mot de passe en clair."""
    assert "is not set" not in valeurs_recues["_avertissements"], (
        "Compose signale une variable non résolue — un fragment du mot de passe "
        f"a fuité : {valeurs_recues['_avertissements'].strip()}"
    )

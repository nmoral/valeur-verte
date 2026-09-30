"""Vitrine restreinte pilotable côté CI/CD (#385, #402).

`APP_VITRINE_RESTREINTE` ferme la zone pro (redirige app. vers l'accueil), masque
le commercial et met en avant le simulateur (#385). Comme la bascule maintenance
(#197), elle doit être pilotable depuis le déploiement sans reconstruire l'image :
une variable lue dans le `.env` du VPS, passée à l'app par le compose.

Différence clé avec la maintenance : le **défaut est restreint (1)** — tant qu'on
n'ouvre pas explicitement la vitrine, la zone pro reste fermée.
"""

from __future__ import annotations

import subprocess
from typing import Any

from .conftest import REPO_ROOT, services

ECRIRE_ENV = REPO_ROOT / "infra" / "ecrire-env.sh"

# Environnement minimal valide pour ecrire-env.sh (identifiants factices, URL-safe).
ENV_VALIDE = {
    "PATH": "/usr/bin:/bin",
    "POSTGRES_USER": "vv",
    "POSTGRES_DB": "valeur_verte",
    "POSTGRES_PASSWORD": "motdepasse",
    "DATA_IMAGE_TAG": "abc123",
    "APP_IMAGE_TAG": "abc123",
    "APP_SECRET": "deadbeef",
    "APP_DB_PASSWORD": "url-safe_pass.123~",
    "BREVO_API_KEY": "xkeysib-factice",
}


def _ecrire_env(**surcharges: str) -> str:
    execution = subprocess.run(
        ["bash", str(ECRIRE_ENV)],
        capture_output=True,
        text=True,
        check=True,
        env={**ENV_VALIDE, **surcharges},
    )
    return execution.stdout


def test_le_compose_pilote_la_vitrine_par_l_environnement(
    compose_prod: dict[str, Any],
) -> None:
    """L'app lit APP_VITRINE_RESTREINTE depuis le .env du VPS (passe-plat), jamais
    figée dans le dépôt : c'est ce qui permet d'ouvrir/fermer la vitrine sans
    reconstruire l'image ni redéployer."""
    app = services(compose_prod)["app"]
    environnement: object = app.get("environment", {})
    assert isinstance(environnement, dict)
    valeur = str(environnement.get("APP_VITRINE_RESTREINTE", "")).strip()
    # Passe-plat nu (la règle « aucun repli en prod » interdit « :- ») : le défaut
    # restreint (1) est porté par ecrire-env.sh, testé plus bas.
    assert valeur == "${APP_VITRINE_RESTREINTE}", (
        "APP_VITRINE_RESTREINTE doit être passée depuis l'environnement du VPS "
        f"(« ${{APP_VITRINE_RESTREINTE}} »), reçu « {valeur} »."
    )
    assert valeur != "1", "La vitrine ne doit jamais être figée dans le dépôt."


def test_le_env_du_vps_restreint_la_vitrine_par_defaut() -> None:
    """Sans consigne, le .env déposé porte APP_VITRINE_RESTREINTE=1 : zone pro
    fermée et pas d'avertissement Compose (variable définie)."""
    assert "APP_VITRINE_RESTREINTE='1'" in _ecrire_env()


def test_le_env_du_vps_peut_ouvrir_la_vitrine() -> None:
    """L'exploitant fixe APP_VITRINE_RESTREINTE=0 (dans l'environnement / le .env)
    pour rouvrir la zone pro le jour de l'ouverture commerciale."""
    assert "APP_VITRINE_RESTREINTE='0'" in _ecrire_env(APP_VITRINE_RESTREINTE="0")

"""Périmètre public national pilotable côté CI/CD (#393).

Le périmètre de publication des pages programmatiques SEO
({@see App\\Publication\\PerimetrePublic}) est volontairement progressif : au
go-live, seuls les départements d'une allowlist sont exposés/indexés. L'allowlist
est pilotée par `VV_PERIMETRE_DEPARTEMENTS` (CSV de codes, ou la sentinelle `*`
pour « tous »).

Comme la vitrine restreinte (#385) et la bascule maintenance (#197), l'allowlist
doit être pilotable depuis le déploiement sans reconstruire l'image : une variable
lue dans le `.env` du VPS, passée à l'app par le compose. Différence avec la
vitrine : le **défaut est ouvert (`*`)** — le territoire national est exposé tant
qu'on ne restreint pas explicitement.
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


def test_le_compose_pilote_le_perimetre_par_l_environnement(
    compose_prod: dict[str, Any],
) -> None:
    """L'app lit VV_PERIMETRE_DEPARTEMENTS depuis le .env du VPS (passe-plat),
    jamais figée dans le dépôt : c'est ce qui permet d'élargir ou de restreindre
    le périmètre sans reconstruire l'image ni redéployer."""
    app = services(compose_prod)["app"]
    environnement: object = app.get("environment", {})
    assert isinstance(environnement, dict)
    valeur = str(environnement.get("VV_PERIMETRE_DEPARTEMENTS", "")).strip()
    # Passe-plat nu (la règle « aucun repli en prod » interdit « :- ») : le défaut
    # ouvert (`*`) est porté par ecrire-env.sh, testé plus bas.
    assert valeur == "${VV_PERIMETRE_DEPARTEMENTS}", (
        "VV_PERIMETRE_DEPARTEMENTS doit être passée depuis l'environnement du VPS "
        f"(« ${{VV_PERIMETRE_DEPARTEMENTS}} »), reçu « {valeur} »."
    )
    # L'allowlist ne doit jamais être figée dans le dépôt (sinon élargir un
    # département imposerait un redéploiement d'image).
    assert "," not in valeur and valeur != "*", (
        "Le périmètre ne doit jamais être figé dans le dépôt."
    )


def test_le_env_du_vps_ouvre_tout_le_territoire_par_defaut() -> None:
    """Sans consigne, le .env déposé porte VV_PERIMETRE_DEPARTEMENTS=* : tous les
    départements mesurés sont exposés, et pas d'avertissement Compose (variable
    définie)."""
    assert "VV_PERIMETRE_DEPARTEMENTS='*'" in _ecrire_env()


def test_le_env_du_vps_peut_restreindre_le_perimetre() -> None:
    """L'exploitant fixe une allowlist CSV (dans l'environnement / le .env) pour
    revenir à une exposition partielle sans toucher au code."""
    assert "VV_PERIMETRE_DEPARTEMENTS='09,31'" in _ecrire_env(
        VV_PERIMETRE_DEPARTEMENTS="09,31"
    )

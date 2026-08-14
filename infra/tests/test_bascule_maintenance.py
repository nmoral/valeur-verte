"""Bascule maintenance pilotable côté VPS (#197).

La maintenance (page 503 habillée) s'active sans reconstruire ni retélécharger
l'image : par une variable d'environnement lue dans le `.env` du VPS. Deux
exigences opposées à tenir : pouvoir l'activer d'un geste, et ne jamais couper le
service par accident — une variable absente doit laisser le service **ouvert**,
pas le fermer.
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


def test_le_compose_pilote_la_maintenance_par_l_environnement(
    compose_prod: dict[str, Any],
) -> None:
    """L'app lit APP_MAINTENANCE depuis le .env du VPS (passe-plat), jamais figée
    dans le dépôt : c'est ce qui permet d'activer la maintenance sans redéployer."""
    app = services(compose_prod)["app"]
    environnement: object = app.get("environment", {})
    assert isinstance(environnement, dict)
    valeur = str(environnement.get("APP_MAINTENANCE", "")).strip()
    assert valeur == "${APP_MAINTENANCE}", (
        "APP_MAINTENANCE doit être passée depuis l'environnement du VPS "
        f"(« ${{APP_MAINTENANCE}} »), reçu « {valeur} »."
    )


def test_une_maintenance_absente_laisse_le_service_ouvert(
    compose_prod: dict[str, Any],
) -> None:
    """Le passe-plat sans « :? » : une variable absente n'échoue pas le compose et
    vaut « vide » côté app (maintenance off). Fermer par accident serait pire que
    l'oubli d'activation."""
    app = services(compose_prod)["app"]
    valeur = str(app.get("environment", {}).get("APP_MAINTENANCE", ""))
    assert ":?" not in valeur, "La maintenance ne doit pas être requise (défaut = ouvert)."
    assert valeur != "1", "La maintenance ne doit jamais être figée sur « activée »."


def test_le_env_du_vps_desactive_la_maintenance_par_defaut() -> None:
    """Sans consigne, le .env déposé porte APP_MAINTENANCE=0 : service ouvert et
    pas d'avertissement Compose (variable définie)."""
    assert "APP_MAINTENANCE='0'" in _ecrire_env()


def test_le_env_du_vps_peut_activer_la_maintenance() -> None:
    """L'exploitant fixe APP_MAINTENANCE=1 (dans l'environnement / le .env) pour
    couper proprement en 503 le temps d'une intervention."""
    assert "APP_MAINTENANCE='1'" in _ecrire_env(APP_MAINTENANCE="1")

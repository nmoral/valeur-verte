"""Accès aux fichiers de déploiement du dépôt, chargés une fois par session.

Les tests de ce périmètre lisent des fichiers situés à la racine du monorepo
(`infra/`, `.github/workflows/`) : la racine est déduite de l'emplacement de ce
fichier, jamais du répertoire courant.
"""

from __future__ import annotations

from pathlib import Path
from typing import Any

import pytest
import yaml

REPO_ROOT = Path(__file__).resolve().parents[2]

COMPOSE_PROD = REPO_ROOT / "infra" / "docker-compose.prod.yml"
DEPLOY_WORKFLOW = REPO_ROOT / ".github" / "workflows" / "deploy.yml"
DATA_DOCKERFILE = REPO_ROOT / "data" / "Dockerfile"


def load_yaml(path: Path) -> dict[str, Any]:
    """Charge un YAML en dictionnaire, avec un échec lisible s'il manque."""
    if not path.exists():
        raise AssertionError(f"Fichier de déploiement absent : {path.relative_to(REPO_ROOT)}")
    parsed: object = yaml.safe_load(path.read_text(encoding="utf-8"))
    if not isinstance(parsed, dict):
        raise AssertionError(f"YAML inattendu (dictionnaire attendu) : {path}")
    return parsed


def services(compose: dict[str, Any]) -> dict[str, dict[str, Any]]:
    """Services déclarés par un fichier compose."""
    declared: object = compose.get("services", {})
    assert isinstance(declared, dict), "La clé « services » doit être un dictionnaire."
    return {str(name): dict(body) for name, body in declared.items()}


def triggers(workflow: dict[str, Any]) -> dict[str, Any]:
    """Déclencheurs d'un workflow GitHub Actions.

    PyYAML interprète la clé ``on:`` comme le booléen ``True`` (YAML 1.1) : on
    accepte les deux formes pour rester indépendant du chargeur.
    """
    clef_booleenne: Any = True
    raw: object = workflow["on"] if "on" in workflow else workflow.get(clef_booleenne)
    if isinstance(raw, str):
        return {raw: None}
    if isinstance(raw, list):
        return {str(item): None for item in raw}
    assert isinstance(raw, dict), "Le workflow doit déclarer des déclencheurs « on: »."
    return {str(name): body for name, body in raw.items()}


@pytest.fixture(scope="session")
def repo_root() -> Path:
    return REPO_ROOT


@pytest.fixture(scope="session")
def compose_prod() -> dict[str, Any]:
    return load_yaml(COMPOSE_PROD)


@pytest.fixture(scope="session")
def deploy_workflow() -> dict[str, Any]:
    return load_yaml(DEPLOY_WORKFLOW)

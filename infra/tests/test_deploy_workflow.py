"""Invariants du workflow de déploiement (`.github/workflows/deploy.yml`).

Le runner GitHub ouvre la connexion SSH vers le VPS OVH (#78) : ces tests
verrouillent qui peut déclencher un déploiement, d'où viennent les identifiants,
et la traçabilité de ce qui est réellement déployé.
"""

from __future__ import annotations

import re
from typing import Any

from .conftest import DEPLOY_WORKFLOW, triggers

REFERENCE_SECRET = re.compile(r"\$\{\{\s*secrets\.[A-Z_]+\s*\}\}")
IDENTIFIANT_SENSIBLE = re.compile(r"\b(host|user(name)?|key|password|passphrase)\b", re.IGNORECASE)


def _jobs(workflow: dict[str, Any]) -> dict[str, dict[str, Any]]:
    declares: object = workflow.get("jobs", {})
    assert isinstance(declares, dict), "Le workflow doit déclarer des jobs."
    return {str(nom): dict(corps) for nom, corps in declares.items()}


def _job_de_deploiement(workflow: dict[str, Any]) -> tuple[str, dict[str, Any]]:
    for nom, corps in _jobs(workflow).items():
        rendu = str(corps)
        if "ssh" in rendu.lower() or "deploy" in nom.lower():
            return nom, corps
    raise AssertionError("Aucun job de déploiement identifiable dans le workflow.")


def test_une_pull_request_ne_peut_pas_declencher_un_deploiement(
    deploy_workflow: dict[str, Any],
) -> None:
    """Sur `pull_request`, une branche quelconque déploierait son propre code en production."""
    declencheurs = triggers(deploy_workflow)
    assert "pull_request" not in declencheurs, (
        "Le déploiement ne doit pas se déclencher sur une pull request."
    )
    assert "pull_request_target" not in declencheurs, (
        "« pull_request_target » exposerait les secrets de déploiement à du code non fusionné."
    )


def test_le_deploiement_automatique_est_restreint_a_main(
    deploy_workflow: dict[str, Any],
) -> None:
    """Seul le code fusionné et passé par la porte qualité part en production."""
    push: object = triggers(deploy_workflow).get("push")
    if push is None:
        return
    assert isinstance(push, dict), "Le déclencheur « push » doit restreindre ses branches."
    assert push.get("branches") == ["main"], (
        f"Le déploiement sur push doit être limité à main, reçu : {push.get('branches')}."
    )


def test_le_job_de_deploiement_est_rattache_a_l_environnement_vps(
    deploy_workflow: dict[str, Any],
) -> None:
    """Les secrets SSH vivent dans l'environnement « vps » : sans rattachement, ils sont vides."""
    nom, deploiement = _job_de_deploiement(deploy_workflow)
    environnement: object = deploiement.get("environment")
    if isinstance(environnement, dict):
        environnement = environnement.get("name")
    assert environnement == "vps", (
        f"Le job « {nom} » doit déclarer « environment: vps » pour accéder aux secrets SSH."
    )


def test_aucun_identifiant_du_vps_n_est_ecrit_en_clair(deploy_workflow: dict[str, Any]) -> None:
    """Hôte, utilisateur et clé privée viennent de GitHub Secrets, jamais du fichier."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    for ligne in contenu.splitlines():
        cle, separateur, valeur = ligne.partition(":")
        if not separateur or not IDENTIFIANT_SENSIBLE.search(cle):
            continue
        valeur = valeur.strip()
        if not valeur or valeur.startswith("#"):
            continue
        assert REFERENCE_SECRET.search(valeur), (
            f"« {ligne.strip()} » doit référencer ${{{{ secrets.… }}}} et non une valeur littérale."
        )


def test_le_deploiement_attend_la_publication_de_l_image(
    deploy_workflow: dict[str, Any],
) -> None:
    """Déployer avant que l'image soit sur GHCR ferait échouer le « pull » côté VPS."""
    nom_deploiement, deploiement = _job_de_deploiement(deploy_workflow)
    dependances: object = deploiement.get("needs", [])
    if isinstance(dependances, str):
        dependances = [dependances]
    assert isinstance(dependances, list) and dependances, (
        f"Le job « {nom_deploiement} » doit dépendre du job de publication de l'image."
    )


def test_l_image_deployee_est_tracable_jusqu_au_commit(
    deploy_workflow: dict[str, Any],
) -> None:
    """Sans tag porteur du SHA, on ne sait pas quel code tourne sur le VPS."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert "github.sha" in contenu, (
        "L'image publiée puis déployée doit être taguée avec le SHA du commit."
    )

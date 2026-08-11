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
EXPRESSION_GITHUB = re.compile(r"\$\{\{.+\}\}")

# Les clés du workflow sont nommées en français : chercher les seuls termes
# anglais laisserait le contrôle passer par vacuité. Pas de limite de mot non
# plus, sans quoi « CLE_PRIVEE » échapperait à « cle ».
CONFIDENTIEL = re.compile(
    r"(host|hote|key|cle|password|mot_de_passe|passphrase|jeton|token)", re.IGNORECASE
)
# Ce qui identifie un accès (compte SSH, compte GHCR) : peut venir d'un contexte
# GitHub, jamais d'un littéral. « user » seul est exclu à dessein : POSTGRES_USER
# est un nom de rôle applicatif, sans valeur d'authentification.
IDENTIFIANT = re.compile(r"(ssh_user|username|utilisateur|compte|acteur)", re.IGNORECASE)

# Une adresse littérale trahit l'hôte de production ; les versions (v4, 16-3.4)
# ne doivent pas être confondues avec elle.
ADRESSE_IP = re.compile(r"\b(?:\d{1,3}\.){3}\d{1,3}\b")

# Une vraie clé YAML : un identifiant sans espace. Sans ce filtre, la prose des
# commentaires déclenche des faux positifs (« déclencheur » contient « cle »).
CLE_YAML = re.compile(r"^[A-Za-z_][A-Za-z0-9_-]*$")


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
        cle = cle.strip().lstrip("- ")
        valeur = valeur.strip()
        if not separateur or not valeur or not CLE_YAML.match(cle):
            continue

        if CONFIDENTIEL.search(cle):
            assert REFERENCE_SECRET.search(valeur), (
                f"« {ligne.strip()} » doit venir de ${{{{ secrets.… }}}}, jamais d'un littéral."
            )
        elif IDENTIFIANT.search(cle):
            assert EXPRESSION_GITHUB.search(valeur), (
                f"« {ligne.strip()} » doit venir d'un secret ou d'un contexte GitHub, "
                "jamais d'un littéral."
            )


def test_aucune_adresse_de_serveur_n_est_ecrite_dans_le_workflow() -> None:
    """L'adresse du VPS est un secret d'exploitation : elle ne doit pas fuiter par le dépôt."""
    for numero, ligne in enumerate(DEPLOY_WORKFLOW.read_text(encoding="utf-8").splitlines(), 1):
        trouvee = ADRESSE_IP.search(ligne)
        assert trouvee is None, (
            f"Ligne {numero} : adresse littérale « {trouvee.group()} » — "
            "utiliser ${{ secrets.SSH_HOST }}."
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

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


def test_l_image_app_est_construite_et_publiee() -> None:
    """L'app tourne sur le VPS : son image doit être bâtie depuis app/Dockerfile et publiée."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert "valeur-verte-app" in contenu, "Le workflow doit publier l'image « valeur-verte-app »."
    assert "app/Dockerfile" in contenu, "L'image app doit être construite depuis app/Dockerfile."


def test_l_app_est_demarree_au_deploiement() -> None:
    """Publier l'image ne suffit pas : le déploiement doit relancer le service app sur le VPS."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert "docker compose up -d app" in contenu, (
        "Le déploiement doit (re)démarrer le service « app » après les migrations."
    )


def test_la_configuration_du_reverse_proxy_est_televersee() -> None:
    """Le reverse proxy lit un Caddyfile (auth, noindex, TLS) monté depuis le VPS :
    sans téléversement de « infra/caddy », il démarrerait sans sa configuration."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert "infra/caddy" in contenu, (
        "Le déploiement doit téléverser la configuration du reverse proxy (infra/caddy)."
    )


def test_le_cache_app_est_reconstruit_au_deploiement() -> None:
    """L'image app est bâtie avec le domaine par défaut : son cache de routes cible
    *.valeurverte.localhost. APP_DOMAINE n'agit qu'au runtime, or le cache de prod
    n'est pas reconstruit tout seul → sans cache:clear au déploiement, tout
    *.valeur-verte.fr tombe en 404. Le déploiement doit donc reconstruire le cache."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert "cache:clear" in contenu or "cache:warmup" in contenu, (
        "Le déploiement doit reconstruire le cache app (cache:clear) après avoir posé "
        "APP_DOMAINE, sinon les routes restent sur le domaine de build (404)."
    )


def test_le_reverse_proxy_est_demarre_au_deploiement() -> None:
    """Le point d'entrée public doit être (re)lancé au déploiement, sinon le site est
    injoignable — et une nouvelle image app n'est servie qu'après relance du proxy."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert re.search(r"up -d [^\n&|]*\bcaddy\b", contenu), (
        "Le déploiement doit démarrer le reverse proxy « caddy » (ex. « up -d app caddy »)."
    )


def test_le_role_applicatif_est_provisionne_au_deploiement() -> None:
    """L'app se connecte avec le rôle « app » : sans provisionnement au déploiement,
    le rôle n'existe pas (ou son mot de passe diverge du secret) et l'app tombe en
    500. Le rôle est (re)posé de façon idempotente à chaque livraison."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert "provisionner-app.sql" in contenu, (
        "Le déploiement doit provisionner le rôle « app » (infra/provisionner-app.sql)."
    )


def test_les_migrations_app_sont_jouees_au_deploiement() -> None:
    """Le service « migrate » ne couvre que le schéma « data » (pipeline Python). Sans
    les migrations Doctrine, le schéma « app » reste vide et l'app tombe en 500."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert "doctrine:migrations:migrate" in contenu, (
        "Le déploiement doit jouer les migrations Symfony (schéma « app »)."
    )


def test_le_crontab_est_televerse() -> None:
    """Le service « cron » monte `./cron/crontab` depuis le VPS : sans téléversement,
    Compose crée un répertoire vide à sa place et l'ordonnanceur démarre sans tâche."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert "infra/cron" in contenu, (
        "Le déploiement doit téléverser les tâches planifiées (infra/cron)."
    )


def test_l_ordonnanceur_est_demarre_au_deploiement() -> None:
    """Livrer une nouvelle image sans relancer « cron » laisserait l'ordonnanceur sur
    le code de la version précédente — y compris sur un crontab périmé."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert re.search(r"up -d [^\n&|]*\bcron\b", contenu), (
        "Le déploiement doit démarrer l'ordonnanceur « cron » (ex. « up -d app caddy cron »)."
    )


def test_la_config_montee_est_ecrasee_sans_imbrication() -> None:
    """« scp -r infra/caddy » vers un dossier DÉJÀ existant copie DANS ce dossier
    (caddy/caddy/…, cron/cron/…) au lieu de le remplacer : le montage Compose sert
    alors une version périmée (#368). Le dossier de config distant doit donc être
    effacé avant la recopie, sinon la config figée survit à tous les déploiements."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert re.search(r"rm -rf[^\n]*\bcaddy\b[^\n]*\bcron\b", contenu) or re.search(
        r"rm -rf[^\n]*\bcron\b[^\n]*\bcaddy\b", contenu
    ), (
        "Le déploiement doit effacer le dossier de config distant (caddy, cron) avant "
        "« scp -r », sinon la copie s'imbrique et la config périmée persiste (#368)."
    )


def test_caddy_et_cron_relisent_leur_config_au_deploiement() -> None:
    """« up -d » ne recrée un conteneur que si son image ou sa définition change, pas
    quand seul le CONTENU d'un fichier bind-monté change (Caddyfile, crontab). Sans
    « --force-recreate », caddy et cron resservent la config lue au démarrage
    précédent malgré un fichier à jour sur le VPS (#368)."""
    contenu = DEPLOY_WORKFLOW.read_text(encoding="utf-8")
    assert re.search(r"up -d[^\n&|]*--force-recreate[^\n&|]*\bcaddy\b[^\n&|]*\bcron\b", contenu), (
        "Le déploiement doit forcer la recréation de « caddy » et « cron » "
        "(--force-recreate) pour qu'ils relisent leur configuration montée (#368)."
    )

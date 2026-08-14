"""Invariants d'exploitation du compose de production (`infra/docker-compose.prod.yml`).

Ces tests n'inventorient pas les clés du fichier : chacun encode une règle dont
la violation a un coût réel — base PostGIS ouverte sur Internet, données perdues
au redéploiement, mot de passe de développement promu en production, impossibilité
de revenir en arrière faute de version épinglée.
"""

from __future__ import annotations

import re
from typing import Any

from .conftest import services

# Interpolation compose : ${VAR:-defaut} fournit une valeur de repli,
# ${VAR:?message} fait échouer le démarrage si la variable est absente.
DEFAUT_SILENCIEUX = re.compile(r"\$\{[A-Za-z_][A-Za-z0-9_]*:-")
OBLIGATOIRE = re.compile(r"\$\{[A-Za-z_][A-Za-z0-9_]*:\?")

VOLUME_POSTGRES = "/var/lib/postgresql/data"


def _valeurs_environnement(service: dict[str, Any]) -> list[str]:
    env: object = service.get("environment", {})
    if isinstance(env, dict):
        return [str(valeur) for valeur in env.values()]
    assert isinstance(env, list), "« environment » doit être un dictionnaire ou une liste."
    return [str(entree).partition("=")[2] for entree in env]


PORTS_PUBLICS_AUTORISES = {"80", "443"}


def test_seul_le_reverse_proxy_est_publie_sur_une_interface_publique(
    compose_prod: dict[str, Any],
) -> None:
    """Un port publié sans adresse écoute sur 0.0.0.0. Seul le reverse proxy « caddy »
    est un point d'entrée public volontaire, et uniquement sur HTTP(80)/HTTPS(443) ;
    tout autre service (db, app, migrate) ne se publie que sur la boucle locale, d'où
    il n'est atteignable que par le réseau interne ou un tunnel SSH."""
    for nom, service in services(compose_prod).items():
        for publication in service.get("ports", []):
            tete = str(publication).split(":")[0]
            if tete in {"127.0.0.1", "localhost"}:
                continue
            assert nom == "caddy", (
                f"Le service « {nom} » publie « {publication} » sur une interface publique ; "
                "seul le reverse proxy « caddy » est un point d'entrée public."
            )
            port_publie = tete.split("/")[0]
            assert port_publie in PORTS_PUBLICS_AUTORISES, (
                f"Le reverse proxy ne publie que HTTP(80)/HTTPS(443), reçu « {publication} »."
            )


def test_les_donnees_survivent_a_la_recreation_du_conteneur(
    compose_prod: dict[str, Any],
) -> None:
    """« Une perte de base = la fin du produit » (épic #8) : volume nommé obligatoire."""
    db = services(compose_prod)["db"]
    montages = [str(montage) for montage in db.get("volumes", [])]
    persistants = [montage for montage in montages if montage.endswith(f":{VOLUME_POSTGRES}")]
    assert persistants, f"Le service « db » doit monter un volume sur {VOLUME_POSTGRES}."

    source = persistants[0].split(":")[0]
    assert not source.startswith((".", "/")), (
        f"Le stockage de « db » doit être un volume nommé, pas un chemin hôte ({source})."
    )
    declares: object = compose_prod.get("volumes", {})
    assert isinstance(declares, dict) and source in declares, (
        f"Le volume « {source} » doit être déclaré au niveau racine du compose."
    )


def test_aucune_valeur_de_repli_en_production(compose_prod: dict[str, Any]) -> None:
    """Une variable oubliée doit faire échouer le déploiement, pas retomber sur un défaut.

    Le compose de développement se permet ``${POSTGRES_PASSWORD:-valeur_verte}`` ;
    en production ce repli ferait tourner la base avec un mot de passe public.
    """
    for nom, service in services(compose_prod).items():
        for valeur in _valeurs_environnement(service):
            assert not DEFAUT_SILENCIEUX.search(valeur), (
                f"Le service « {nom} » définit une valeur de repli : {valeur}. "
                "Utiliser ${VAR:?message} pour échouer explicitement."
            )


def test_les_identifiants_de_la_base_sont_exiges_explicitement(
    compose_prod: dict[str, Any],
) -> None:
    """Mot de passe et DSN viennent de l'environnement du VPS, jamais du dépôt."""
    db = services(compose_prod)["db"]
    environnement: object = db.get("environment", {})
    assert isinstance(environnement, dict), "« environment » de db doit être un dictionnaire."
    mot_de_passe = str(environnement.get("POSTGRES_PASSWORD", ""))
    assert OBLIGATOIRE.search(mot_de_passe), (
        "POSTGRES_PASSWORD doit être exigé via ${POSTGRES_PASSWORD:?…} et rester hors du dépôt."
    )


def test_la_base_repart_apres_un_redemarrage_du_vps(compose_prod: dict[str, Any]) -> None:
    """Sans politique de redémarrage, un reboot OVH laisse le service à l'arrêt."""
    db = services(compose_prod)["db"]
    assert db.get("restart") in {"unless-stopped", "always"}, (
        "Le service « db » doit redémarrer automatiquement après un reboot."
    )


def test_l_image_data_est_epinglee_sur_une_version_publiee(
    compose_prod: dict[str, Any],
) -> None:
    """Le VPS tire une image publiée sur GHCR : pas de build sur place, pas de « latest »."""
    migrate = services(compose_prod)["migrate"]
    assert "build" not in migrate, (
        "Le VPS ne construit pas l'image : elle est publiée par la CI puis tirée depuis GHCR."
    )
    image = str(migrate.get("image", ""))
    assert image.startswith("ghcr.io/"), f"L'image doit provenir de GHCR, reçu « {image} »."

    # Le tag suit le nom d'image : découper sur le dernier « : » attraperait
    # le message de ${VAR:?message}.
    tag = image.split("/")[-1].partition(":")[2]
    assert tag != "latest", (
        "Un tag « latest » interdit de savoir ce qui tourne et de revenir en arrière."
    )
    assert OBLIGATOIRE.search(tag), (
        "Le tag d'image doit être injecté au déploiement via ${…:?…} "
        "pour tracer la version déployée."
    )


def test_l_image_app_est_epinglee_sur_une_version_publiee(
    compose_prod: dict[str, Any],
) -> None:
    """Comme l'image data : publiée sur GHCR, tirée par le VPS, jamais « latest »."""
    app = services(compose_prod)["app"]
    assert "build" not in app, (
        "Le VPS ne construit pas l'image app : elle est publiée par la CI puis tirée depuis GHCR."
    )
    image = str(app.get("image", ""))
    assert image.startswith("ghcr.io/"), f"L'image app doit provenir de GHCR, reçu « {image} »."

    tag = image.split("/")[-1].partition(":")[2]
    assert tag != "latest", (
        "Un tag « latest » interdit de savoir ce qui tourne et de revenir en arrière."
    )
    assert OBLIGATOIRE.search(tag), (
        "Le tag de l'image app doit être injecté au déploiement via ${…:?…} "
        "pour tracer la version déployée."
    )


def test_l_app_repart_apres_un_redemarrage_du_vps(compose_prod: dict[str, Any]) -> None:
    """Sans politique de redémarrage, un reboot OVH laisse l'application à l'arrêt."""
    app = services(compose_prod)["app"]
    assert app.get("restart") in {"unless-stopped", "always"}, (
        "Le service « app » doit redémarrer automatiquement après un reboot."
    )


def test_l_app_fait_confiance_au_proxy_pour_generer_des_urls_https(
    compose_prod: dict[str, Any],
) -> None:
    """TLS terminé par le proxy : sans TRUSTED_PROXIES, Symfony génère des URLs http.

    L'app n'est jamais joignable qu'à travers le proxy (publiée sur 127.0.0.1) ;
    elle doit honorer X-Forwarded-Proto pour rediriger la connexion en https.
    """
    app = services(compose_prod)["app"]
    environnement: object = app.get("environment", {})
    assert isinstance(environnement, dict), "« environment » de app doit être un dictionnaire."
    valeur = str(environnement.get("TRUSTED_PROXIES", "")).strip()
    assert valeur, (
        "Le service « app » doit définir TRUSTED_PROXIES pour que Symfony fasse confiance "
        "au reverse proxy et génère des URLs https."
    )


def test_le_reverse_proxy_publie_http_et_https(compose_prod: dict[str, Any]) -> None:
    """Le reverse proxy (Caddy) est le point d'entrée public : il termine TLS et doit
    exposer 443, plus 80 pour la redirection HTTP→HTTPS et le challenge ACME."""
    caddy: object = services(compose_prod).get("caddy")
    assert isinstance(caddy, dict), (
        "Le compose de production doit déclarer le reverse proxy « caddy » (point d'entrée public)."
    )
    publies = {str(p).split(":")[0].split("/")[0] for p in caddy.get("ports", [])}
    assert {"80", "443"} <= publies, (
        f"Le reverse proxy doit publier 80 et 443, reçu : {sorted(publies)}."
    )


def test_le_reverse_proxy_repart_apres_un_redemarrage_du_vps(
    compose_prod: dict[str, Any],
) -> None:
    """Sans politique de redémarrage, un reboot OVH laisserait le site injoignable."""
    caddy = services(compose_prod)["caddy"]
    assert caddy.get("restart") in {"unless-stopped", "always"}, (
        "Le service « caddy » doit redémarrer automatiquement après un reboot."
    )


def test_l_app_connait_son_domaine_de_production(compose_prod: dict[str, Any]) -> None:
    """Sans APP_DOMAINE, les routes de zone restent sur *.valeurverte.localhost (défaut
    de l'image) et tout *.valeur-verte.fr tombe en 404 derrière le proxy. La variable
    doit vivre dans le compose versionné, pas seulement sur le VPS, sinon chaque
    redéploiement fait retomber le site en 404."""
    app = services(compose_prod)["app"]
    environnement: object = app.get("environment", {})
    assert isinstance(environnement, dict), "« environment » de app doit être un dictionnaire."
    assert str(environnement.get("APP_DOMAINE", "")).strip(), (
        "Le service « app » doit définir APP_DOMAINE pour router ses zones en production."
    )


def _env_app(compose_prod: dict[str, Any]) -> dict[str, Any]:
    app = services(compose_prod)["app"]
    environnement: object = app.get("environment", {})
    assert isinstance(environnement, dict), "« environment » de app doit être un dictionnaire."
    return environnement


def test_l_app_est_raccordee_a_sa_base(compose_prod: dict[str, Any]) -> None:
    """Sans DATABASE_URL, l'app retombe sur le défaut de l'image (app@127.0.0.1) et
    ne joint jamais la base : toute page qui touche la base tombe en 500. La DSN
    doit être assemblée ici et exigée explicitement, jamais avec un repli."""
    dsn = str(_env_app(compose_prod).get("DATABASE_URL", ""))
    assert "@db:" in dsn, (
        "L'app doit se connecter au service « db » (et non au 127.0.0.1 baké dans l'image)."
    )
    assert OBLIGATOIRE.search(dsn), (
        "Le mot de passe du rôle « app » doit être exigé via ${APP_DB_PASSWORD:?…}."
    )


def test_l_app_porte_un_secret_applicatif(compose_prod: dict[str, Any]) -> None:
    """APP_SECRET signe les jetons (vérification d'email #105, réinitialisation #194)
    et protège le CSRF. Vide, l'app tourne sur un secret par défaut : à exiger."""
    secret = str(_env_app(compose_prod).get("APP_SECRET", ""))
    assert OBLIGATOIRE.search(secret), (
        "APP_SECRET doit être exigé via ${APP_SECRET:?…} et rester hors du dépôt."
    )


def test_l_app_configure_les_urls_hors_contexte_http(compose_prod: dict[str, Any]) -> None:
    """Les URLs générées hors requête HTTP (CLI, envois asynchrones) retombent sur
    http://localhost sans DEFAULT_URI : elles doivent cibler le domaine de production."""
    default_uri = str(_env_app(compose_prod).get("DEFAULT_URI", "")).strip()
    assert default_uri and "localhost" not in default_uri, (
        "Le service « app » doit définir DEFAULT_URI sur son domaine de production."
    )


def test_l_app_peut_envoyer_des_emails_transactionnels(compose_prod: dict[str, Any]) -> None:
    """Sans clé Brevo, l'envoi d'email échoue (401). La clé est un secret : exigée
    explicitement, jamais un littéral ni un repli."""
    cle = str(_env_app(compose_prod).get("BREVO_API_KEY", ""))
    assert OBLIGATOIRE.search(cle), (
        "BREVO_API_KEY doit être exigé via ${BREVO_API_KEY:?…} et rester hors du dépôt."
    )


def test_l_app_attend_une_base_prete(compose_prod: dict[str, Any]) -> None:
    """L'app partage la base : démarrer avant qu'elle soit prête casse le premier accès."""
    app = services(compose_prod)["app"]
    depends: object = app.get("depends_on", {})
    assert isinstance(depends, dict), (
        "« depends_on » de app doit utiliser la forme longue avec condition."
    )
    assert depends.get("db", {}).get("condition") == "service_healthy", (
        "Le service « app » doit attendre « service_healthy » sur « db »."
    )


def test_les_migrations_attendent_une_base_prete(compose_prod: dict[str, Any]) -> None:
    """Sans attente de healthcheck, le premier déploiement échoue sur une base non initialisée."""
    compose = services(compose_prod)
    assert "healthcheck" in compose["db"], "Le service « db » doit déclarer un healthcheck."

    depends: object = compose["migrate"].get("depends_on", {})
    assert isinstance(depends, dict), (
        "« depends_on » doit utiliser la forme longue avec condition, "
        "sinon l'attente est illusoire."
    )
    assert depends.get("db", {}).get("condition") == "service_healthy", (
        "Le service « migrate » doit attendre « service_healthy » sur « db »."
    )

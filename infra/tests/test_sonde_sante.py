"""Sonde de santé applicative du service « app » (#313).

Le contrôle de santé hérité de l'image interroge l'endpoint d'administration de
FrankenPHP : il dit que le serveur web est debout, pas que l'application
fonctionne. Une base injoignable, une migration à moitié appliquée, un disque
plein — le conteneur reste « healthy » et le produit est mort. Ces tests
contraignent la sonde à interroger l'endpoint applicatif, et le reverse proxy à
laisser une surveillance externe l'atteindre (le VPS peut tomber en entier : une
sonde interne ne le verra jamais).
"""

from __future__ import annotations

import re
from typing import Any

from .conftest import REPO_ROOT, services

CADDYFILE = REPO_ROOT / "infra" / "caddy" / "Caddyfile"

CHEMIN_SANTE = "/sante"
PORT_ADMIN_FRANKENPHP = "2019"


def _commande_sonde(service: dict[str, Any]) -> str:
    sonde: object = service.get("healthcheck")
    assert isinstance(sonde, dict), (
        "Le service « app » doit déclarer son propre healthcheck : celui de l'image "
        "sonde le serveur web, pas l'application."
    )
    assert sonde.get("disable") is not True, (
        "La sonde du service « app » ne doit pas être désactivée."
    )
    test: object = sonde.get("test")
    assert test, "Le healthcheck doit porter une commande « test »."
    return " ".join(str(part) for part in test) if isinstance(test, list) else str(test)


def _caddyfile() -> str:
    assert CADDYFILE.exists(), "Le Caddyfile doit exister."
    return CADDYFILE.read_text(encoding="utf-8")


def test_la_sonde_de_l_app_interroge_l_endpoint_applicatif(compose_prod: dict[str, Any]) -> None:
    """Sonder l'application, pas le serveur web : c'est tout l'objet de #313."""
    commande = _commande_sonde(services(compose_prod)["app"])
    assert CHEMIN_SANTE in commande, (
        f"La sonde du service « app » doit interroger {CHEMIN_SANTE}, reçu : {commande}."
    )


def test_la_sonde_de_l_app_ne_sonde_plus_l_administration_de_frankenphp(
    compose_prod: dict[str, Any],
) -> None:
    """L'endpoint d'administration répond tant que le binaire tourne : il ne sait
    rien de la base ni des migrations. Le garder reviendrait à ne rien sonder."""
    commande = _commande_sonde(services(compose_prod)["app"])
    assert PORT_ADMIN_FRANKENPHP not in commande, (
        "La sonde ne doit plus interroger l'endpoint d'administration de FrankenPHP "
        f"(port {PORT_ADMIN_FRANKENPHP}), qui ignore tout de l'application."
    )


def test_la_sonde_de_l_app_echoue_sur_un_code_d_erreur(compose_prod: dict[str, Any]) -> None:
    """L'endpoint répond 503 quand un contrôle est rouge : sans « --fail », curl
    sort en 0 sur une 503 et le conteneur resterait « healthy » — la sonde
    mentirait exactement dans le cas qu'elle est censée détecter."""
    commande = _commande_sonde(services(compose_prod)["app"])
    assert re.search(r"(--fail|-\w*f)\b", commande), (
        f"La sonde doit échouer sur une réponse d'erreur (curl --fail), reçu : {commande}."
    )


def test_la_sonde_de_l_app_laisse_le_temps_du_demarrage(compose_prod: dict[str, Any]) -> None:
    """Warmup du cache et attente de la base : sans start_period, le conteneur
    s'affiche « unhealthy » à chaque déploiement le temps de démarrer."""
    sonde: object = services(compose_prod)["app"].get("healthcheck")
    assert isinstance(sonde, dict)
    for clef in ("interval", "timeout", "retries", "start_period"):
        assert clef in sonde, f"Le healthcheck du service « app » doit préciser « {clef} »."


def test_la_sonde_est_atteignable_par_une_surveillance_externe() -> None:
    """Le VPS peut tomber en entier — une sonde interne ne le verra jamais. Le
    proxy doit donc servir /sante sans le basic auth de la préversion, sans quoi
    toute surveillance externe ne mesure que le 401."""
    caddy = _caddyfile()
    bloc = re.search(r"handle\s+@sante\s*\{(.*?)\n\t\}", caddy, re.DOTALL)
    assert bloc, (
        "Le Caddyfile doit déclarer un bloc « handle @sante » servant la sonde "
        "avant la porte basic auth."
    )
    assert "basic_auth" not in bloc.group(1), (
        "La sonde ne doit pas être derrière le basic auth : une surveillance externe "
        "ne mesurerait que le 401."
    )
    assert "reverse_proxy" in bloc.group(1), "Le bloc de la sonde doit relayer vers l'app."
    assert caddy.index("@sante") < caddy.index("basic_auth"), (
        "Le matcher de la sonde doit précéder la porte basic auth : Caddy évalue les "
        "blocs « handle » dans l'ordre écrit."
    )

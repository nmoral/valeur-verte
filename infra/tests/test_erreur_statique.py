"""Page d'erreur statique servie par le reverse proxy quand l'app est injoignable (#197).

Quand le conteneur Symfony est arrêté, en crash ou en cours de redéploiement, il
ne peut rendre aucune de ses propres pages d'erreur : c'est Caddy qui doit servir
une page autonome. Autonome au sens strict — aucune dépendance à l'app (ses assets,
ses routes) ni à un service externe : elle doit s'afficher correctement au moment
précis où tout le reste est indisponible.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Any

from .conftest import REPO_ROOT, services

PAGE_STATIQUE = REPO_ROOT / "infra" / "caddy" / "erreur-indisponible.html"
CADDYFILE = REPO_ROOT / "infra" / "caddy" / "Caddyfile"

MONTAGE_ATTENDU = "/etc/caddy/erreur-indisponible.html"


def _page() -> str:
    assert PAGE_STATIQUE.exists(), (
        f"La page d'erreur statique doit exister : {PAGE_STATIQUE.relative_to(REPO_ROOT)}."
    )
    return PAGE_STATIQUE.read_text(encoding="utf-8")


def _caddyfile() -> str:
    assert CADDYFILE.exists(), "Le Caddyfile doit exister."
    return CADDYFILE.read_text(encoding="utf-8")


def test_la_page_statique_porte_l_identite_de_marque() -> None:
    """L'utilisateur doit reconnaître Valeur Verte, pas voir une page d'erreur nue."""
    assert "Valeur Verte" in _page()


def test_la_page_statique_embarque_son_style() -> None:
    """Sans style inline, la page dépendrait d'une feuille externe — donc de l'app."""
    assert "<style" in _page().lower(), "Le CSS doit être inline (page autonome)."


def test_la_page_statique_ne_depend_d_aucune_ressource_externe() -> None:
    """Aucun href/src vers http(s) : ni assets de l'app, ni CDN. Tout est embarqué,
    police système comprise — la page s'affiche même app et réseau tiers coupés."""
    page = _page()
    ressources = re.findall(r'(?:href|src)\s*=\s*["\']([^"\']+)["\']', page, re.IGNORECASE)
    externes = [url for url in ressources if url.startswith(("http://", "https://", "//"))]
    assert not externes, f"La page ne doit référencer aucune ressource externe : {externes}."


def test_la_page_statique_ne_reference_pas_l_app() -> None:
    """Pas de feuille de style de l'app ni de renvoi vers l'upstream indisponible."""
    page = _page().lower()
    assert "<link" not in page or "stylesheet" not in page, (
        "Pas de feuille de style liée : le CSS est inline."
    )
    assert "app:80" not in page


def test_le_caddyfile_sert_la_page_sur_erreur_de_passerelle() -> None:
    """Un bloc handle_errors doit rattraper l'échec de l'upstream et servir la page."""
    caddy = _caddyfile()
    assert "handle_errors" in caddy, (
        "Le Caddyfile doit déclarer un bloc handle_errors pour servir la page statique."
    )
    assert "erreur-indisponible.html" in caddy, (
        "Le bloc d'erreur doit servir le fichier erreur-indisponible.html."
    )


def test_le_caddyfile_indique_un_delai_de_reessai() -> None:
    """Une indisponibilité est temporaire : l'en-tête Retry-After le signale."""
    assert "Retry-After" in _caddyfile()


def test_le_compose_prod_monte_la_page_dans_le_reverse_proxy(
    compose_prod: dict[str, Any],
) -> None:
    """Sans montage, Caddy n'a pas le fichier à servir dans le conteneur."""
    caddy = services(compose_prod)["caddy"]
    montages = [str(v) for v in caddy.get("volumes", [])]
    cible = [m for m in montages if MONTAGE_ATTENDU in m]
    assert cible, f"Le service « caddy » doit monter la page sur {MONTAGE_ATTENDU}."
    assert cible[0].rstrip().endswith(":ro"), "La page doit être montée en lecture seule."
    source = cible[0].split(":")[0]
    assert (REPO_ROOT / "infra" / Path(source.lstrip("./"))).exists(), (
        f"La source du montage doit exister dans le dépôt : {source}."
    )

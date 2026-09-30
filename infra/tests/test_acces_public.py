"""Ouverture du site au public (#402).

Le Caddyfile protégeait tout le site derrière un `basic_auth` de préversion et
posait `X-Robots-Tag: noindex` sur les quatre hôtes. À l'ouverture publique :

- plus de `basic_auth` — l'accès aux zones pro/admin reste porté par
  l'authentification applicative et la vitrine restreinte (#385) ;
- le domaine public `valeur-verte.fr` devient indexable ; `app.`, `admin.` et
  `widget.` (zones privées + widget hébergé par client, D9) restent en noindex.

Tests statiques sur le Caddyfile (même convention que test_sonde_sante /
test_erreur_statique) : pas de démon Caddy requis.
"""

from __future__ import annotations

import re

from .conftest import REPO_ROOT

CADDYFILE = REPO_ROOT / "infra" / "caddy" / "Caddyfile"

PRIVES = ("app.valeur-verte.fr", "admin.valeur-verte.fr", "widget.valeur-verte.fr")


def _caddyfile() -> str:
    assert CADDYFILE.exists(), "Le Caddyfile doit exister."
    return CADDYFILE.read_text(encoding="utf-8")


def test_le_site_est_ouvert_au_public_sans_basic_auth() -> None:
    """Ouverture publique : plus de porte basic auth de préversion nulle part."""
    assert "basic_auth" not in _caddyfile(), (
        "Le site est ouvert au public : le basic_auth de préversion doit être retiré."
    )


def test_le_domaine_public_nest_pas_indexe_via_un_noindex_global() -> None:
    """Le noindex ne s'applique plus à tout le monde : sinon le domaine public ne
    serait pas référençable. Il doit être porté par un matcher d'hôtes."""
    assert not re.search(r'header\s+X-Robots-Tag\s+"noindex', _caddyfile()), (
        "Le noindex ne doit plus être posé inconditionnellement : le domaine public "
        "valeur-verte.fr doit être indexable."
    )


def test_les_zones_privees_restent_en_noindex() -> None:
    """app./admin./widget. restent hors index ; le domaine public, lui, en sort."""
    caddy = _caddyfile()
    entete = re.search(r'header\s+@(\w+)\s+X-Robots-Tag\s+"noindex', caddy)
    assert entete, "Le noindex doit être porté par un matcher d'hôtes (zones privées)."

    matcher = re.search(rf"@{entete.group(1)}\s+host\s+([^\n]+)", caddy)
    assert matcher, f"Le matcher @{entete.group(1)} doit lister ses hôtes."
    hotes = matcher.group(1).split()

    for prive in PRIVES:
        assert prive in hotes, f"{prive} (zone privée) doit rester en noindex."
    assert "valeur-verte.fr" not in hotes, (
        "Le domaine public principal doit être indexable (hors du matcher noindex)."
    )


def test_le_domaine_public_est_bien_servi() -> None:
    """Le domaine public reste un hôte servi par le proxy (adresse du bloc de site)."""
    assert re.search(r"(^|\n)valeur-verte\.fr", _caddyfile()), (
        "valeur-verte.fr doit rester un hôte servi."
    )

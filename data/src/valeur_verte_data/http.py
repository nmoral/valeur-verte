"""Transport HTTP minimal pour les clients d'ingestion (DVF, DPE, BAN…).

Le protocole ``Fetcher`` isole la logique des clients de l'implémentation réseau :
les clients reçoivent un ``Fetcher`` et sont testables hors-ligne avec un faux
transport. ``HttpFetcher`` en est l'implémentation réelle (httpx) ; elle est
elle-même testable via ``httpx.MockTransport`` sans accès réseau.
"""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any, Protocol

import httpx


class Fetcher(Protocol):
    """Récupère du texte ou du JSON depuis une URL."""

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str: ...

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any: ...


class HttpFetcher:
    """Implémentation réelle du ``Fetcher`` au-dessus de httpx."""

    def __init__(self, client: httpx.Client | None = None, timeout: float = 30.0) -> None:
        self._client = client if client is not None else httpx.Client(timeout=timeout)
        # DVF sert ses CSV par une redirection vers un stockage objet : sans
        # suivi automatique, l'ingestion échoue sur toutes les communes. C'est
        # une garantie du composant, y compris pour un client fourni.
        self._client.follow_redirects = True

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        response = self._client.get(url, params=params)
        response.raise_for_status()
        return response.text

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        response = self._client.get(url, params=params)
        response.raise_for_status()
        return response.json()

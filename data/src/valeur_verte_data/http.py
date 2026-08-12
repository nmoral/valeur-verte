"""Transport HTTP minimal pour les clients d'ingestion (DVF, DPE, BAN…).

Le protocole ``Fetcher`` isole la logique des clients de l'implémentation réseau :
les clients reçoivent un ``Fetcher`` et sont testables hors-ligne avec un faux
transport. ``HttpFetcher`` en est l'implémentation réelle (httpx) ; elle est
elle-même testable via ``httpx.MockTransport`` sans accès réseau.
"""

from __future__ import annotations

import time
from collections.abc import Callable, Mapping
from typing import Any, Protocol

import httpx

_TOO_MANY_REQUESTS = 429


class Fetcher(Protocol):
    """Récupère du texte ou du JSON depuis une URL."""

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str: ...

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any: ...


class HttpFetcher:
    """Implémentation réelle du ``Fetcher`` au-dessus de httpx.

    Reprend sur un **429 Too Many Requests** : DVF et surtout ADEME rate-limitent
    sous charge (ingestion en parallèle). Sans reprise, un seul 429 transitoire
    fait échouer toute la commune — elle finit vide. On réessaie avec un délai
    exponentiel, en respectant l'en-tête ``Retry-After`` quand il est fourni.
    """

    def __init__(
        self,
        client: httpx.Client | None = None,
        timeout: float = 30.0,
        *,
        max_retries: int = 5,
        backoff_base: float = 0.5,
        sleep: Callable[[float], None] = time.sleep,
    ) -> None:
        self._client = client if client is not None else httpx.Client(timeout=timeout)
        # DVF sert ses CSV par une redirection vers un stockage objet : sans
        # suivi automatique, l'ingestion échoue sur toutes les communes. C'est
        # une garantie du composant, y compris pour un client fourni.
        self._client.follow_redirects = True
        self._max_retries = max_retries
        self._backoff_base = backoff_base
        self._sleep = sleep

    def get_text(self, url: str, params: Mapping[str, str] | None = None) -> str:
        return self._get(url, params).text

    def get_json(self, url: str, params: Mapping[str, str] | None = None) -> Any:
        return self._get(url, params).json()

    def _get(self, url: str, params: Mapping[str, str] | None) -> httpx.Response:
        attempt = 0
        while True:
            response = self._client.get(url, params=params)
            if response.status_code == _TOO_MANY_REQUESTS and attempt < self._max_retries:
                self._sleep(self._delai(response, attempt))
                attempt += 1
                continue
            response.raise_for_status()
            return response

    def _delai(self, response: httpx.Response, attempt: int) -> float:
        """Délai avant reprise : ``Retry-After`` si fourni, sinon backoff exponentiel."""
        retry_after = response.headers.get("Retry-After", "").strip()
        if retry_after.isdigit():
            return float(retry_after)
        return self._backoff_base * (2.0**attempt)

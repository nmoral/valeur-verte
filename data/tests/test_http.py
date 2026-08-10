"""Tests du transport HTTP (hors-ligne via httpx.MockTransport)."""

from __future__ import annotations

from collections.abc import Callable

import httpx
import pytest

from valeur_verte_data.http import HttpFetcher


def _fetcher(handler: Callable[[httpx.Request], httpx.Response]) -> HttpFetcher:
    return HttpFetcher(client=httpx.Client(transport=httpx.MockTransport(handler)))


def test_get_text_retourne_le_corps() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        assert request.url.path == "/data.csv"
        return httpx.Response(200, text="id;prix\n1;100")

    assert _fetcher(handler).get_text("https://example/data.csv") == "id;prix\n1;100"


def test_get_json_parse_le_corps() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"total": 3})

    assert _fetcher(handler).get_json("https://example/j") == {"total": 3}


def test_get_text_passe_les_parametres() -> None:
    seen: dict[str, str | None] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["q"] = request.url.params.get("q")
        return httpx.Response(200, text="ok")

    _fetcher(handler).get_text("https://example/search", {"q": "toulouse"})

    assert seen["q"] == "toulouse"


def test_get_text_leve_sur_erreur_http() -> None:
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(404, text="absent")

    with pytest.raises(httpx.HTTPStatusError):
        _fetcher(handler).get_text("https://example/missing")


def test_get_json_passe_les_parametres() -> None:
    seen: dict[str, str | None] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["size"] = request.url.params.get("size")
        return httpx.Response(200, json={})

    _fetcher(handler).get_json("https://example/j", {"size": "100"})

    assert seen["size"] == "100"


def test_httpfetcher_construit_un_client_avec_le_timeout_demande() -> None:
    assert HttpFetcher(timeout=12.5)._client.timeout.connect == 12.5


def test_httpfetcher_timeout_par_defaut() -> None:
    assert HttpFetcher()._client.timeout.connect == 30.0

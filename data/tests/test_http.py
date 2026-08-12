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


def test_les_redirections_sont_suivies() -> None:
    """DVF sert ses CSV via une redirection 302 vers un stockage objet OVH.

    Sans suivi automatique, l'ingestion échoue sur *toutes* les communes : le
    défaut ne se voit qu'en conditions réelles, jamais sur un faux transport.
    """

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith("/redirige"):
            return httpx.Response(302, headers={"Location": "https://example/final"})
        return httpx.Response(200, text="contenu")

    assert _fetcher(handler).get_text("https://example/redirige") == "contenu"


def test_httpfetcher_suit_les_redirections_par_defaut() -> None:
    assert HttpFetcher()._client.follow_redirects is True


def _fetcher_sans_attente(
    handler: Callable[[httpx.Request], httpx.Response], **kwargs: object
) -> HttpFetcher:
    return HttpFetcher(
        client=httpx.Client(transport=httpx.MockTransport(handler)),
        sleep=lambda _: None,
        **kwargs,  # type: ignore[arg-type]
    )


def test_reprend_sur_429_puis_reussit() -> None:
    """Un 429 transitoire (rate limiting ADEME/DVF) ne doit pas perdre la commune."""
    appels = {"n": 0}

    def handler(request: httpx.Request) -> httpx.Response:
        appels["n"] += 1
        if appels["n"] == 1:
            return httpx.Response(429, text="slow down")
        return httpx.Response(200, text="ok")

    assert _fetcher_sans_attente(handler).get_text("https://example/x") == "ok"
    assert appels["n"] == 2


def test_abandonne_apres_le_max_de_reprises() -> None:
    appels = {"n": 0}

    def handler(request: httpx.Request) -> httpx.Response:
        appels["n"] += 1
        return httpx.Response(429)

    with pytest.raises(httpx.HTTPStatusError):
        _fetcher_sans_attente(handler, max_retries=3).get_text("https://example/x")

    assert appels["n"] == 4  # 1 tentative + 3 reprises


def test_respecte_le_header_retry_after() -> None:
    delais: list[float] = []
    appels = {"n": 0}

    def handler(request: httpx.Request) -> httpx.Response:
        appels["n"] += 1
        if appels["n"] == 1:
            return httpx.Response(429, headers={"Retry-After": "2"})
        return httpx.Response(200, text="ok")

    fetcher = HttpFetcher(
        client=httpx.Client(transport=httpx.MockTransport(handler)),
        sleep=lambda d: delais.append(d),
    )
    fetcher.get_text("https://example/x")

    assert delais == [2.0]


def test_le_json_reprend_aussi_sur_429() -> None:
    appels = {"n": 0}

    def handler(request: httpx.Request) -> httpx.Response:
        appels["n"] += 1
        if appels["n"] == 1:
            return httpx.Response(429)
        return httpx.Response(200, json={"ok": True})

    assert _fetcher_sans_attente(handler).get_json("https://example/j") == {"ok": True}
    assert appels["n"] == 2

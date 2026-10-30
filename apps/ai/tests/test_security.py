from __future__ import annotations

import time

import httpx
import pytest
from fastapi import Depends, FastAPI
from fastapi.testclient import TestClient

from app.security import (
    InternalSignatureAuth,
    build_signature_header,
    compute_signature,
    parse_signature_header,
    require_internal_signature,
    verify_signature,
)
from tests.helpers import TEST_SECRET, make_settings

SHARED_VECTOR_HEX = "723447fac31e5883463cdebeb32b961445f2ea9dd5877e8c2a5cf685f2f8abd7"


def test_shared_signing_vector() -> None:
    assert compute_signature("test-secret", 1700000000, "POST", "/internal/v1/ping", b'{"a":1}') == SHARED_VECTOR_HEX


def test_header_round_trip_and_verification() -> None:
    header = build_signature_header(TEST_SECRET, "post", "/v1/chat?x=1", b"{}", timestamp=1700000000)
    assert header == f"t=1700000000,v1={compute_signature(TEST_SECRET, 1700000000, 'POST', '/v1/chat?x=1', b'{}')}"
    parsed = parse_signature_header(header)
    assert parsed is not None and parsed.timestamp == 1700000000
    assert verify_signature(
        header, secret=TEST_SECRET, method="POST", path_with_query="/v1/chat?x=1", body=b"{}", now=1700000100
    )


@pytest.mark.parametrize(
    ("method", "path", "body", "now", "secret"),
    [
        ("GET", "/v1/chat?x=1", b"{}", 1700000000, TEST_SECRET),
        ("POST", "/v1/chat?x=2", b"{}", 1700000000, TEST_SECRET),
        ("POST", "/v1/chat?x=1", b'{"a":2}', 1700000000, TEST_SECRET),
        ("POST", "/v1/chat?x=1", b"{}", 1700000301, TEST_SECRET),
        ("POST", "/v1/chat?x=1", b"{}", 1699999699, TEST_SECRET),
        ("POST", "/v1/chat?x=1", b"{}", 1700000000, "other-secret"),
    ],
)
def test_verification_rejects(method: str, path: str, body: bytes, now: int, secret: str) -> None:
    header = build_signature_header(TEST_SECRET, "POST", "/v1/chat?x=1", b"{}", timestamp=1700000000)
    assert not verify_signature(header, secret=secret, method=method, path_with_query=path, body=body, now=now)


@pytest.mark.parametrize("header", [None, "", "garbage", "t=abc,v1=00", "t=1700000000", "v1=abcd"])
def test_malformed_headers_are_rejected(header: str | None) -> None:
    assert not verify_signature(header, secret=TEST_SECRET, method="GET", path_with_query="/", body=b"", now=1700000000)


def test_httpx_auth_signs_path_query_and_body() -> None:
    seen: dict[str, httpx.Request] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["request"] = request
        return httpx.Response(200)

    client = httpx.Client(
        base_url="http://web.test", auth=InternalSignatureAuth(TEST_SECRET), transport=httpx.MockTransport(handler)
    )
    client.post("/internal/v1/documents/7/status", params={"a": "b c"}, json={"status": "indexed"})
    request = seen["request"]
    assert request.headers["X-Service"] == "ai"
    assert len(request.headers["X-Request-Id"]) == 36
    assert verify_signature(
        request.headers["X-Signature"],
        secret=TEST_SECRET,
        method="POST",
        path_with_query="/internal/v1/documents/7/status?a=b+c",
        body=request.content,
    )


@pytest.fixture
def guarded_app(monkeypatch: pytest.MonkeyPatch) -> TestClient:
    monkeypatch.setattr("app.security.get_settings", lambda: make_settings())
    app = FastAPI()

    @app.post("/v1/echo")
    def echo(payload: dict[str, int], service: str = Depends(require_internal_signature)) -> dict[str, object]:
        return {"service": service, "payload": payload}

    return TestClient(app)


def test_dependency_accepts_signed_request_and_keeps_body(guarded_app: TestClient) -> None:
    body = b'{"a":1}'
    headers = {
        "X-Service": "worker",
        "X-Signature": build_signature_header(TEST_SECRET, "POST", "/v1/echo?q=1", body),
        "content-type": "application/json",
    }
    response = guarded_app.post("/v1/echo?q=1", content=body, headers=headers)
    assert response.status_code == 200
    assert response.json() == {"service": "worker", "payload": {"a": 1}}


@pytest.mark.parametrize(
    "headers",
    [
        {},
        {"X-Service": "web"},
        {"X-Service": "stranger", "X-Signature": "computed"},
        {"X-Service": "web", "X-Signature": f"t={int(time.time()) - 1000},v1=deadbeef"},
    ],
)
def test_dependency_rejects_unsigned_or_unknown(guarded_app: TestClient, headers: dict[str, str]) -> None:
    body = b'{"a":1}'
    if headers.get("X-Signature") == "computed":
        headers = {**headers, "X-Signature": build_signature_header(TEST_SECRET, "POST", "/v1/echo", body)}
    response = guarded_app.post("/v1/echo", content=body, headers={**headers, "content-type": "application/json"})
    assert response.status_code == 401


def test_signing_secret_fallback_and_production_guard() -> None:
    from pydantic import ValidationError

    from app.config import DEV_FALLBACK_SECRET

    assert make_settings(internal_api_secret="").signing_secret == DEV_FALLBACK_SECRET
    assert make_settings(internal_api_secret="  s3cret ").signing_secret == "s3cret"
    with pytest.raises(ValidationError, match="INTERNAL_KEY_WEB_AI"):
        make_settings(app_env="production", internal_api_secret="x" * 32)
    with pytest.raises(ValidationError, match="INTERNAL_KEY_WORKER_AI"):
        make_settings(app_env="production", internal_key_web_ai="w" * 32, internal_key_worker_ai="short")
    with pytest.raises(ValidationError, match="must differ"):
        make_settings(app_env="production", internal_key_web_ai="k" * 32, internal_key_worker_ai="k" * 32)
    production = make_settings(app_env="production", internal_key_web_ai="w" * 32, internal_key_worker_ai="k" * 32)
    assert production.is_production


def test_peer_secret_picks_the_pair_key_and_falls_back_outside_production() -> None:
    settings = make_settings(internal_key_web_ai="w" * 32, internal_key_worker_ai="k" * 32)
    assert settings.peer_secret("web") == "w" * 32
    assert settings.peer_secret("worker") == "k" * 32
    assert settings.peer_secret("ai") is None
    assert settings.peer_secret("stranger") is None
    assert make_settings().peer_secret("worker") == TEST_SECRET


def test_dependency_rejects_a_web_key_presented_as_worker(monkeypatch: pytest.MonkeyPatch) -> None:
    settings = make_settings(internal_key_web_ai="w" * 32, internal_key_worker_ai="k" * 32)
    monkeypatch.setattr("app.security.get_settings", lambda: settings)
    app = FastAPI()

    @app.post("/v1/echo")
    def echo(service: str = Depends(require_internal_signature)) -> dict[str, str]:
        return {"service": service}

    client = TestClient(app)
    body = b"{}"
    as_worker = {"X-Service": "worker", "content-type": "application/json"}
    forged_signature = build_signature_header("w" * 32, "POST", "/v1/echo", body)
    forged = client.post("/v1/echo", content=body, headers={**as_worker, "X-Signature": forged_signature})
    assert forged.status_code == 401
    genuine_signature = build_signature_header("k" * 32, "POST", "/v1/echo", body)
    genuine = client.post("/v1/echo", content=body, headers={**as_worker, "X-Signature": genuine_signature})
    assert genuine.status_code == 200
    assert genuine.json() == {"service": "worker"}

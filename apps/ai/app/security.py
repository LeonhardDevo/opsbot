from __future__ import annotations

import hashlib
import hmac
import time
import uuid
from collections.abc import Generator
from contextvars import ContextVar
from dataclasses import dataclass

import httpx
from fastapi import HTTPException, Request, status

from app.config import get_settings

SIGNATURE_HEADER = "X-Signature"
SERVICE_HEADER = "X-Service"
REQUEST_ID_HEADER = "X-Request-Id"
KNOWN_SERVICES = frozenset({"web", "worker", "ai"})

request_id_var: ContextVar[str | None] = ContextVar("request_id", default=None)


def current_request_id() -> str:
    return request_id_var.get() or str(uuid.uuid4())


def compute_signature(secret: str, timestamp: int, method: str, path_with_query: str, body: bytes) -> str:
    message = b".".join([str(timestamp).encode(), method.upper().encode(), path_with_query.encode(), body])
    return hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()


def build_signature_header(
    secret: str, method: str, path_with_query: str, body: bytes, timestamp: int | None = None
) -> str:
    ts = int(time.time()) if timestamp is None else timestamp
    return f"t={ts},v1={compute_signature(secret, ts, method, path_with_query, body)}"


@dataclass(frozen=True, slots=True)
class ParsedSignature:
    timestamp: int
    signatures: tuple[str, ...]


def parse_signature_header(header: str | None) -> ParsedSignature | None:
    if not header:
        return None
    timestamp: int | None = None
    signatures: list[str] = []
    for part in header.split(","):
        key, _, value = part.strip().partition("=")
        if key == "t" and value.isdigit():
            timestamp = int(value)
        elif key == "v1" and value:
            signatures.append(value.strip().lower())
    if timestamp is None or not signatures:
        return None
    return ParsedSignature(timestamp, tuple(signatures))


def verify_signature(
    header: str | None,
    *,
    secret: str,
    method: str,
    path_with_query: str,
    body: bytes,
    tolerance: int = 300,
    now: int | None = None,
) -> bool:
    parsed = parse_signature_header(header)
    if parsed is None or not secret:
        return False
    current = int(time.time()) if now is None else now
    if abs(current - parsed.timestamp) > tolerance:
        return False
    expected = compute_signature(secret, parsed.timestamp, method, path_with_query, body)
    matches = [hmac.compare_digest(expected, candidate) for candidate in parsed.signatures]
    return any(matches)


def request_path_with_query(request: Request) -> str:
    raw_path: bytes = request.scope.get("raw_path") or request.url.path.encode()
    query: bytes = request.scope.get("query_string") or b""
    path = raw_path.decode("latin-1")
    return f"{path}?{query.decode('latin-1')}" if query else path


async def require_internal_signature(request: Request) -> str:
    container = getattr(request.app.state, "container", None)
    settings = container.settings if container is not None else get_settings()
    body = await request.body()
    service = request.headers.get(SERVICE_HEADER, "").strip().lower()
    secret = settings.peer_secret(service) if service in KNOWN_SERVICES else None
    ok = secret is not None and verify_signature(
        request.headers.get(SIGNATURE_HEADER),
        secret=secret,
        method=request.method,
        path_with_query=request_path_with_query(request),
        body=body,
        tolerance=settings.internal_api_tolerance,
    )
    if not ok:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="invalid_signature")
    return service


class InternalSignatureAuth(httpx.Auth):
    requires_request_body = True

    def __init__(self, secret: str, service: str = "ai") -> None:
        self._secret = secret
        self._service = service

    def auth_flow(self, request: httpx.Request) -> Generator[httpx.Request, httpx.Response, None]:
        path = request.url.raw_path.decode("latin-1")
        request.headers[SERVICE_HEADER] = self._service
        request.headers.setdefault(REQUEST_ID_HEADER, current_request_id())
        request.headers[SIGNATURE_HEADER] = build_signature_header(self._secret, request.method, path, request.content)
        yield request

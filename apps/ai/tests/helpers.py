from __future__ import annotations

import io
import json
from collections.abc import Callable
from dataclasses import dataclass, field
from typing import Any

import httpx

from app.config import Settings
from app.providers.types import ChatRequest, ChatResult, ChatUsage, ProviderError
from app.security import build_signature_header, verify_signature
from app.settings_store import SettingsStore

TEST_SECRET = "test-secret"


def make_settings(**overrides: Any) -> Settings:
    values: dict[str, Any] = {
        "app_env": "testing",
        "internal_api_secret": TEST_SECRET,
        "ai_settings_key": "unit-test-ai-settings-key",
        "embeddings_provider": "hash",
        "embeddings_model": "hash-384",
        "ai_provider": "mock",
        "ai_model": "",
        "web_url": "http://web.test",
        "product_name": "TestProduct",
        "product_slug": "testproduct",
        "rag_min_confidence": 0.35,
        "ai_retry_base_seconds": 0.0,
        "log_level": "WARNING",
        "anthropic_api_key": None,
        "openai_api_key": None,
        "deepseek_api_key": None,
        "openrouter_api_key": None,
        "nvidia_api_key": None,
        "opencode_zen_api_key": None,
        "opencode_go_api_key": None,
    }
    values.update(overrides)
    return Settings(_env_file=None, **values)


class InMemorySettingsStore(SettingsStore):
    def __init__(self, settings: Settings) -> None:
        super().__init__(session_factory=None, settings=settings)  # type: ignore[arg-type]
        self.raw: dict[str, Any] = {}

    def get_raw(self, key: str, default: Any = None) -> Any:
        value = self.raw.get(key)
        return default if value is None else json.loads(json.dumps(value))

    def set_raw(self, key: str, value: Any) -> None:
        self.raw[key] = json.loads(json.dumps(value))


@dataclass
class ScriptedProvider:
    steps: list[ChatResult | Exception]
    provider_id: str = "fake"
    calls: list[ChatRequest] = field(default_factory=list)

    @property
    def id(self) -> str:
        return self.provider_id

    def chat(self, req: ChatRequest) -> ChatResult:
        self.calls.append(req)
        step = self.steps.pop(0) if len(self.steps) > 1 else self.steps[0]
        if isinstance(step, Exception):
            raise step
        return step


def text_result(text: str, prompt: int = 10, completion: int = 5) -> ChatResult:
    return ChatResult(
        content=text, tool_calls=[], usage=ChatUsage(prompt, completion, 0), finish_reason="stop", served_model=None
    )


def rate_limited(retry_after: float | None = None) -> ProviderError:
    return ProviderError("rate limited", status=429, retry_after=retry_after)


@dataclass
class FakeWeb:
    secret: str = TEST_SECRET
    files: dict[int, tuple[bytes, str, str]] = field(default_factory=dict)
    statuses: list[tuple[int, dict[str, Any]]] = field(default_factory=list)
    ai_queries: list[dict[str, Any]] = field(default_factory=list)
    ai_query_request_ids: list[str | None] = field(default_factory=list)
    unsigned_requests: int = 0
    fail_status_reports: bool = False

    def handler(self, request: httpx.Request) -> httpx.Response:
        path = request.url.raw_path.decode()
        if request.headers.get("X-Service") != "ai" or not verify_signature(
            request.headers.get("X-Signature"),
            secret=self.secret,
            method=request.method,
            path_with_query=path,
            body=request.content,
        ):
            self.unsigned_requests += 1
            return httpx.Response(401, json={"message": "invalid signature"})
        parts = request.url.path.strip("/").split("/")
        if request.method == "GET" and parts[:3] == ["internal", "v1", "documents"] and parts[-1] == "file":
            stored = self.files.get(int(parts[3]))
            if stored is None:
                return httpx.Response(404)
            content, mime, name = stored
            return httpx.Response(
                200,
                content=content,
                headers={"content-type": mime, "content-disposition": f'attachment; filename="{name}"'},
            )
        if request.method == "POST" and parts[:3] == ["internal", "v1", "documents"] and parts[-1] == "status":
            if self.fail_status_reports:
                return httpx.Response(500)
            self.statuses.append((int(parts[3]), json.loads(request.content)))
            return httpx.Response(204)
        if request.method == "POST" and request.url.path == "/internal/v1/ai-queries":
            self.ai_queries.append(json.loads(request.content))
            self.ai_query_request_ids.append(request.headers.get("X-Request-Id"))
            return httpx.Response(201, json={"id": len(self.ai_queries)})
        return httpx.Response(404)

    def transport(self) -> httpx.MockTransport:
        return httpx.MockTransport(self.handler)


def signed_headers(method: str, path_with_query: str, body: bytes = b"", service: str = "web") -> dict[str, str]:
    return {
        "X-Service": service,
        "X-Request-Id": "11111111-2222-3333-4444-555555555555",
        "X-Signature": build_signature_header(TEST_SECRET, method, path_with_query, body),
        "content-type": "application/json",
    }


class SignedClient:
    def __init__(self, client: Any) -> None:
        self.client = client

    def request(self, method: str, path: str, payload: Any | None = None, service: str = "web") -> httpx.Response:
        body = b"" if payload is None else json.dumps(payload).encode()
        headers = signed_headers(method, path, body, service)
        return self.client.request(method, path, content=body or None, headers=headers)

    def raw(
        self, method: str, path: str, body: bytes, headers: dict[str, str] | None = None, service: str = "worker"
    ) -> httpx.Response:
        signed = signed_headers(method, path, body, service)
        signed.update(headers or {})
        return self.client.request(method, path, content=body, headers=signed)

    def patch(self, path: str, payload: Any | None = None) -> httpx.Response:
        return self.request("PATCH", path, payload)

    def get(self, path: str) -> httpx.Response:
        return self.request("GET", path)

    def post(self, path: str, payload: Any | None = None) -> httpx.Response:
        return self.request("POST", path, payload)

    def put(self, path: str, payload: Any | None = None) -> httpx.Response:
        return self.request("PUT", path, payload)

    def delete(self, path: str) -> httpx.Response:
        return self.request("DELETE", path)


def make_text_pdf(pages: list[list[str]]) -> bytes:

    def esc(line: str) -> str:
        return line.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")

    objects: list[bytes] = []
    page_ids = [4 + i * 2 for i in range(len(pages))]
    objects.append(b"<< /Type /Catalog /Pages 2 0 R >>")
    kids = " ".join(f"{pid} 0 R" for pid in page_ids)
    objects.append(f"<< /Type /Pages /Kids [{kids}] /Count {len(pages)} >>".encode())
    objects.append(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>")
    for page_index, lines in enumerate(pages):
        content_id = page_ids[page_index] + 1
        objects.append(
            f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {content_id} 0 R "
            f"/Resources << /Font << /F1 3 0 R >> >> >>".encode()
        )
        ops = ["BT", "/F1 12 Tf", "72 720 Td", "14 TL"]
        for line in lines:
            ops.append(f"({esc(line)}) Tj T*")
        ops.append("ET")
        stream = "\n".join(ops).encode("latin-1")
        objects.append(b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"\nendstream")
    out = io.BytesIO()
    out.write(b"%PDF-1.4\n")
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(out.tell())
        out.write(f"{number} 0 obj\n".encode() + body + b"\nendobj\n")
    xref = out.tell()
    out.write(f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n".encode())
    for offset in offsets:
        out.write(f"{offset:010d} 00000 n \n".encode())
    out.write(f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode())
    return out.getvalue()


def make_text_image(lines: list[str], *, width: int = 1400, size: int = 44) -> Any:
    from PIL import Image, ImageDraw, ImageFont

    height = 80 + len(lines) * (size + 24)
    image = Image.new("RGB", (width, height), "white")
    draw = ImageDraw.Draw(image)
    font = ImageFont.load_default(size=size)
    for i, line in enumerate(lines):
        draw.text((40, 40 + i * (size + 24)), line, fill="black", font=font)
    return image


def image_bytes(image: Any, fmt: str = "PNG") -> bytes:
    buffer = io.BytesIO()
    image.save(buffer, fmt)
    return buffer.getvalue()


def make_docx(build: Callable[[Any], None]) -> bytes:
    import docx

    document = docx.Document()
    build(document)
    buffer = io.BytesIO()
    document.save(buffer)
    return buffer.getvalue()

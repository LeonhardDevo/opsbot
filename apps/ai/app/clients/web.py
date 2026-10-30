from __future__ import annotations

from dataclasses import dataclass
from typing import Any
from urllib.parse import unquote

import httpx
from tenacity import retry, retry_if_exception, stop_after_attempt, wait_exponential

from app.config import Settings
from app.security import REQUEST_ID_HEADER, InternalSignatureAuth


class WebApiError(RuntimeError):
    def __init__(self, message: str, status: int | None = None) -> None:
        super().__init__(message)
        self.status = status


class FileTooLargeError(WebApiError):
    pass


@dataclass(frozen=True, slots=True)
class DownloadedFile:
    content: bytes
    content_type: str | None
    filename: str | None


def _is_retryable(exc: BaseException) -> bool:
    if isinstance(exc, FileTooLargeError):
        return False
    if isinstance(exc, httpx.TransportError):
        return True
    return isinstance(exc, WebApiError) and (exc.status is None or exc.status >= 500 or exc.status == 429)


def filename_from_disposition(value: str | None) -> str | None:
    if not value:
        return None
    plain: str | None = None
    for part in value.split(";"):
        key, _, raw = part.strip().partition("=")
        if key.lower() == "filename*" and "''" in raw:
            return unquote(raw.split("''", 1)[1])
        if key.lower() == "filename":
            plain = raw.strip('"')
    return plain


_RETRY = retry(
    retry=retry_if_exception(_is_retryable), stop=stop_after_attempt(3), wait=wait_exponential(0.5, max=4), reraise=True
)


class WebClient:
    def __init__(self, settings: Settings, transport: httpx.BaseTransport | None = None) -> None:
        self._max_bytes = settings.max_file_mb * 1024 * 1024
        self._client = httpx.Client(
            base_url=settings.web_url,
            auth=InternalSignatureAuth(settings.peer_secret("web") or settings.signing_secret, settings.service_name),
            timeout=settings.web_timeout_seconds,
            transport=transport,
            headers={"accept": "application/json"},
        )

    def close(self) -> None:
        self._client.close()

    @staticmethod
    def _raise_for_status(response: httpx.Response, what: str) -> None:
        if response.status_code >= 400:
            raise WebApiError(f"{what}: HTTP {response.status_code}", status=response.status_code)

    @_RETRY
    def download_document(self, document_id: int) -> DownloadedFile:
        too_large = f"file exceeds {self._max_bytes // (1024 * 1024)} MB"
        with self._client.stream("GET", f"/internal/v1/documents/{document_id}/file") as response:
            self._raise_for_status(response, "download document")
            declared = response.headers.get("content-length")
            if declared and declared.isdigit() and int(declared) > self._max_bytes:
                raise FileTooLargeError(too_large, status=413)
            parts: list[bytes] = []
            total = 0
            for part in response.iter_bytes():
                total += len(part)
                if total > self._max_bytes:
                    raise FileTooLargeError(too_large, status=413)
                parts.append(part)
            return DownloadedFile(
                content=b"".join(parts),
                content_type=response.headers.get("content-type"),
                filename=filename_from_disposition(response.headers.get("content-disposition")),
            )

    @_RETRY
    def report_document_status(self, document_id: int, payload: dict[str, Any]) -> None:
        response = self._client.post(f"/internal/v1/documents/{document_id}/status", json=payload)
        self._raise_for_status(response, "report document status")

    def log_ai_query(self, payload: dict[str, Any], request_id: str | None = None) -> None:
        headers = {REQUEST_ID_HEADER: request_id} if request_id else None
        response = self._client.post("/internal/v1/ai-queries", json=payload, headers=headers)
        self._raise_for_status(response, "log ai query")

    def get_json(self, path: str, params: dict[str, Any] | None = None) -> Any:
        response = self._client.get(path, params=params)
        self._raise_for_status(response, f"GET {path}")
        return response.json()

    def get_optional_json(self, path: str, params: dict[str, Any] | None = None) -> Any:
        response = self._client.get(path, params={k: v for k, v in (params or {}).items() if v is not None})
        if response.status_code in (403, 404):
            return None
        self._raise_for_status(response, f"GET {path}")
        return response.json()

    def post_json(self, path: str, payload: dict[str, Any]) -> Any:
        response = self._client.post(path, json=payload)
        self._raise_for_status(response, f"POST {path}")
        return response.json() if response.content else None

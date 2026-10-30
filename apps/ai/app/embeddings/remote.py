from __future__ import annotations

from collections.abc import Sequence

import httpx

from app.embeddings.base import EmbeddingError, l2_normalize


class RemoteEmbedder:
    def __init__(
        self,
        *,
        base_url: str,
        model: str,
        api_key: str | None,
        dims: int,
        batch_size: int = 64,
        http_client: httpx.Client | None = None,
        timeout: float = 60.0,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._model = model
        self._api_key = api_key
        self._dims = dims
        self._batch_size = batch_size
        self._http = http_client or httpx.Client(timeout=timeout)

    @property
    def model_id(self) -> str:
        return f"openai-compatible:{self._model}"

    @property
    def dims(self) -> int:
        return self._dims

    @property
    def calibration(self) -> tuple[float, float]:
        return (0.15, 0.65)

    def _request(self, inputs: list[str]) -> list[list[float]]:
        headers = {"authorization": f"Bearer {self._api_key}"} if self._api_key else {}
        try:
            response = self._http.post(
                f"{self._base_url}/embeddings", json={"model": self._model, "input": inputs}, headers=headers
            )
        except httpx.HTTPError as exc:
            raise EmbeddingError(f"embeddings request failed: {exc}") from exc
        if response.status_code >= 400:
            raise EmbeddingError(f"embeddings HTTP {response.status_code}: {response.text[:300]}")
        data = sorted(response.json().get("data") or [], key=lambda item: item.get("index", 0))
        vectors = [l2_normalize(item["embedding"]) for item in data]
        if len(vectors) != len(inputs):
            raise EmbeddingError("embeddings response size mismatch")
        for vector in vectors:
            if len(vector) != self._dims:
                raise EmbeddingError(f"embedding has {len(vector)} dims, EMBEDDINGS_DIMS is {self._dims}")
        return vectors

    def embed_documents(self, texts: Sequence[str]) -> list[list[float]]:
        out: list[list[float]] = []
        items = list(texts)
        for start in range(0, len(items), self._batch_size):
            out.extend(self._request(items[start : start + self._batch_size]))
        return out

    def embed_query(self, text: str) -> list[float]:
        return self._request([text])[0]

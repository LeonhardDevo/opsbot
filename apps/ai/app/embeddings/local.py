from __future__ import annotations

import threading
from collections.abc import Sequence
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from app.embeddings.base import EmbeddingError, l2_normalize
from app.observability import get_logger

log = get_logger(__name__)


@dataclass(frozen=True, slots=True)
class LocalModelSpec:
    dims: int
    window_words: int
    overlap_words: int
    query_prefix: str = ""
    passage_prefix: str = ""
    calibration: tuple[float, float] = (0.2, 0.75)


LOCAL_MODEL_SPECS: dict[str, LocalModelSpec] = {
    "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2": LocalModelSpec(
        dims=384, window_words=64, overlap_words=16, calibration=(0.2, 0.7)
    ),
    "sentence-transformers/paraphrase-multilingual-mpnet-base-v2": LocalModelSpec(
        dims=768, window_words=256, overlap_words=32, calibration=(0.2, 0.7)
    ),
    "intfloat/multilingual-e5-large": LocalModelSpec(
        dims=1024,
        window_words=300,
        overlap_words=40,
        query_prefix="query: ",
        passage_prefix="passage: ",
        calibration=(0.74, 0.9),
    ),
    "BAAI/bge-small-en-v1.5": LocalModelSpec(dims=384, window_words=300, overlap_words=40, calibration=(0.45, 0.85)),
}
DEFAULT_SPEC = LocalModelSpec(dims=384, window_words=200, overlap_words=30)


def word_windows(text: str, size: int, overlap: int) -> list[str]:
    tokens = text.split()
    if len(tokens) <= size:
        return [text]
    step = max(1, size - overlap)
    windows = []
    for start in range(0, len(tokens), step):
        windows.append(" ".join(tokens[start : start + size]))
        if start + size >= len(tokens):
            break
    return windows


class LocalEmbedder:
    def __init__(self, model_name: str, cache_dir: Path, batch_size: int = 32) -> None:
        self._model_name = model_name
        self._cache_dir = cache_dir
        self._batch_size = batch_size
        self._spec = LOCAL_MODEL_SPECS.get(model_name, DEFAULT_SPEC)
        self._model: Any = None
        self._lock = threading.Lock()

    @property
    def model_id(self) -> str:
        return f"local:{self._model_name}"

    @property
    def dims(self) -> int:
        return self._spec.dims

    @property
    def calibration(self) -> tuple[float, float]:
        return self._spec.calibration

    def load(self) -> None:
        with self._lock:
            if self._model is not None:
                return
            try:
                from fastembed import TextEmbedding

                self._cache_dir.mkdir(parents=True, exist_ok=True)
                self._model = TextEmbedding(model_name=self._model_name, cache_dir=str(self._cache_dir))
            except Exception as exc:
                raise EmbeddingError(f"cannot load local embedding model {self._model_name}: {exc}") from exc
            log.info("embedding_model_loaded", model=self._model_name, cache_dir=str(self._cache_dir))

    def _embed(self, texts: Sequence[str], prefix: str) -> list[list[float]]:
        self.load()
        windows: list[str] = []
        owners: list[int] = []
        for index, text in enumerate(texts):
            for window in word_windows(text, self._spec.window_words, self._spec.overlap_words):
                windows.append(prefix + window)
                owners.append(index)
        sums = [[0.0] * self._spec.dims for _ in texts]
        counts = [0] * len(texts)
        try:
            vectors = self._model.embed(windows, batch_size=self._batch_size)
            for owner, vector in zip(owners, vectors, strict=True):
                values = vector.tolist()
                if len(values) != self._spec.dims:
                    raise EmbeddingError(f"model returned {len(values)} dims, expected {self._spec.dims}")
                acc = sums[owner]
                for i, value in enumerate(values):
                    acc[i] += value
                counts[owner] += 1
        except EmbeddingError:
            raise
        except Exception as exc:
            raise EmbeddingError(f"local embedding failed: {exc}") from exc
        return [l2_normalize([v / max(1, counts[i]) for v in acc]) for i, acc in enumerate(sums)]

    def embed_documents(self, texts: Sequence[str]) -> list[list[float]]:
        return self._embed(texts, self._spec.passage_prefix) if texts else []

    def embed_query(self, text: str) -> list[float]:
        return self._embed([text], self._spec.query_prefix)[0]

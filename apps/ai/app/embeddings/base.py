from __future__ import annotations

import math
from collections.abc import Sequence
from typing import Protocol


class EmbeddingError(RuntimeError):
    pass


class Embedder(Protocol):
    @property
    def model_id(self) -> str: ...

    @property
    def dims(self) -> int: ...

    @property
    def calibration(self) -> tuple[float, float]: ...

    def embed_documents(self, texts: Sequence[str]) -> list[list[float]]: ...

    def embed_query(self, text: str) -> list[float]: ...


def l2_normalize(vector: Sequence[float]) -> list[float]:
    norm = math.sqrt(sum(v * v for v in vector))
    if norm == 0:
        return [0.0 for _ in vector]
    return [float(v) / norm for v in vector]


def calibrate(similarity: float, calibration: tuple[float, float]) -> float:
    floor, ceiling = calibration
    if ceiling <= floor:
        return max(0.0, min(1.0, similarity))
    return max(0.0, min(1.0, (similarity - floor) / (ceiling - floor)))

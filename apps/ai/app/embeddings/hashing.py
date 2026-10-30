from __future__ import annotations

import hashlib
from collections.abc import Sequence
from itertools import pairwise

from app.embeddings.base import l2_normalize
from app.text import content_terms

HASH_MODEL = "hash-384"


class HashEmbedder:
    def __init__(self, dims: int = 384) -> None:
        self._dims = dims

    @property
    def model_id(self) -> str:
        return f"hash:{HASH_MODEL if self._dims == 384 else f'hash-{self._dims}'}"

    @property
    def dims(self) -> int:
        return self._dims

    @property
    def calibration(self) -> tuple[float, float]:
        return (0.05, 0.55)

    def _vector(self, text: str) -> list[float]:
        vector = [0.0] * self._dims
        terms = content_terms(text)
        features: list[tuple[str, float]] = [(t, 1.0) for t in terms]
        features += [(f"{a}_{b}", 0.5) for a, b in pairwise(terms)]
        for feature, weight in features:
            digest = hashlib.blake2b(feature.encode(), digest_size=8).digest()
            index = int.from_bytes(digest[:4], "little") % self._dims
            sign = 1.0 if digest[4] & 1 else -1.0
            vector[index] += sign * weight
        return l2_normalize(vector)

    def embed_documents(self, texts: Sequence[str]) -> list[list[float]]:
        return [self._vector(t) for t in texts]

    def embed_query(self, text: str) -> list[float]:
        return self._vector(text)

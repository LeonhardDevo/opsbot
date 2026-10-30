from __future__ import annotations

import json
import math
from typing import Any

import httpx
import numpy as np
import pytest

from app.crypto import secret_box_for
from app.embeddings.base import EmbeddingError, calibrate, l2_normalize
from app.embeddings.hashing import HashEmbedder
from app.embeddings.local import LocalEmbedder, word_windows
from app.embeddings.registry import EmbeddingsRegistry
from app.embeddings.remote import RemoteEmbedder
from app.providers.keys import ProviderKeys
from tests.helpers import InMemorySettingsStore, make_settings


def _cos(a: list[float], b: list[float]) -> float:
    return sum(x * y for x, y in zip(a, b, strict=True))


def test_hash_embeddings_are_deterministic_normalized_and_lexical() -> None:
    embedder = HashEmbedder()
    vacation = embedder.embed_query("How many vacation days do employees get?")
    assert embedder.embed_query("How many vacation days do employees get?") == vacation
    assert len(vacation) == 384 and math.isclose(math.sqrt(sum(v * v for v in vacation)), 1.0, rel_tol=1e-9)
    related, unrelated = embedder.embed_documents(
        ["Employees get 28 vacation days per year.", "The office kitchen is cleaned on Fridays."]
    )
    assert _cos(vacation, related) > 0.3 > _cos(vacation, unrelated)
    assert embedder.model_id == "hash:hash-384"
    assert HashEmbedder(dims=128).model_id == "hash:hash-128"


def test_normalize_and_calibrate() -> None:
    assert l2_normalize([0.0, 0.0]) == [0.0, 0.0]
    assert l2_normalize([3.0, 4.0]) == [0.6, 0.8]
    assert calibrate(0.05, (0.1, 0.6)) == 0.0
    assert calibrate(0.35, (0.1, 0.6)) == pytest.approx(0.5)
    assert calibrate(0.9, (0.1, 0.6)) == 1.0


def test_word_windows_overlap() -> None:
    text = " ".join(f"w{i}" for i in range(150))
    windows = word_windows(text, size=64, overlap=16)
    assert windows[0].split()[0] == "w0" and windows[1].split()[0] == "w48"
    assert windows[-1].split()[-1] == "w149"
    assert word_windows("short text", 64, 16) == ["short text"]


class _FakeFastEmbed:
    def __init__(self, dims: int) -> None:
        self.dims = dims
        self.seen: list[str] = []

    def embed(self, texts: list[str], batch_size: int = 32) -> Any:
        for text in texts:
            self.seen.append(text)
            vector = np.zeros(self.dims)
            vector[len(text.split()) % self.dims] = 1.0
            yield vector


def test_local_embedder_mean_pools_long_texts(tmp_path: Any) -> None:
    embedder = LocalEmbedder("sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2", tmp_path)
    fake = _FakeFastEmbed(384)
    embedder._model = fake
    long_text = " ".join(["word"] * 150)
    [vector] = embedder.embed_documents([long_text])
    assert len(fake.seen) == 3
    assert math.isclose(math.sqrt(sum(v * v for v in vector)), 1.0, rel_tol=1e-9)
    assert embedder.model_id == "local:sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
    assert embedder.dims == 384


def test_local_embedder_rejects_wrong_dims(tmp_path: Any) -> None:
    embedder = LocalEmbedder("sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2", tmp_path)
    embedder._model = _FakeFastEmbed(10)
    with pytest.raises(EmbeddingError, match="dims"):
        embedder.embed_query("hello")


def test_registry_falls_back_to_hash_when_local_model_fails(monkeypatch: pytest.MonkeyPatch, tmp_path: Any) -> None:
    settings = make_settings(embeddings_provider="local", embeddings_model="", fastembed_cache_path=str(tmp_path))
    store = InMemorySettingsStore(settings)
    registry = EmbeddingsRegistry(settings, store, ProviderKeys(store, settings, secret_box_for(settings)))

    def boom(self: LocalEmbedder) -> None:
        raise EmbeddingError("no network to download the model")

    monkeypatch.setattr(LocalEmbedder, "load", boom)
    embedder = registry.active()
    assert isinstance(embedder, HashEmbedder)
    status = registry.status()
    assert status.degraded and "no network" in (status.reason or "")
    assert status.configured == "local:sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"


def test_registry_uses_settings_store_choice() -> None:
    settings = make_settings()
    store = InMemorySettingsStore(settings)
    registry = EmbeddingsRegistry(settings, store, ProviderKeys(store, settings, secret_box_for(settings)))
    store.update_ai_settings({"embeddings": {"provider": "hash", "model": "hash-256"}})
    assert registry.active().dims == 256
    assert not registry.status().degraded


def test_remote_embedder_batches_and_validates_dims() -> None:
    calls: list[dict[str, Any]] = []

    def handler(request: httpx.Request) -> httpx.Response:
        body = json.loads(request.content)
        calls.append(body)
        assert request.headers["authorization"] == "Bearer sk-test"
        data = [{"index": i, "embedding": [1.0, 0.0, 0.0]} for i, _ in enumerate(body["input"])]
        return httpx.Response(200, json={"data": list(reversed(data))})

    embedder = RemoteEmbedder(
        base_url="http://emb.test/v1",
        model="text-embedding-3-small",
        api_key="sk-test",
        dims=3,
        batch_size=2,
        http_client=httpx.Client(transport=httpx.MockTransport(handler)),
    )
    vectors = embedder.embed_documents(["a", "b", "c"])
    assert len(vectors) == 3 and [len(c["input"]) for c in calls] == [2, 1]
    assert embedder.model_id == "openai-compatible:text-embedding-3-small"

    wrong = RemoteEmbedder(
        base_url="http://emb.test/v1",
        model="m",
        api_key="sk-test",
        dims=5,
        http_client=httpx.Client(transport=httpx.MockTransport(handler)),
    )
    with pytest.raises(EmbeddingError, match="dims"):
        wrong.embed_query("x")


def test_registry_builds_a_model_once_under_concurrency(monkeypatch: pytest.MonkeyPatch, tmp_path: Any) -> None:
    import threading
    import time

    settings = make_settings(embeddings_provider="local", embeddings_model="", fastembed_cache_path=str(tmp_path))
    store = InMemorySettingsStore(settings)
    registry = EmbeddingsRegistry(settings, store, ProviderKeys(store, settings, secret_box_for(settings)))
    loads: list[int] = []
    release = threading.Event()

    def slow_load(self: LocalEmbedder) -> None:
        loads.append(1)
        release.wait(5)
        self._model = _FakeFastEmbed(384)

    monkeypatch.setattr(LocalEmbedder, "load", slow_load)
    results: list[Any] = []
    threads = [threading.Thread(target=lambda: results.append(registry.active())) for _ in range(4)]
    for thread in threads:
        thread.start()
    deadline = time.monotonic() + 5
    while not loads and time.monotonic() < deadline:
        time.sleep(0.01)
    assert registry.peek()["state"] == "loading"
    impatient = registry.active(wait=0.01)
    assert isinstance(impatient, HashEmbedder)
    release.set()
    for thread in threads:
        thread.join(5)
    assert len(loads) == 1 and len({id(r) for r in results}) == 1
    assert registry.peek() == {
        "configured": "local:sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2",
        "model": "local:sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2",
        "state": "ready",
    }

from __future__ import annotations

import threading
import time
from dataclasses import dataclass

from app.config import Settings
from app.embeddings.base import Embedder, EmbeddingError
from app.embeddings.hashing import HashEmbedder
from app.embeddings.local import LocalEmbedder
from app.embeddings.remote import RemoteEmbedder
from app.observability import get_logger
from app.providers.keys import ProviderKeys
from app.settings_store import EmbeddingsConfig, SettingsStore

log = get_logger(__name__)
LOCAL_RETRY_SECONDS = 600.0
ON_DEVICE_PROVIDERS = frozenset({"hash", "local"})


@dataclass(frozen=True, slots=True)
class EmbeddingsStatus:
    configured: str
    active: str
    dims: int
    degraded: bool
    reason: str | None


class EmbeddingsRegistry:
    def __init__(self, settings: Settings, store: SettingsStore, keys: ProviderKeys) -> None:
        self._settings = settings
        self._store = store
        self._keys = keys
        self._cache: dict[tuple[str, str], Embedder] = {}
        self._failures: dict[tuple[str, str], tuple[float, str]] = {}
        self._lock = threading.Lock()
        self._build_locks: dict[tuple[str, str], threading.Lock] = {}

    def config(self) -> EmbeddingsConfig:
        return self._store.ai_settings().embeddings

    def is_external(self) -> bool:
        return self.config().provider not in ON_DEVICE_PROVIDERS

    def active(self, wait: float | None = None, *, external_allowed: bool = True) -> Embedder:
        cfg = self.config()
        if not external_allowed and cfg.provider not in ON_DEVICE_PROVIDERS:
            return HashEmbedder()
        return self._resolve(cfg, wait)[0]

    def status(self) -> EmbeddingsStatus:
        cfg = self.config()
        embedder, reason = self._resolve(cfg)
        return EmbeddingsStatus(
            configured=f"{cfg.provider}:{cfg.model}",
            active=embedder.model_id,
            dims=embedder.dims,
            degraded=reason is not None,
            reason=reason,
        )

    def peek(self) -> dict[str, object]:
        cfg = self.config()
        key = (cfg.provider, cfg.model)
        with self._lock:
            cached = self._cache.get(key)
            failure = self._failures.get(key)
            building = self._build_locks.get(key)
        if building is not None and building.locked() and cached is None:
            return {"configured": f"{cfg.provider}:{cfg.model}", "model": None, "state": "loading"}
        if cached is not None:
            return {"configured": f"{cfg.provider}:{cfg.model}", "model": cached.model_id, "state": "ready"}
        if failure is not None:
            return {
                "configured": f"{cfg.provider}:{cfg.model}",
                "model": HashEmbedder().model_id,
                "state": "degraded",
                "reason": failure[1],
            }
        return {"configured": f"{cfg.provider}:{cfg.model}", "model": None, "state": "not_loaded"}

    def _cached(self, key: tuple[str, str]) -> tuple[Embedder, str | None] | None:
        with self._lock:
            cached = self._cache.get(key)
            if cached is not None:
                return cached, None
            failure = self._failures.get(key)
            if failure and time.monotonic() - failure[0] < LOCAL_RETRY_SECONDS:
                return HashEmbedder(), failure[1]
            return None

    def _resolve(self, cfg: EmbeddingsConfig, wait: float | None = None) -> tuple[Embedder, str | None]:
        key = (cfg.provider, cfg.model)
        hit = self._cached(key)
        if hit is not None:
            return hit
        with self._lock:
            build_lock = self._build_locks.setdefault(key, threading.Lock())
        if not build_lock.acquire(timeout=-1 if wait is None else max(0.0, wait)):
            log.warning("embeddings_loading_using_hash", configured=f"{cfg.provider}:{cfg.model}")
            return HashEmbedder(), "embedding model is still loading"
        try:
            hit = self._cached(key)
            if hit is not None:
                return hit
            return self._build_and_cache(cfg, key)
        finally:
            build_lock.release()

    def _build_and_cache(self, cfg: EmbeddingsConfig, key: tuple[str, str]) -> tuple[Embedder, str | None]:
        try:
            embedder = self._build(cfg)
        except EmbeddingError as exc:
            reason = str(exc)
            log.warning("embeddings_fallback_to_hash", configured=f"{cfg.provider}:{cfg.model}", reason=reason)
            with self._lock:
                self._failures[key] = (time.monotonic(), reason)
            return HashEmbedder(), reason
        with self._lock:
            self._cache[key] = embedder
            self._failures.pop(key, None)
        return embedder, None

    def _build(self, cfg: EmbeddingsConfig) -> Embedder:
        if cfg.provider == "hash":
            dims = 384
            if cfg.model.startswith("hash-") and cfg.model[5:].isdigit():
                dims = int(cfg.model[5:])
            return HashEmbedder(dims)
        if cfg.provider == "local":
            local = LocalEmbedder(cfg.model, self._settings.fastembed_cache_dir, self._settings.embeddings_batch_size)
            local.load()
            return local
        base_url = self._settings.embeddings_base_url or self._settings.openai_base_url
        api_key = self._settings.embeddings_api_key or self._keys.key("openai")
        return RemoteEmbedder(base_url=base_url, model=cfg.model, api_key=api_key, dims=self._settings.embeddings_dims)

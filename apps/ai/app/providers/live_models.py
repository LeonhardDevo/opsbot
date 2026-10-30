from __future__ import annotations

import re
import threading
import time
from collections.abc import Callable
from dataclasses import asdict, dataclass
from typing import Any

import httpx

from app.config import Settings
from app.providers.anthropic_provider import AnthropicProvider
from app.providers.catalog import MOCK_MODEL, get_entry
from app.providers.keys import ProviderKeys
from app.providers.types import ProviderError

CACHE_TTL_SECONDS = 600.0
NON_CHAT_MODEL = re.compile(
    r"embed|rerank|reward|safety|guard|parse|clip|retriever|whisper|tts|dall-e|moderation", re.I
)


@dataclass(frozen=True, slots=True)
class LiveModel:
    id: str
    free: bool
    context: int | None
    structured: bool

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


AnthropicLister = Callable[[str], list[str]]


def _default_anthropic_lister(api_key: str) -> list[str]:
    return AnthropicProvider(api_key).list_models()


class LiveModels:
    def __init__(
        self,
        settings: Settings,
        keys: ProviderKeys,
        *,
        http_client: httpx.Client | None = None,
        anthropic_lister: AnthropicLister = _default_anthropic_lister,
        ttl_seconds: float = CACHE_TTL_SECONDS,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        self._settings = settings
        self._keys = keys
        self._http = http_client or httpx.Client(timeout=15.0)
        self._anthropic_lister = anthropic_lister
        self._ttl = ttl_seconds
        self._clock = clock
        self._cache: dict[str, tuple[float, list[LiveModel]]] = {}
        self._lock = threading.Lock()

    def forget(self, provider_id: str) -> None:
        with self._lock:
            self._cache.pop(provider_id, None)

    def cached_at(self, provider_id: str) -> float | None:
        hit = self._cache.get(provider_id)
        return hit[0] if hit else None

    def models(self, provider_id: str) -> list[LiveModel]:
        now = self._clock()
        with self._lock:
            hit = self._cache.get(provider_id)
            if hit and now - hit[0] < self._ttl:
                return hit[1]
        models = self._fetch(provider_id)
        models.sort(key=lambda m: (not m.free, not m.structured, m.id))
        with self._lock:
            self._cache[provider_id] = (now, models)
        return models

    def _fetch(self, provider_id: str) -> list[LiveModel]:
        entry = get_entry(provider_id)
        if entry is None:
            raise ProviderError(f"unknown provider '{provider_id}'", status=404)
        if entry.kind == "mock":
            return [LiveModel(MOCK_MODEL, free=True, context=None, structured=True)]
        if entry.kind == "anthropic":
            key = self._keys.key(provider_id)
            if not key:
                return []
            return [
                LiveModel(model_id, free=False, context=None, structured=True)
                for model_id in self._anthropic_lister(key)
            ]
        if entry.base_url is None:
            return []
        key = None if not entry.needs_key else self._keys.key(provider_id)
        if entry.needs_key and not key and not entry.public_model_list:
            return []
        headers = {"authorization": f"Bearer {key}"} if key else {}
        try:
            response = self._http.get(f"{entry.base_url(self._settings).rstrip('/')}/models", headers=headers)
        except httpx.HTTPError as exc:
            raise ProviderError(f"{provider_id} models: {exc}", status=None) from exc
        if response.status_code >= 400:
            raise ProviderError(f"{provider_id} models HTTP {response.status_code}", status=response.status_code)
        data = response.json().get("data") or []
        models: list[LiveModel] = []
        for item in data:
            model_id = str(item.get("id", ""))
            if not model_id or NON_CHAT_MODEL.search(model_id):
                continue
            pricing = item.get("pricing") or {}
            params = item.get("supported_parameters")
            models.append(
                LiveModel(
                    id=model_id,
                    free=entry.free_trial
                    or model_id.endswith(":free")
                    or (str(pricing.get("prompt")) == "0" and str(pricing.get("completion")) == "0"),
                    context=item.get("context_length"),
                    structured=params is None or "structured_outputs" in params,
                )
            )
        return models

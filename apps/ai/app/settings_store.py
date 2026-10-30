from __future__ import annotations

import threading
import time
from dataclasses import asdict, dataclass
from typing import Any

from sqlalchemy.dialects.postgresql import insert
from sqlalchemy.orm import Session, sessionmaker

from app.config import EmbeddingsProviderName, Settings
from app.models import Setting
from app.providers.catalog import get_entry

AI_SETTINGS_KEY = "ai"
PROVIDER_KEYS_SETTING = "provider_keys"

EMBEDDING_PROVIDERS: tuple[EmbeddingsProviderName, ...] = ("local", "openai-compatible", "hash")
DEFAULT_EMBEDDING_MODELS: dict[str, str] = {
    "local": "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2",
    "openai-compatible": "text-embedding-3-small",
    "hash": "hash-384",
}


@dataclass(frozen=True, slots=True)
class EmbeddingsConfig:
    provider: EmbeddingsProviderName
    model: str


@dataclass(frozen=True, slots=True)
class AiSettings:
    provider: str
    model: str
    fallback_model: str | None
    temperature: float
    embeddings: EmbeddingsConfig

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


class SettingsValidationError(ValueError):
    def __init__(self, field: str, message: str) -> None:
        super().__init__(message)
        self.field = field


class SettingsStore:
    def __init__(self, session_factory: sessionmaker[Session], settings: Settings, ttl_seconds: float = 5.0) -> None:
        self._session_factory = session_factory
        self._settings = settings
        self._ttl = ttl_seconds
        self._cache: dict[str, tuple[float, Any]] = {}
        self._lock = threading.Lock()

    def get_raw(self, key: str, default: Any = None) -> Any:
        now = time.monotonic()
        with self._lock:
            hit = self._cache.get(key)
            if hit and now - hit[0] < self._ttl:
                return hit[1]
        with self._session_factory() as session:
            row = session.get(Setting, key)
            value = row.value if row is not None else None
        with self._lock:
            self._cache[key] = (now, value)
        return default if value is None else value

    def set_raw(self, key: str, value: Any) -> None:
        with self._session_factory() as session:
            stmt = insert(Setting).values(key=key, value=value)
            stmt = stmt.on_conflict_do_update(
                index_elements=[Setting.key],
                set_={"value": stmt.excluded.value, "updated_at": stmt.excluded.updated_at},
            )
            session.execute(stmt)
            session.commit()
        with self._lock:
            self._cache.pop(key, None)

    def invalidate(self) -> None:
        with self._lock:
            self._cache.clear()

    def ai_settings(self) -> AiSettings:
        stored: dict[str, Any] = self.get_raw(AI_SETTINGS_KEY, {}) or {}
        env = self._settings
        provider = str(stored.get("provider") or env.ai_provider or "mock")
        entry = get_entry(provider) or get_entry("mock")
        assert entry is not None
        provider = entry.id
        model = str(stored.get("model") or (env.ai_model if provider == env.ai_provider else "") or entry.default_model)
        fallback_raw = stored.get("fallback_model", env.ai_fallback_model if provider == env.ai_provider else None)
        fallback = str(fallback_raw).strip() or None if fallback_raw else None
        temperature = float(stored.get("temperature", env.ai_temperature))
        emb: dict[str, Any] = stored.get("embeddings") or {}
        emb_provider = emb.get("provider") or env.embeddings_provider
        if emb_provider not in EMBEDDING_PROVIDERS:
            emb_provider = "hash"
        emb_model = str(
            emb.get("model")
            or (env.embeddings_model if emb_provider == env.embeddings_provider else "")
            or DEFAULT_EMBEDDING_MODELS[emb_provider]
        )
        return AiSettings(
            provider=provider,
            model=model,
            fallback_model=fallback,
            temperature=temperature,
            embeddings=EmbeddingsConfig(provider=emb_provider, model=emb_model),
        )

    def update_ai_settings(self, patch: dict[str, Any]) -> AiSettings:
        current = self.ai_settings()
        merged: dict[str, Any] = current.to_dict()
        provider_changed = "provider" in patch and patch["provider"] != current.provider
        for key in ("provider", "model", "fallback_model", "temperature"):
            if key in patch:
                merged[key] = patch[key]
        if provider_changed and "model" not in patch:
            entry = get_entry(str(merged["provider"]))
            merged["model"] = entry.default_model if entry else ""
        if "embeddings" in patch and patch["embeddings"] is not None:
            emb = dict(merged["embeddings"])
            new_emb = patch["embeddings"]
            if new_emb.get("provider") and new_emb.get("provider") != emb["provider"] and not new_emb.get("model"):
                emb["model"] = DEFAULT_EMBEDDING_MODELS.get(str(new_emb["provider"]), "")
            emb.update({k: v for k, v in new_emb.items() if v is not None})
            merged["embeddings"] = emb
        validated = self._validate(merged)
        self.set_raw(AI_SETTINGS_KEY, validated.to_dict())
        return validated

    @staticmethod
    def _validate(data: dict[str, Any]) -> AiSettings:
        provider = str(data.get("provider") or "")
        if get_entry(provider) is None:
            raise SettingsValidationError("provider", f"unknown provider '{provider}'")
        model = str(data.get("model") or "").strip()
        if not model or len(model) > 200:
            raise SettingsValidationError("model", "model is required (max 200 characters)")
        fallback = data.get("fallback_model")
        fallback = str(fallback).strip() if fallback else None
        if fallback and len(fallback) > 200:
            raise SettingsValidationError("fallback_model", "fallback model is too long")
        try:
            temperature = float(data.get("temperature", 0.2))
        except (TypeError, ValueError) as exc:
            raise SettingsValidationError("temperature", "temperature must be a number") from exc
        if not 0.0 <= temperature <= 2.0:
            raise SettingsValidationError("temperature", "temperature must be between 0 and 2")
        emb = data.get("embeddings") or {}
        emb_provider = emb.get("provider")
        if emb_provider not in EMBEDDING_PROVIDERS:
            raise SettingsValidationError("embeddings.provider", f"unknown embeddings provider '{emb_provider}'")
        emb_model = str(emb.get("model") or "").strip() or DEFAULT_EMBEDDING_MODELS[emb_provider]
        return AiSettings(
            provider=provider,
            model=model,
            fallback_model=fallback or None,
            temperature=round(temperature, 3),
            embeddings=EmbeddingsConfig(provider=emb_provider, model=emb_model),
        )

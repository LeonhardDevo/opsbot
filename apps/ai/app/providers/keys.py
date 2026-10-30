from __future__ import annotations

from dataclasses import dataclass
from typing import Literal

from app.config import Settings
from app.crypto import SecretBox, SecretBoxError
from app.observability import get_logger
from app.providers.catalog import KEYLESS_PROVIDERS
from app.settings_store import PROVIDER_KEYS_SETTING, SettingsStore

KeySource = Literal["settings", "env"]

SHARED_KEY_PROVIDERS: dict[str, str] = {
    "opencode-zen": "opencode-go",
    "opencode-go": "opencode-zen",
}

log = get_logger(__name__)


@dataclass(frozen=True, slots=True)
class KeyInfo:
    source: KeySource | None
    hint: str | None


def key_hint(key: str) -> str:
    return f"…{key[-4:]}"


class ProviderKeys:
    def __init__(self, store: SettingsStore, settings: Settings, box: SecretBox) -> None:
        self._store = store
        self._settings = settings
        self._box = box

    def _env_key(self, provider_id: str) -> str | None:
        s = self._settings
        candidates: dict[str, tuple[str | None, ...]] = {
            "anthropic": (s.anthropic_api_key,),
            "openai": (s.openai_api_key,),
            "deepseek": (s.deepseek_api_key,),
            "openrouter": (s.openrouter_api_key,),
            "nvidia": (s.nvidia_api_key,),
            "opencode-zen": (s.opencode_zen_api_key, s.opencode_go_api_key),
            "opencode-go": (s.opencode_go_api_key, s.opencode_zen_api_key),
        }
        for value in candidates.get(provider_id, ()):
            if value and value.strip():
                return value.strip()
        return None

    def _stored_tokens(self) -> dict[str, str]:
        raw = self._store.get_raw(PROVIDER_KEYS_SETTING, {}) or {}
        return {str(k): str(v) for k, v in raw.items() if v}

    def _decrypt(self, provider_id: str, token: str | None) -> str | None:
        if not token:
            return None
        try:
            value = self._box.decrypt(token).strip()
        except SecretBoxError:
            log.warning("provider_key_undecryptable", provider=provider_id)
            return None
        return value or None

    def _stored_key(self, provider_id: str) -> str | None:
        tokens = self._stored_tokens()
        own = self._decrypt(provider_id, tokens.get(provider_id))
        if own:
            return own
        shared_with = SHARED_KEY_PROVIDERS.get(provider_id)
        return self._decrypt(shared_with, tokens.get(shared_with)) if shared_with else None

    def key(self, provider_id: str) -> str | None:
        return self._stored_key(provider_id) or self._env_key(provider_id)

    def info(self, provider_id: str) -> KeyInfo:
        stored = self._stored_key(provider_id)
        if stored:
            return KeyInfo("settings", key_hint(stored))
        env = self._env_key(provider_id)
        if env:
            return KeyInfo("env", key_hint(env))
        return KeyInfo(None, None)

    def is_configured(self, provider_id: str) -> bool:
        return provider_id in KEYLESS_PROVIDERS or self.key(provider_id) is not None

    def set_key(self, provider_id: str, api_key: str | None) -> KeyInfo:
        tokens = self._stored_tokens()
        trimmed = (api_key or "").strip()
        if trimmed:
            tokens[provider_id] = self._box.encrypt(trimmed)
        else:
            tokens.pop(provider_id, None)
        self._store.set_raw(PROVIDER_KEYS_SETTING, tokens)
        return self.info(provider_id)

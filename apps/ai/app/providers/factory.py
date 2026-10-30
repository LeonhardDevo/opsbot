from __future__ import annotations

from collections.abc import Sequence

from app.config import Settings
from app.providers.anthropic_provider import AnthropicProvider
from app.providers.catalog import get_entry
from app.providers.keys import ProviderKeys
from app.providers.mock import IntentRule, MockProvider
from app.providers.openai_compat import OpenAiCompatibleProvider, openai_supports_temperature
from app.providers.types import AiProvider


class ProviderFactory:
    def __init__(self, settings: Settings, keys: ProviderKeys, intent_rules: Sequence[IntentRule] = ()) -> None:
        self._settings = settings
        self._keys = keys
        self._mock = MockProvider(intent_rules)

    @property
    def mock(self) -> MockProvider:
        return self._mock

    def is_configured(self, provider_id: str) -> bool:
        return self._keys.is_configured(provider_id)

    def build(self, provider_id: str) -> AiProvider | None:
        entry = get_entry(provider_id)
        if entry is None:
            return None
        if entry.kind == "mock":
            return self._mock
        timeout = self._settings.ai_request_timeout_seconds
        if entry.kind == "anthropic":
            key = self._keys.key(provider_id)
            if not key:
                return None
            return AnthropicProvider(key, timeout=timeout, server_fallbacks=self._settings.anthropic_server_fallbacks)
        if entry.base_url is None:
            return None
        key = "ollama" if not entry.needs_key else self._keys.key(provider_id)
        if not key:
            return None
        return OpenAiCompatibleProvider(
            provider_id,
            base_url=entry.base_url(self._settings),
            api_key=key,
            identify_client=entry.session_routed,
            send_session_header=entry.session_routed,
            max_tokens_param=entry.max_tokens_param,
            supports_temperature=openai_supports_temperature if provider_id == "openai" else (lambda _m: True),
            timeout=timeout,
        )

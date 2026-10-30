from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass, field
from typing import Literal

from app.config import Settings

ProviderKind = Literal["mock", "anthropic", "openai-compatible"]


@dataclass(frozen=True, slots=True)
class CatalogEntry:
    id: str
    label: str
    kind: ProviderKind
    default_model: str
    models: tuple[str, ...]
    needs_key: bool = True
    note: dict[str, str] = field(default_factory=dict)
    base_url: Callable[[Settings], str] | None = None
    public_model_list: bool = False
    free_trial: bool = False
    session_routed: bool = False
    max_tokens_param: Literal["max_tokens", "max_completion_tokens"] = "max_tokens"

    def localized_note(self, locale: str) -> str | None:
        return self.note.get(locale) or self.note.get("en")


MOCK_MODEL = "mock-extractive-1"

CATALOG: tuple[CatalogEntry, ...] = (
    CatalogEntry(
        id="mock",
        label="Mock (offline, deterministic)",
        kind="mock",
        default_model=MOCK_MODEL,
        models=(MOCK_MODEL,),
        needs_key=False,
        note={
            "en": "Built-in deterministic provider: extractive answers from retrieved documents and "
            "rule-based tool routing. No key and no network required.",
            "ru": "Встроенный детерминированный провайдер: ответы цитатами из найденных документов и "
            "маршрутизация инструментов по правилам. Ключ и сеть не нужны.",
        },
    ),
    CatalogEntry(
        id="anthropic",
        label="Anthropic (Claude)",
        kind="anthropic",
        default_model="claude-opus-5-5",
        models=("claude-opus-5-5", "claude-sonnet-5-5", "claude-haiku-5-5", "claude-fable-5-1"),
    ),
    CatalogEntry(
        id="openai",
        label="OpenAI",
        kind="openai-compatible",
        default_model="gpt-5-mini",
        models=("gpt-5", "gpt-5-mini", "gpt-5-nano"),
        base_url=lambda s: s.openai_base_url,
        max_tokens_param="max_completion_tokens",
    ),
    CatalogEntry(
        id="deepseek",
        label="DeepSeek (official)",
        kind="openai-compatible",
        default_model="deepseek-chat",
        models=("deepseek-chat", "deepseek-reasoner"),
        base_url=lambda s: s.deepseek_base_url,
    ),
    CatalogEntry(
        id="openrouter",
        label="OpenRouter",
        kind="openai-compatible",
        default_model="z-ai/glm-5.2:free",
        models=(
            "z-ai/glm-5.2:free",
            "minimax/minimax-m3:free",
            "deepseek/deepseek-v4-flash",
            "anthropic/claude-sonnet-5",
        ),
        base_url=lambda s: s.openrouter_base_url,
        public_model_list=True,
    ),
    CatalogEntry(
        id="nvidia",
        label="NVIDIA (build.nvidia.com)",
        kind="openai-compatible",
        default_model="nvidia/nemotron-3-ultra-550b-a55b",
        models=(
            "nvidia/nemotron-3-ultra-550b-a55b",
            "nvidia/nemotron-3.5-lightning-30b-a3b",
            "deepseek-ai/deepseek-v4.1-flash",
        ),
        base_url=lambda s: s.nvidia_base_url,
        public_model_list=True,
        free_trial=True,
        note={
            "en": "NVIDIA free trial: about 40 requests per minute, meant for prototypes and tests. "
            "Get a key (nvapi-…) at build.nvidia.com → Get API Key.",
            "ru": "Бесплатный пробный доступ NVIDIA: около 40 запросов в минуту, для прототипов и тестов. "
            "Ключ (nvapi-…) — на build.nvidia.com → Get API Key.",
        },
    ),
    CatalogEntry(
        id="opencode-zen",
        label="OpenCode Zen",
        kind="openai-compatible",
        default_model="deepseek/deepseek-v4-flash-free",
        models=("deepseek/deepseek-v4-flash-free",),
        base_url=lambda s: s.opencode_zen_base_url,
        session_routed=True,
        note={
            "en": "The free OpenCode tier works only inside their app; only paid Zen models are reachable from here.",
            "ru": "Бесплатный тариф OpenCode работает только внутри их приложения — "
            "отсюда доступны только платные модели Zen.",
        },
    ),
    CatalogEntry(
        id="opencode-go",
        label="OpenCode Go (subscription)",
        kind="openai-compatible",
        default_model="deepseek-v4-flash",
        models=("deepseek-v4-flash", "qwen3.8-max", "glm-5.3-flash", "grok-4.6"),
        base_url=lambda s: s.opencode_go_base_url,
        session_routed=True,
    ),
    CatalogEntry(
        id="ollama",
        label="Ollama (local)",
        kind="openai-compatible",
        default_model="qwen3",
        models=(),
        needs_key=False,
        base_url=lambda s: s.ollama_base_url,
    ),
)

_BY_ID = {entry.id: entry for entry in CATALOG}
KEYLESS_PROVIDERS = frozenset(entry.id for entry in CATALOG if not entry.needs_key)


def get_entry(provider_id: str) -> CatalogEntry | None:
    return _BY_ID.get(provider_id)


def provider_ids() -> list[str]:
    return [entry.id for entry in CATALOG]

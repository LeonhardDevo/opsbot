from __future__ import annotations

import json
from decimal import Decimal
from typing import Any

import httpx
import httpx2
import pytest

from app.agent.sources import NOT_FOUND_MARKER, Source, render_documents
from app.crypto import secret_box_for
from app.providers.anthropic_provider import SERVER_FALLBACK_BETA, AnthropicProvider, to_anthropic_messages
from app.providers.factory import ProviderFactory
from app.providers.keys import ProviderKeys
from app.providers.live_models import LiveModels
from app.providers.manager import AiManager, UsageRecord
from app.providers.mock import MockProvider
from app.providers.openai_compat import OpenAiCompatibleProvider
from app.providers.pricing import estimate_cost_usd, price_for
from app.providers.types import (
    ChatMessage,
    ChatRequest,
    ChatUsage,
    JsonSchemaSpec,
    ProviderError,
    ProviderNotConfiguredError,
    ToolCall,
    ToolDefinition,
)
from app.tools.intents import INTENT_RULES
from tests.helpers import InMemorySettingsStore, ScriptedProvider, make_settings, rate_limited, text_result


def test_pricing_uses_longest_matching_key() -> None:
    assert price_for("claude-opus-5-5") == (4.0, 20.0, 0.2)
    assert price_for("claude-opus-5") == (5.0, 25.0, 0.5)
    assert price_for("anthropic/claude-sonnet-5-5") == (2.0, 10.0, 0.2)
    assert price_for("z-ai/glm-5.2:free") is None
    assert price_for("unknown-model") is None
    usage = ChatUsage(prompt_tokens=1_000_000, completion_tokens=100_000, cached_tokens=500_000)
    assert estimate_cost_usd("claude-opus-5-5", usage) == Decimal("4.100000")
    assert estimate_cost_usd("mock-extractive-1", usage) == Decimal(0)


def _keys(**settings_overrides: Any) -> tuple[ProviderKeys, InMemorySettingsStore]:
    settings = make_settings(**settings_overrides)
    store = InMemorySettingsStore(settings)
    return ProviderKeys(store, settings, secret_box_for(settings)), store


def test_stored_key_overrides_env_and_is_encrypted() -> None:
    keys, store = _keys(deepseek_api_key="sk-env-1111")
    assert keys.info("deepseek").source == "env" and keys.info("deepseek").hint == "…1111"
    info = keys.set_key("deepseek", "  sk-stored-2222  ")
    assert (info.source, info.hint) == ("settings", "…2222")
    assert keys.key("deepseek") == "sk-stored-2222"
    assert "sk-stored-2222" not in json.dumps(store.raw)
    keys.set_key("deepseek", None)
    assert keys.info("deepseek").source == "env"


def test_shared_opencode_keys_and_keyless_providers() -> None:
    keys, _ = _keys()
    assert not keys.is_configured("opencode-go")
    keys.set_key("opencode-zen", "zen-key-9999")
    assert keys.key("opencode-go") == "zen-key-9999"
    assert keys.is_configured("mock") and keys.is_configured("ollama")
    assert keys.info("anthropic").source is None


def test_undecryptable_key_is_ignored() -> None:
    keys, store = _keys()
    store.raw["provider_keys"] = {"anthropic": "not-a-fernet-token"}
    assert keys.key("anthropic") is None


def _sources_message(question: str) -> ChatMessage:
    sources = [
        Source(
            ref=1,
            document_id=10,
            title="Leave policy",
            content="Employees get 28 vacation days per year. Requests go to the line manager.",
        ),
        Source(ref=2, document_id=11, title="Kitchen rules", content="The kitchen is cleaned on Fridays."),
    ]
    return ChatMessage(role="user", content=f"{render_documents(sources)}\n\nQuestion: {question}")


def test_mock_answers_extractively_with_citations() -> None:
    question = "How many vacation days do employees get?"
    result = MockProvider().chat(
        ChatRequest(model="", messages=[_sources_message(question)], metadata={"question": question, "locale": "en"})
    )
    assert result.content is not None
    assert result.content.startswith("According to the approved documents:")
    assert "Employees get 28 vacation days per year. [1]" in result.content
    assert "kitchen" not in result.content.lower()
    assert result.usage.prompt_tokens > 0


def test_mock_localizes_intro_and_reports_not_found() -> None:
    question = "Какой лимит на такси?"
    mock = MockProvider()
    result = mock.chat(
        ChatRequest(model="", messages=[_sources_message(question)], metadata={"question": question, "locale": "ru"})
    )
    assert result.content == NOT_FOUND_MARKER
    ru = mock.chat(
        ChatRequest(
            model="",
            messages=[
                ChatMessage(
                    role="user",
                    content=render_documents(
                        [Source(ref=1, document_id=1, title="Такси", content="Лимит на такси составляет 2000 рублей.")]
                    ),
                )
            ],
            metadata={"question": question, "locale": "ru"},
        )
    )
    assert ru.content == "Согласно утверждённым документам:\n\nЛимит на такси составляет 2000 рублей. [1]"


def test_mock_routes_intents_to_offered_tools_once() -> None:
    mock = MockProvider(INTENT_RULES)
    tool = ToolDefinition(name="search_documents", description="", parameters={})
    question = "Найди регламент командировок"
    first = mock.chat(
        ChatRequest(
            model="",
            messages=[ChatMessage(role="user", content=question)],
            tools=[tool],
            metadata={"question": question},
        )
    )
    assert [c.name for c in first.tool_calls] == ["search_documents"]
    assert json.loads(first.tool_calls[0].arguments) == {"query": "регламент командировок"}
    assert not mock.chat(
        ChatRequest(model="", messages=[ChatMessage(role="user", content=question)], metadata={"question": question})
    ).tool_calls
    tool_result = render_documents(
        [Source(ref=1, document_id=5, title="Командировки", content="Регламент командировок утверждён в 2025 году.")]
    )
    second = mock.chat(
        ChatRequest(
            model="",
            messages=[
                ChatMessage(role="user", content=question),
                ChatMessage(role="assistant", content="", tool_calls=first.tool_calls),
                ChatMessage(role="tool", content=tool_result, tool_call_id=first.tool_calls[0].id),
            ],
            tools=[tool],
            metadata={"question": question, "locale": "ru"},
        )
    )
    assert not second.tool_calls and "[1]" in (second.content or "")


def test_mock_uses_data_tool_summaries() -> None:
    result = MockProvider().chat(
        ChatRequest(
            model="",
            messages=[
                ChatMessage(role="user", content="Incident INC-7 status?"),
                ChatMessage(
                    role="tool", content=json.dumps({"summary": "INC-7 is resolved (2026-10-01)."}), tool_call_id="t"
                ),
            ],
            metadata={"question": "Incident INC-7 status?", "locale": "en"},
        )
    )
    assert result.content == "Data from connected systems:\n\n- INC-7 is resolved (2026-10-01)."


class _Recorder:
    def __init__(self) -> None:
        self.records: list[UsageRecord] = []

    def record(self, record: UsageRecord) -> None:
        self.records.append(record)


class _Factory(ProviderFactory):
    def __init__(self, provider: Any) -> None:
        settings = make_settings()
        store = InMemorySettingsStore(settings)
        super().__init__(settings, ProviderKeys(store, settings, secret_box_for(settings)))
        self.provider = provider

    def build(self, provider_id: str) -> Any:
        return self.provider


def _manager(provider: Any, *, fallback: str | None = "fallback-model") -> tuple[AiManager, _Recorder, list[float]]:
    settings = make_settings()
    store = InMemorySettingsStore(settings)
    store.update_ai_settings({"provider": "deepseek", "model": "primary-model", "fallback_model": fallback})
    recorder = _Recorder()
    sleeps: list[float] = []
    manager = AiManager(
        store, _Factory(provider), recorder, retry_base_seconds=1.0, retry_max_seconds=8.0, sleep=sleeps.append
    )
    return manager, recorder, sleeps


def _req() -> ChatRequest:
    return ChatRequest(model="", messages=[ChatMessage(role="user", content="hi")])


def test_manager_success_logs_usage_and_cost() -> None:
    provider = ScriptedProvider([text_result("ok", prompt=1000, completion=100)], provider_id="deepseek")
    manager, recorder, _ = _manager(provider)
    managed = manager.chat(_req(), purpose="chat", workspace_id=7)
    assert (managed.provider, managed.model, managed.attempts) == ("deepseek", "primary-model", 1)
    assert provider.calls[0].model == "primary-model" and provider.calls[0].temperature == 0.2
    assert provider.calls[0].session_id == "ws-7-chat"
    [record] = recorder.records
    assert (record.workspace_id, record.purpose, record.ok, record.usage.prompt_tokens) == (7, "chat", True, 1000)


@pytest.mark.parametrize("status", [403, 404])
def test_manager_switches_to_fallback_on_missing_model(status: int) -> None:
    provider = ScriptedProvider([ProviderError("gone", status=status), text_result("ok")])
    manager, _, sleeps = _manager(provider)
    managed = manager.chat(_req(), purpose="chat")
    assert [c.model for c in provider.calls] == ["primary-model", "fallback-model"]
    assert managed.model == "fallback-model" and sleeps == []


def test_manager_retries_429_with_backoff_then_falls_back_after_three() -> None:
    provider = ScriptedProvider([rate_limited(), rate_limited(retry_after=3.0), rate_limited(), text_result("ok")])
    manager, _, sleeps = _manager(provider)
    managed = manager.chat(_req(), purpose="chat")
    assert [c.model for c in provider.calls] == ["primary-model"] * 3 + ["fallback-model"]
    assert sleeps == [1.0, 3.0]
    assert managed.model == "fallback-model" and managed.attempts == 4


def test_manager_gives_up_after_six_attempts_and_records_failure() -> None:
    provider = ScriptedProvider([rate_limited()])
    manager, recorder, sleeps = _manager(provider)
    with pytest.raises(ProviderError):
        manager.chat(_req(), purpose="chat")
    assert len(provider.calls) == 6
    assert len(sleeps) == 4
    assert recorder.records[-1].ok is False


def test_manager_without_fallback_retries_429_up_to_max() -> None:
    provider = ScriptedProvider([rate_limited()])
    manager, _, sleeps = _manager(provider, fallback=None)
    with pytest.raises(ProviderError):
        manager.chat(_req(), purpose="chat")
    assert len(provider.calls) == 6 and sleeps == [1.0, 2.0, 4.0, 8.0, 8.0]


def test_manager_does_not_retry_client_errors_and_limits_transient_retries() -> None:
    bad_request = ScriptedProvider([ProviderError("bad", status=400)])
    manager, _, _ = _manager(bad_request)
    with pytest.raises(ProviderError):
        manager.chat(_req(), purpose="chat")
    assert len(bad_request.calls) == 1

    flaky = ScriptedProvider([ProviderError("down", status=503)])
    manager, _, _ = _manager(flaky, fallback=None)
    with pytest.raises(ProviderError):
        manager.chat(_req(), purpose="chat")
    assert len(flaky.calls) == 3


def test_manager_reports_unconfigured_provider() -> None:
    settings = make_settings()
    store = InMemorySettingsStore(settings)
    store.update_ai_settings({"provider": "anthropic"})
    keys = ProviderKeys(store, settings, secret_box_for(settings))
    manager = AiManager(store, ProviderFactory(settings, keys), _Recorder())
    with pytest.raises(ProviderNotConfiguredError):
        manager.chat(_req(), purpose="chat")


def test_default_settings_use_mock_and_ping_works() -> None:
    settings = make_settings()
    store = InMemorySettingsStore(settings)
    keys = ProviderKeys(store, settings, secret_box_for(settings))
    manager = AiManager(store, ProviderFactory(settings, keys), _Recorder())
    managed = manager.chat(ChatRequest(model="", messages=[], metadata={"ping": True}), purpose="test")
    assert (managed.provider, managed.model, managed.content) == ("mock", "mock-extractive-1", "OK")


def _openai(handler: Any, **kwargs: Any) -> OpenAiCompatibleProvider:
    return OpenAiCompatibleProvider(
        "deepseek",
        base_url="http://llm.test/v1",
        api_key="sk-test",
        http_client=httpx2.Client(transport=httpx2.MockTransport(handler)),
        **kwargs,
    )


def _completion(message: dict[str, Any], finish: str = "stop") -> dict[str, Any]:
    return {
        "id": "c1",
        "object": "chat.completion",
        "created": 1,
        "model": "deepseek-chat",
        "choices": [{"index": 0, "message": {"role": "assistant", **message}, "finish_reason": finish}],
        "usage": {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15, "prompt_cache_hit_tokens": 4},
    }


def test_openai_compatible_tool_calls_and_usage() -> None:
    bodies: list[dict[str, Any]] = []

    def handler(request: httpx2.Request) -> httpx2.Response:
        bodies.append(json.loads(request.content))
        return httpx2.Response(
            200,
            json=_completion(
                {
                    "content": None,
                    "tool_calls": [
                        {
                            "id": "call_1",
                            "type": "function",
                            "function": {"name": "search_documents", "arguments": '{"query":"x"}'},
                        }
                    ],
                },
                "tool_calls",
            ),
        )

    provider = _openai(handler)
    result = provider.chat(
        ChatRequest(
            model="deepseek-chat",
            messages=[
                ChatMessage(role="system", content="sys"),
                ChatMessage(role="assistant", content="", tool_calls=[ToolCall("t0", "search_documents", "{}")]),
                ChatMessage(role="tool", content="result", tool_call_id="t0"),
            ],
            tools=[ToolDefinition("search_documents", "d", {"type": "object"})],
            temperature=0.3,
            max_tokens=100,
        )
    )
    assert result.tool_calls == [ToolCall("call_1", "search_documents", '{"query":"x"}')]
    assert (result.usage.prompt_tokens, result.usage.cached_tokens) == (10, 4)
    body = bodies[0]
    assert body["temperature"] == 0.3 and body["max_tokens"] == 100
    assert body["messages"][1]["tool_calls"][0]["function"]["name"] == "search_documents"
    assert body["messages"][2] == {"role": "tool", "content": "result", "tool_call_id": "t0"}


def test_openai_compatible_falls_back_to_json_object() -> None:
    formats: list[str] = []

    def handler(request: httpx2.Request) -> httpx2.Response:
        body = json.loads(request.content)
        formats.append(body["response_format"]["type"])
        if body["response_format"]["type"] == "json_schema":
            return httpx2.Response(400, json={"error": {"message": "response_format json_schema is not supported"}})
        assert "JSON Schema" in body["messages"][0]["content"]
        return httpx2.Response(200, json=_completion({"content": '{"ok": true}'}))

    result = _openai(handler).chat(
        ChatRequest(
            model="m",
            messages=[ChatMessage(role="system", content="sys"), ChatMessage(role="user", content="u")],
            json_schema=JsonSchemaSpec("answer", {"type": "object"}),
        )
    )
    assert formats == ["json_schema", "json_object"] and result.content == '{"ok": true}'


def test_openai_compatible_maps_errors() -> None:
    def limited(_: httpx2.Request) -> httpx2.Response:
        return httpx2.Response(429, headers={"retry-after": "7"}, json={"error": {"message": "slow down"}})

    with pytest.raises(ProviderError) as caught:
        _openai(limited).chat(ChatRequest(model="m", messages=[ChatMessage(role="user", content="u")]))
    assert (caught.value.status, caught.value.retry_after) == (429, 7.0)

    def empty(_: httpx2.Request) -> httpx2.Response:
        return httpx2.Response(
            200,
            json={
                "id": "x",
                "object": "chat.completion",
                "created": 1,
                "model": "m",
                "choices": [],
                "error": {"message": "upstream overloaded", "code": 502},
            },
        )

    with pytest.raises(ProviderError) as caught:
        _openai(empty).chat(ChatRequest(model="m", messages=[ChatMessage(role="user", content="u")]))
    assert caught.value.status == 502


def test_openai_reasoning_models_skip_temperature() -> None:
    from app.providers.openai_compat import openai_supports_temperature

    bodies: list[dict[str, Any]] = []

    def handler(request: httpx2.Request) -> httpx2.Response:
        bodies.append(json.loads(request.content))
        return httpx2.Response(200, json=_completion({"content": "hi"}))

    provider = _openai(
        handler, supports_temperature=openai_supports_temperature, max_tokens_param="max_completion_tokens"
    )
    provider.chat(
        ChatRequest(
            model="gpt-5-mini", messages=[ChatMessage(role="user", content="u")], temperature=0.2, max_tokens=50
        )
    )
    assert "temperature" not in bodies[0] and bodies[0]["max_completion_tokens"] == 50


def _anthropic_message(content: list[dict[str, Any]], stop: str = "end_turn") -> dict[str, Any]:
    return {
        "id": "msg_1",
        "type": "message",
        "role": "assistant",
        "model": "claude-opus-5-5",
        "content": content,
        "stop_reason": stop,
        "stop_sequence": None,
        "usage": {
            "input_tokens": 100,
            "output_tokens": 20,
            "cache_read_input_tokens": 50,
            "cache_creation_input_tokens": 10,
        },
    }


def _anthropic(handler: Any, **kwargs: Any) -> AnthropicProvider:
    return AnthropicProvider(
        "sk-ant-test", http_client=httpx2.Client(transport=httpx2.MockTransport(handler)), **kwargs
    )


def test_anthropic_tool_use_blocks_usage_and_server_fallbacks() -> None:
    seen: list[httpx2.Request] = []

    def handler(request: httpx2.Request) -> httpx2.Response:
        seen.append(request)
        return httpx2.Response(
            200,
            json=_anthropic_message(
                [
                    {"type": "thinking", "thinking": "", "signature": "sig-1"},
                    {"type": "text", "text": "Let me search."},
                    {"type": "tool_use", "id": "toolu_1", "name": "search_documents", "input": {"query": "leave"}},
                ],
                "tool_use",
            ),
        )

    result = _anthropic(handler).chat(
        ChatRequest(
            model="claude-opus-5-5",
            messages=[ChatMessage(role="system", content="rules"), ChatMessage(role="user", content="q")],
            tools=[ToolDefinition("search_documents", "d", {"type": "object"})],
            temperature=0.2,
        )
    )
    body = json.loads(seen[0].content)
    assert "temperature" not in body
    assert body["system"] == "rules" and body["fallbacks"] == "default"
    assert SERVER_FALLBACK_BETA in seen[0].headers.get("anthropic-beta", "")
    assert body["tools"][0]["input_schema"] == {"type": "object"}
    assert result.tool_calls == [ToolCall("toolu_1", "search_documents", '{"query": "leave"}')]
    assert result.content == "Let me search."
    assert (result.usage.prompt_tokens, result.usage.cached_tokens, result.usage.completion_tokens) == (160, 50, 20)
    assert [b["type"] for b in result.provider_blocks or []] == ["thinking", "text", "tool_use"]


def test_anthropic_replays_native_blocks_and_groups_tool_results() -> None:
    blocks: list[dict[str, Any]] = [
        {"type": "thinking", "thinking": "", "signature": "s"},
        {"type": "tool_use", "id": "t1", "name": "a", "input": {}},
    ]
    messages = to_anthropic_messages(
        [
            ChatMessage(role="user", content="q"),
            ChatMessage(
                role="assistant",
                content="",
                tool_calls=[ToolCall("t1", "a", "{}"), ToolCall("t2", "b", "{}")],
                provider_blocks=blocks,
                provider_id="anthropic",
            ),
            ChatMessage(role="tool", content="r1", tool_call_id="t1"),
            ChatMessage(role="tool", content="r2", tool_call_id="t2"),
        ]
    )
    assert messages[1] == {"role": "assistant", "content": blocks}
    assert [b["tool_use_id"] for b in messages[2]["content"]] == ["t1", "t2"]
    rebuilt = to_anthropic_messages(
        [ChatMessage(role="assistant", content="x", tool_calls=[ToolCall("t9", "a", "not json")])]
    )
    assert rebuilt[0]["content"][1] == {"type": "tool_use", "id": "t9", "name": "a", "input": {}}


def test_anthropic_refusal_and_errors() -> None:
    def refusal(_: httpx2.Request) -> httpx2.Response:
        return httpx2.Response(200, json=_anthropic_message([], "refusal"))

    result = _anthropic(refusal, server_fallbacks=False).chat(
        ChatRequest(model="claude-haiku-5-5", messages=[ChatMessage(role="user", content="q")])
    )
    assert result.finish_reason == "refusal" and result.content is None

    def missing(_: httpx2.Request) -> httpx2.Response:
        return httpx2.Response(404, json={"type": "error", "error": {"type": "not_found_error", "message": "model"}})

    with pytest.raises(ProviderError) as caught:
        _anthropic(missing).chat(ChatRequest(model="claude-x", messages=[ChatMessage(role="user", content="q")]))
    assert caught.value.status == 404


def test_live_models_are_filtered_sorted_and_cached() -> None:
    calls = {"n": 0}

    def handler(request: httpx.Request) -> httpx.Response:
        calls["n"] += 1
        assert request.url.path == "/api/v1/models"
        return httpx.Response(
            200,
            json={
                "data": [
                    {
                        "id": "paid/model",
                        "pricing": {"prompt": "0.1", "completion": "0.2"},
                        "context_length": 1000,
                        "supported_parameters": ["tools"],
                    },
                    {"id": "free/model:free", "context_length": 2000, "supported_parameters": ["structured_outputs"]},
                    {"id": "text-embedding-3-small"},
                ]
            },
        )

    keys, _ = _keys()
    now = {"t": 0.0}
    live = LiveModels(
        make_settings(), keys, http_client=httpx.Client(transport=httpx.MockTransport(handler)), clock=lambda: now["t"]
    )
    models = live.models("openrouter")
    assert [m.id for m in models] == ["free/model:free", "paid/model"]
    assert models[0].free and models[0].structured and not models[1].structured
    live.models("openrouter")
    assert calls["n"] == 1
    now["t"] = 601.0
    live.models("openrouter")
    assert calls["n"] == 2
    assert live.models("deepseek") == []
    assert [m.id for m in live.models("mock")] == ["mock-extractive-1"]


def test_live_models_anthropic_uses_sdk_lister() -> None:
    keys, _ = _keys(anthropic_api_key="sk-ant-1234")
    live = LiveModels(
        make_settings(anthropic_api_key="sk-ant-1234"),
        keys,
        anthropic_lister=lambda key: ["claude-opus-5-5", "claude-haiku-5-5"],
    )
    assert [m.id for m in live.models("anthropic")] == ["claude-haiku-5-5", "claude-opus-5-5"]

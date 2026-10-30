from __future__ import annotations

from dataclasses import replace
from typing import Any, cast

import pytest
from sqlalchemy import text

from app.agent.pipeline import ChatInput
from app.deps import Container
from app.privacy import is_local_provider, redact_text
from app.providers.types import ChatMessage, ChatRequest
from app.retrieval.search import RetrievalResult
from tests.helpers import ScriptedProvider, SignedClient, text_result
from tests.test_api import index
from tests.test_chat_pipeline import LEAVE, ask, make_service
from tests.test_providers import _manager


@pytest.mark.parametrize(
    ("source", "expected"),
    [
        ("Write to anna.petrova+hr@example.co.uk today", "Write to [email] today"),
        ("Call +7 912 345-67-89 or (555) 123-4567", "Call [phone ****6789] or [phone ****4567]"),
        ("IBAN DE89 3704 0044 0532 0130 00 please", "IBAN [IBAN ****3000] please"),
        ("Card 4242 4242 4242 4242 expires", "Card [card ****4242] expires"),
        ("Card 4111-1111-1111-1111", "Card [card ****1111]"),
        ("Account 40817810099910004312 at the bank", "Account [account ****4312] at the bank"),
    ],
)
def test_redacts_personal_identifiers_keeping_last_four(source: str, expected: str) -> None:
    assert redact_text(source) == expected


@pytest.mark.parametrize(
    "safe",
    [
        "Vacation: 28 days, effective 2026-10-22.",
        "The limit is 1 000 000 RUB, invoice INV-2026-0042.",
        "Version 1.2.3 of policy [1] from 01.06.2025",
        "",
    ],
)
def test_leaves_dates_amounts_and_references_alone(safe: str) -> None:
    assert redact_text(safe) == safe


def test_non_luhn_grouped_numbers_are_masked_and_long_runs_are_accounts() -> None:
    assert redact_text("Order 4242 4242 4242 4241") == "Order [number ****4241]"
    assert redact_text("Card 4276 3800 1234 5678") == "Card [number ****5678]"
    assert redact_text("Ref 1234567890123456") == "Ref [account ****3456]"


@pytest.mark.parametrize(
    ("source", "expected"),
    [
        ("Write to ivan.petrov@почта.рф now", "Write to [email] now"),
        ("Почта: мария@пример.рус", "Почта: [email]"),
        ("Phone 8 (916) 123-45-67", "Phone [phone ****4567]"),
        ("Тел. +7 (495) 123-45-67", "Тел. [phone ****4567]"),
        ("Passport ru 4510 123456", "Passport ru [passport ****3456]"),
        ("Паспорт 45 10 123456 выдан", "Паспорт [passport ****3456] выдан"),
        ("СНИЛС 112-233-445 95", "СНИЛС [SNILS]"),
        ("ИНН 7707083893", "ИНН [account ****3893]"),
    ],
)
def test_redacts_russian_and_unicode_identifiers(source: str, expected: str) -> None:
    assert redact_text(source) == expected


@pytest.mark.parametrize(
    "reference",
    [
        "Invoice INV-2026-000123 is overdue",
        "Order ORD-2026-0001234567 shipped",
        "See ticket #2026-000123",
        "Счёт СЧ-2026-000123 оплачен",
        "Dates 2026-10-22 2026-10-23",
    ],
)
def test_keeps_invoice_and_order_references(reference: str) -> None:
    assert redact_text(reference) == reference


def test_redaction_is_idempotent() -> None:
    once = redact_text("mail a@b.io, card 4242424242424242, phone +44 20 7946 0958")
    assert redact_text(once) == once
    assert "4242424242424242" not in once and "a@b.io" not in once


def test_local_providers() -> None:
    assert is_local_provider("mock") and is_local_provider("ollama")
    assert not is_local_provider("anthropic") and not is_local_provider("deepseek")


def test_manager_redacts_prompts_sent_to_external_providers() -> None:
    provider = ScriptedProvider([text_result("ok")], provider_id="deepseek")
    manager, _, _ = _manager(provider)
    manager.chat(
        ChatRequest(
            model="", messages=[ChatMessage(role="user", content="My card is 4242 4242 4242 4242, mail me at x@y.com")]
        ),
        purpose="chat",
    )
    sent = provider.calls[0].messages[0].content
    assert sent == "My card is [card ****4242], mail me at [email]"


def test_manager_keeps_prompts_for_local_providers() -> None:
    provider = ScriptedProvider([text_result("ok")], provider_id="ollama")
    manager, _, _ = _manager(provider)
    manager.chat(ChatRequest(model="", messages=[ChatMessage(role="user", content="x@y.com")]), purpose="chat")
    assert provider.calls[0].messages[0].content == "x@y.com"


def _without_consent(request: ChatInput) -> ChatInput:
    return replace(request, context=replace(request.context, ai_external_allowed=False))


def test_workspace_without_consent_never_reaches_the_external_provider() -> None:
    provider = ScriptedProvider([text_result("Employees get 28 days [1].")], provider_id="deepseek")
    service, _, _ = make_service(RetrievalResult(hits=[LEAVE], confidence=0.8), provider)
    request = ask("How many vacation days do employees get?")
    blocked = _without_consent(request)

    output = service.answer(blocked)

    assert provider.calls == []
    assert output.provider == "mock" and output.degraded and output.external_blocked
    assert not output.refused and output.citations[0]["document_id"] == 10
    assert output.to_response()["external_blocked"] is True


def test_workspace_with_consent_uses_the_configured_provider() -> None:
    provider = ScriptedProvider([text_result("Employees get 28 days [1].")], provider_id="deepseek")
    service, _, _ = make_service(RetrievalResult(hits=[LEAVE], confidence=0.8), provider)
    output = service.answer(ask("How many vacation days do employees get?"))
    assert len(provider.calls) == 1 and output.provider == "deepseek" and not output.external_blocked


POLICY_MD = "# Leave policy\n\nEmployees get 28 calendar days of paid vacation per year.\n"


def _chat(api: SignedClient, workspace_id: int, **extra: Any) -> dict[str, Any]:
    response = api.post(
        "/v1/chat",
        {
            "workspace_id": workspace_id,
            "user": {"id": 5, "role": "employee", "locale": "en", "permissions": [], "department_ids": [1]},
            "channel": "web",
            "conversation_id": "conv-privacy",
            "message": "How many vacation days do employees get?",
            "context": {"today": "2026-10-22", "min_confidence": 0.4, **extra},
        },
    )
    assert response.status_code == 200, response.text
    return response.json()


@pytest.mark.db
def test_chat_api_enforces_the_ai_data_control(api: SignedClient) -> None:
    assert index(api, 1, POLICY_MD, title="Leave policy", department_ids=[1]).status_code == 200
    api.put("/v1/admin/ai/settings", {"provider": "anthropic"})

    blocked = _chat(api, 100)
    assert blocked["provider"] == "mock" and blocked["external_blocked"] is True and blocked["refused"] is False


@pytest.mark.db
def test_workspace_purge_removes_every_row_of_the_workspace(api: SignedClient, container: Container) -> None:
    for document_id, workspace_id in ((1, 100), (2, 200)):
        indexed = index(
            api, document_id, POLICY_MD, title="Leave policy", workspace_id=workspace_id, department_ids=[1]
        )
        assert indexed.status_code == 200
        _chat(api, workspace_id)

    response = api.delete("/v1/workspaces/100")
    assert response.status_code == 200
    purged = response.json()["purged"]
    assert purged["documents"] == 1 and purged["chunks"] >= 1 and purged["conversation_turns"] >= 2

    with container.session_factory() as session:
        for table in ("documents", "chunks", "conversation_turns", "ai_usage"):
            remaining = session.execute(text(f"SELECT count(*) FROM rag.{table} WHERE workspace_id = 100")).scalar()
            assert remaining == 0, table
        kept = session.execute(text("SELECT count(*) FROM rag.chunks WHERE workspace_id = 200")).scalar()
        assert kept is not None and kept >= 1

    assert api.delete("/v1/workspaces/100").json()["purged"]["documents"] == 0
    assert api.delete("/v1/workspaces/0").status_code == 404


def _remote_registry() -> Any:
    from app.crypto import secret_box_for
    from app.embeddings.registry import EmbeddingsRegistry
    from app.providers.keys import ProviderKeys
    from tests.helpers import InMemorySettingsStore, make_settings

    settings = make_settings(
        embeddings_provider="openai-compatible",
        embeddings_model="text-embedding-3-small",
        embeddings_base_url="https://embeddings.invalid/v1",
        embeddings_api_key="sk-test",
    )
    store = InMemorySettingsStore(settings)
    return EmbeddingsRegistry(settings, store, ProviderKeys(store, settings, secret_box_for(settings)))


@pytest.fixture
def remote_calls(monkeypatch: pytest.MonkeyPatch) -> list[str]:
    import httpx

    calls: list[str] = []

    def _send(self: httpx.Client, request: httpx.Request, **_: Any) -> httpx.Response:
        calls.append(str(request.url))
        raise AssertionError("remote embeddings must not be called")

    monkeypatch.setattr(httpx.Client, "send", _send)
    return calls


def test_registry_uses_an_on_device_embedder_without_consent(remote_calls: list[str]) -> None:
    from app.embeddings.hashing import HashEmbedder
    from app.embeddings.remote import RemoteEmbedder

    registry = _remote_registry()
    assert registry.is_external()
    private = registry.active(external_allowed=False)
    assert isinstance(private, HashEmbedder)
    private.embed_documents(["Ivan Petrov, salary 100 000"])
    private.embed_query("salary of Ivan")
    assert isinstance(registry.active(external_allowed=True), RemoteEmbedder)
    assert remote_calls == []


def test_chat_without_consent_never_sends_the_question_to_remote_embeddings(remote_calls: list[str]) -> None:
    service, _, _ = make_service(RetrievalResult(hits=[LEAVE], confidence=0.8))
    service._embeddings = _remote_registry()
    request = ask("How many vacation days does ivan@example.com get?")
    blocked = _without_consent(request)
    service.answer(blocked)
    assert remote_calls == []


def test_indexing_without_consent_never_reaches_remote_embeddings(remote_calls: list[str]) -> None:
    from dataclasses import dataclass, field

    from app.embeddings.hashing import HashEmbedder
    from app.ingest.chunker import Chunker
    from app.ingest.index import IndexService
    from app.ingest.pages import PageText
    from app.retrieval.store import DocumentMeta

    @dataclass
    class _Store:
        embedders: list[Any] = field(default_factory=list)

        def current_revision(self, *_: Any) -> None:
            return None

        def replace_document(self, _meta: Any, drafts: Any, _vectors: Any, embedder: Any, **kw: Any) -> Any:
            self.embedders.append(embedder)
            return type(
                "Stored", (), {"chunks": len(drafts), "embedding_model": embedder.model_id, "warnings": kw["warnings"]}
            )()

    store = _Store()
    service = IndexService(Chunker(), _remote_registry(), cast(Any, store))
    meta = DocumentMeta(document_id=1, workspace_id=100, title="Leave policy", mime="text/markdown")
    outcome = service.index(meta, [PageText(page=1, text=POLICY_MD)], ocr_used=False, external_allowed=False)
    assert any("external embeddings not allowed" in w for w in outcome.warnings)
    assert remote_calls == []
    assert store.embedders and isinstance(store.embedders[0], HashEmbedder)


def _turn(session: Any, workspace_id: int, user_id: int, age_days: int) -> None:
    session.execute(
        text(
            "INSERT INTO rag.conversation_turns (workspace_id, conversation_id, user_id, role, content, created_at) "
            "VALUES (:ws, 'c', :uid, 'user', 'question', now() - make_interval(days => :age))"
        ),
        {"ws": workspace_id, "uid": user_id, "age": age_days},
    )


def _turns(container: Container, where: str) -> int:
    with container.session_factory() as session:
        return int(session.execute(text(f"SELECT count(*) FROM rag.conversation_turns WHERE {where}")).scalar() or 0)


@pytest.mark.db
def test_user_purge_removes_only_that_users_conversation_turns(api: SignedClient, container: Container) -> None:
    with container.session_factory() as session, session.begin():
        _turn(session, 100, 7, 0)
        _turn(session, 200, 7, 1)
        _turn(session, 100, 8, 0)

    response = api.delete("/v1/users/7")
    assert response.status_code == 200
    assert response.json()["purged"]["conversation_turns"] == 2
    assert _turns(container, "user_id = 7") == 0 and _turns(container, "user_id = 8") == 1
    assert api.delete("/v1/users/7").json()["purged"]["conversation_turns"] == 0
    assert api.delete("/v1/users/0").status_code == 404


@pytest.mark.db
def test_memory_prune_follows_the_workspace_retention(api: SignedClient, container: Container) -> None:
    with container.session_factory() as session, session.begin():
        for workspace_id in (100, 200, 300):
            for age in (1, 10, 40):
                _turn(session, workspace_id, 5, age)

    response = api.post(
        "/v1/privacy/prune-memory",
        {"default_days": 180, "workspaces": [{"workspace_id": 200, "days": 7}, {"workspace_id": 300, "days": 365}]},
    )
    assert response.status_code == 200
    assert _turns(container, "workspace_id = 100") == 2
    assert _turns(container, "workspace_id = 200") == 1
    assert _turns(container, "workspace_id = 300") == 2
    assert response.json()["pruned"] == 4

from __future__ import annotations

import gzip
import json
from typing import Any

import pytest
from sqlalchemy import text

from app.deps import Container
from tests.helpers import FakeWeb, SignedClient, make_text_pdf

pytestmark = pytest.mark.db

TODAY = "2026-10-22"

POLICY_MD = """# Leave policy

## Annual leave

Employees get 28 calendar days of paid vacation per year. Vacation requests are approved by the line manager.

## Sick leave

A doctor's note is required after three days of sick leave.
"""

TRAVEL_MD = """# Регламент командировок

## Суточные

Суточные при командировках по России составляют 700 рублей в день. Отчёт сдаётся в течение трёх рабочих дней.
"""


def index_payload(text_value: str, **meta: Any) -> dict[str, Any]:
    return {
        "workspace_id": meta.pop("workspace_id", 100),
        "upload_rev": meta.pop("upload_rev", 1),
        "title": meta.pop("title", "Doc"),
        "mime": meta.pop("mime", "text/markdown"),
        "ocr_used": False,
        "metadata": {"approval_status": "approved", "effective_date": "2026-01-01", **meta},
        "pages": [{"page": 1, "text": text_value}],
    }


def index(api: SignedClient, document_id: int, text_value: str, **meta: Any) -> Any:
    return api.post(f"/v1/documents/{document_id}/index", index_payload(text_value, **meta))


def chat(api: SignedClient, message: str, locale: str = "en", **extra: Any) -> dict[str, Any]:
    payload = {
        "workspace_id": 100,
        "user": {
            "id": 5,
            "role": extra.pop("role", "employee"),
            "locale": locale,
            "permissions": extra.pop("permissions", ["assistant.use", "incidents.create"]),
            "department_ids": extra.pop("department_ids", [1]),
            "privileged": False,
        },
        "channel": extra.pop("channel", "telegram"),
        "conversation_id": extra.pop("conversation_id", None),
        "message": message,
        "context": {
            "today": TODAY,
            "timezone": "Europe/Moscow",
            "min_confidence": 0.4,
            "ai_external_allowed": extra.pop("ai_external_allowed", False),
        },
    }
    response = api.post("/v1/chat", payload)
    assert response.status_code == 200, response.text
    return response.json()


@pytest.fixture
def seeded(api: SignedClient) -> SignedClient:
    assert index(api, 1, POLICY_MD, title="Leave policy", department_ids=[1]).status_code == 200
    assert index(api, 2, TRAVEL_MD, title="Регламент командировок").status_code == 200
    return api


def test_health_and_metrics_are_unsigned(api: SignedClient) -> None:
    health = api.client.get("/healthz")
    assert health.status_code == 200
    assert health.json() == {
        "status": "ok",
        "service": "ai",
        "product": "TestProduct",
        "product_slug": "testproduct",
        "version": "0.1.0",
    }
    assert "X-Request-Id" in health.headers
    ready = api.client.get("/readyz").json()
    assert ready["status"] == "ok" and ready["checks"]["database"] == "ok"
    assert ready["checks"]["embeddings"]["configured"] == "hash:hash-384"
    assert ready["checks"]["embeddings"]["state"] in ("ready", "not_loaded")
    metrics = api.client.get("/metrics")
    assert metrics.status_code == 200 and "ai_http_requests_total" in metrics.text


@pytest.mark.parametrize(
    ("method", "path"),
    [
        ("POST", "/v1/chat"),
        ("GET", "/v1/admin/ai/settings"),
        ("POST", "/v1/documents/1/index"),
        ("POST", "/v1/documents/1/extract"),
        ("PATCH", "/v1/documents/1/metadata"),
        ("DELETE", "/v1/documents/1?workspace_id=1"),
    ],
)
def test_v1_requires_signature(api: SignedClient, method: str, path: str) -> None:
    assert api.client.request(method, path, json={}).status_code == 401


def test_extract_pdf_returns_pages(api: SignedClient) -> None:
    pdf = make_text_pdf(
        [
            ["1. Expense policy", "Expense reports are submitted within 30 days."],
            ["2. Approvals", "Reports above 500 EUR need a second approval."],
        ]
    )
    response = api.raw("POST", "/v1/documents/7/extract?mime=application%2Fpdf&max_pages=5", pdf)
    assert response.status_code == 200, response.text
    body = response.json()
    assert body["pages_total"] == 2 and body["ocr_used"] is False and body["language_guess"] == "en"
    assert [p["page"] for p in body["pages"]] == [1, 2]
    assert body["pages"][0]["text"].startswith("# 1. Expense policy")
    assert "500 EUR" in body["pages"][1]["text"]


@pytest.mark.parametrize(
    ("path", "content", "code"),
    [
        ("/v1/documents/8/extract?mime=application%2Fx-msdownload", b"\x00\x01\x02", "unsupported_type"),
        ("/v1/documents/8/extract?mime=application%2Fpdf&max_pages=1", None, "too_many_pages"),
        ("/v1/documents/8/extract?mime=text%2Fplain", b"   \n  ", "empty_text"),
    ],
)
def test_extract_error_codes(api: SignedClient, path: str, content: bytes | None, code: str) -> None:
    data = content if content is not None else make_text_pdf([["Page one text"], ["Page two text"]])
    response = api.raw("POST", path, data)
    assert response.status_code == 422 and response.json()["error"] == code


def test_index_is_atomic_idempotent_and_rejects_stale_revisions(api: SignedClient, container: Container) -> None:
    first = index(api, 7, POLICY_MD, title="Leave policy", upload_rev=2, tags=["hr"], version="3")
    assert first.status_code == 200, first.text
    body = first.json()
    assert body["chunks"] >= 1 and body["pages"] == 1 and body["upload_rev"] == 2

    def ordinals() -> list[int]:
        with container.engine.connect() as conn:
            rows = conn.execute(text("SELECT ordinal FROM rag.chunks WHERE document_id=7 ORDER BY ordinal")).all()
            return [r.ordinal for r in rows]

    before = ordinals()
    again = index(api, 7, POLICY_MD, title="Leave policy", upload_rev=2, tags=["hr"], version="3")
    assert again.status_code == 200 and again.json()["chunks"] == body["chunks"] and ordinals() == before

    stale = index(api, 7, "Old text that must not win.", title="Leave policy", upload_rev=1)
    assert stale.status_code == 409 and stale.json()["error"] == "stale_revision"
    assert ordinals() == before

    empty = api.post("/v1/documents/9/index", {**index_payload(""), "pages": [{"page": 1, "text": "   "}]})
    assert empty.status_code == 422 and empty.json()["error"] == "empty_text"

    with container.engine.connect() as conn:
        row = conn.execute(
            text("SELECT status, tags, version, upload_rev, approval_status FROM rag.documents WHERE id=7")
        ).one()
    assert row.status == "indexed" and row.tags == ["hr"] and row.version == "3" and row.upload_rev == 2
    assert row.approval_status == "approved"


def test_index_accepts_gzip_bodies(api: SignedClient) -> None:
    raw = gzip.compress(json.dumps(index_payload(POLICY_MD, title="Leave policy")).encode())
    response = api.raw(
        "POST", "/v1/documents/11/index", raw, {"content-encoding": "gzip", "content-type": "application/json"}
    )
    assert response.status_code == 200, response.text
    assert response.json()["chunks"] >= 1


def test_index_validation(api: SignedClient) -> None:
    response = api.post("/v1/documents/1/index", {"workspace_id": 0, "title": ""})
    assert response.status_code == 422 and response.json()["error"] == "invalid_body"


def test_metadata_patch_flips_visibility_without_reembedding(api: SignedClient, container: Container) -> None:
    assert index(api, 3, POLICY_MD, title="Leave policy", approval_status="draft").status_code == 200
    question = "How many vacation days do employees get?"
    assert chat(api, question)["refused"] is True
    with container.engine.connect() as conn:
        vectors_before = conn.execute(text("SELECT embedding::text FROM rag.chunks WHERE document_id=3")).all()
    patch = {
        "workspace_id": 100,
        "title": "Leave policy",
        "department_ids": [],
        "roles": [],
        "tags": ["hr"],
        "version": "2",
        "effective_date": "2026-01-01",
        "approval_status": "approved",
        "archived": False,
    }
    response = api.patch("/v1/documents/3/metadata", patch)
    assert response.status_code == 200 and response.json()["updated_chunks"] >= 1
    with container.engine.connect() as conn:
        assert conn.execute(text("SELECT embedding::text FROM rag.chunks WHERE document_id=3")).all() == vectors_before
    answer = chat(api, question)
    assert answer["refused"] is False and answer["citations"][0]["document_id"] == 3
    assert answer["citations"][0]["version"] == "2" and answer["citations"][0]["tags"] == ["hr"]

    archived = api.patch("/v1/documents/3/metadata", {**patch, "archived": True})
    assert archived.status_code == 200
    assert chat(api, question)["refused"] is True
    assert api.patch("/v1/documents/404/metadata", patch).status_code == 404
    assert api.patch("/v1/documents/3/metadata", {**patch, "workspace_id": 200}).status_code == 409


def test_chat_answers_with_contract_fields(seeded: SignedClient) -> None:
    body = chat(seeded, "How many vacation days do employees get?", conversation_id="tg-1-900000001")
    assert body["refused"] is False and body["provider"] == "mock" and body["model"] == "mock-extractive-1"
    assert "28 calendar days" in body["answer_markdown"] and "[1]" in body["answer_markdown"]
    citation = body["citations"][0]
    assert citation["document_id"] == 1 and citation["title"] == "Leave policy" and citation["chunk_id"] >= 1
    assert citation["effective_date"] == "2026-01-01" and "url" not in citation
    assert body["confidence"] >= 0.4 and body["confidence_band"] in ("high", "medium")
    assert body["answer_kind"] == "document" and body["disclaimers"] == [] and body["refusal_reason"] is None
    assert [a["type"] for a in body["actions"]] == ["open_source"]
    assert body["answer_html"].startswith("<p>")


def test_chat_in_russian(seeded: SignedClient) -> None:
    body = chat(seeded, "Сколько составляют суточные в командировке?", "ru")
    assert body["refused"] is False
    assert body["answer_markdown"].startswith("Согласно утверждённым документам:")
    assert "700 рублей" in body["answer_markdown"] and body["citations"][0]["document_id"] == 2


@pytest.mark.parametrize(
    ("locale", "question", "expected"),
    [
        ("en", "What is the parking fee at the airport?", "I couldn't find this in your company's approved documents."),
        ("ru", "Какая стоимость парковки в аэропорту?", "Я не нашёл ответа в утверждённых документах компании."),
    ],
)
def test_chat_refuses_when_not_in_documents(seeded: SignedClient, locale: str, question: str, expected: str) -> None:
    body = chat(seeded, question, locale)
    assert body["refused"] is True and body["answer_markdown"].startswith(expected)
    assert body["refusal_reason"] == "low_confidence" and body["confidence_band"] == "low"
    assert body["citations"] == [] and body["actions"][-1] == {
        "type": "escalate",
        "label": "Pass to manager" if locale == "en" else "Передать менеджеру",
        "payload": {"reason": "low_confidence"},
    }


def test_chat_department_acl(seeded: SignedClient) -> None:
    question = "How many vacation days do employees get?"
    assert chat(seeded, question, department_ids=[1])["refused"] is False
    assert chat(seeded, question, department_ids=[2])["refused"] is True


def test_chat_conversation_memory(seeded: SignedClient, container: Container) -> None:
    chat(seeded, "How many vacation days do employees get?", conversation_id="conv-mem")
    body = chat(seeded, "Who approves them?", conversation_id="conv-mem")
    assert body["refused"] is False and "line manager" in body["answer_markdown"]
    with container.engine.connect() as conn:
        assert (
            conn.execute(
                text("SELECT count(*) FROM rag.conversation_turns WHERE conversation_id='conv-mem'")
            ).scalar_one()
            == 4
        )


def test_search_chunks_delete_and_reembed(seeded: SignedClient) -> None:
    search = seeded.post("/v1/search", {"workspace_id": 100, "query": "vacation days", "k": 3})
    assert search.status_code == 200
    results = search.json()["results"]
    assert results[0]["document_id"] == 1 and results[0]["section_path"] == "Leave policy"
    scoped = seeded.post(
        "/v1/search",
        {"workspace_id": 100, "query": "vacation days", "user": {"role": "employee", "department_ids": [2]}},
    ).json()
    assert {r["document_id"] for r in scoped["results"]} <= {2}

    preview = seeded.get("/v1/documents/1/chunks?workspace_id=100&limit=5").json()
    first = preview["chunks"][0]
    assert set(first) == {"chunk_id", "ordinal", "page", "section_path", "content"}
    assert first["section_path"] == "Leave policy" and "28 calendar days" in first["content"]
    assert seeded.get("/v1/documents/1/chunks?workspace_id=200").json()["chunks"] == []
    context = seeded.get(f"/v1/documents/1/chunks/{first['chunk_id']}?workspace_id=100&context=1").json()
    assert context["chunk"]["chunk_id"] == first["chunk_id"] and context["before"] == []
    assert seeded.get(f"/v1/documents/1/chunks/{first['chunk_id']}?workspace_id=200").status_code == 404

    batch = seeded.post("/v1/workspaces/100/reembed", {"cursor": 0, "batch_documents": 1}).json()
    assert batch["documents"] == 1 and batch["next_cursor"] == 1 and batch["chunks"] >= 1
    last = seeded.post("/v1/workspaces/100/reembed", {"cursor": 1, "batch_documents": 1}).json()
    assert last["documents"] == 1 and last["next_cursor"] is None
    everything = seeded.post("/v1/workspaces/100/reembed")
    assert everything.status_code == 200 and everything.json()["chunks"] >= 2

    wrong_ws = seeded.delete("/v1/documents/1?workspace_id=200")
    assert wrong_ws.status_code == 409
    deleted = seeded.delete("/v1/documents/1?workspace_id=100")
    assert deleted.status_code == 200 and deleted.json()["deleted_chunks"] >= 1
    assert chat(seeded, "How many vacation days do employees get?")["refused"] is True


def test_admin_providers_and_keys(api: SignedClient) -> None:
    body = api.get("/v1/admin/ai/providers?locale=ru").json()
    assert isinstance(body, list)
    providers = {p["id"]: p for p in body}
    assert list(providers)[:2] == ["mock", "anthropic"]
    assert set(providers) == {
        "mock",
        "anthropic",
        "openai",
        "deepseek",
        "openrouter",
        "nvidia",
        "opencode-zen",
        "opencode-go",
        "ollama",
    }
    assert providers["mock"]["configured"] and not providers["mock"]["needs_key"]
    assert providers["mock"]["note"].startswith("Встроенный")
    anthropic = providers["anthropic"]
    assert anthropic["models"] == ["claude-opus-5-5", "claude-sonnet-5-5", "claude-haiku-5-5", "claude-fable-5-1"]
    assert (anthropic["configured"], anthropic["key_source"], anthropic["key_hint"]) == (False, None, None)

    saved = api.put("/v1/admin/ai/keys/anthropic", {"api_key": "sk-ant-secret-9876"})
    assert saved.json() == {"provider": "anthropic", "configured": True, "key_source": "settings", "key_hint": "…9876"}
    listed = {p["id"]: p for p in api.get("/v1/admin/ai/providers").json()}
    assert listed["anthropic"]["key_hint"] == "…9876" and "sk-ant-secret" not in api.get("/v1/admin/ai/providers").text
    cleared = api.put("/v1/admin/ai/keys/anthropic", {"api_key": None}).json()
    assert cleared["configured"] is False and cleared["key_source"] is None
    assert api.put("/v1/admin/ai/keys/mock", {"api_key": "x"}).status_code == 422
    assert api.put("/v1/admin/ai/keys/nope", {"api_key": "x"}).status_code == 404


def test_admin_settings_validation_and_update(api: SignedClient) -> None:
    current = api.get("/v1/admin/ai/settings").json()
    assert current["provider"] == "mock" and current["model"] == "mock-extractive-1"
    assert current["embeddings"] == {"provider": "hash", "model": "hash-384"}
    assert current["embeddings_providers"] == ["local", "openai-compatible", "hash"]

    invalid = api.put("/v1/admin/ai/settings", {"provider": "skynet"})
    assert invalid.status_code == 422 and "provider" in invalid.json()["errors"]
    assert invalid.json()["detail"] == "unknown provider 'skynet'"
    assert api.put("/v1/admin/ai/settings", {"temperature": 5}).status_code == 422

    updated = api.put(
        "/v1/admin/ai/settings", {"provider": "deepseek", "fallback_model": "deepseek-reasoner", "temperature": 0.4}
    ).json()
    assert (updated["provider"], updated["model"], updated["fallback_model"]) == (
        "deepseek",
        "deepseek-chat",
        "deepseek-reasoner",
    )
    assert updated["temperature"] == 0.4 and updated["reembed_required"] is False
    emb = api.put("/v1/admin/ai/settings", {"embeddings": {"provider": "hash", "model": "hash-256"}}).json()
    assert emb["reembed_required"] is True and emb["embeddings_status"]["configured"] == "hash:hash-256"


def test_admin_models_test_and_usage(api: SignedClient, fake_web: FakeWeb) -> None:
    mock_models = api.get("/v1/admin/ai/providers/mock/models").json()
    assert mock_models["source"] == "live" and mock_models["models"][0]["id"] == "mock-extractive-1"
    deepseek = api.get("/v1/admin/ai/providers/deepseek/models").json()
    assert deepseek["source"] == "catalog" and [m["id"] for m in deepseek["models"]] == [
        "deepseek-chat",
        "deepseek-reasoner",
    ]
    assert api.get("/v1/admin/ai/providers/nope/models").status_code == 404

    ok = api.post("/v1/admin/ai/test").json()
    assert ok["ok"] is True and ok["provider"] == "mock" and ok["reply"] == "OK"

    api.put("/v1/admin/ai/settings", {"provider": "anthropic"})
    failed = api.post("/v1/admin/ai/test").json()
    assert failed["ok"] is False and "not configured" in failed["error"] and failed["model"] == "claude-opus-5-5"

    usage = api.get("/v1/admin/ai/usage?days=7").json()
    assert len(usage["days"]) == 7 and usage["days"][-1]["requests"] >= 2
    assert usage["total_requests"] >= 2 and usage["errors"] >= 1
    by_model = {(m["provider"], m["model"]): m for m in usage["by_model"]}
    assert by_model[("mock", "mock-extractive-1")]["requests"] == 1
    assert by_model[("anthropic", "claude-opus-5-5")]["cost_usd"] == 0.0
    assert {p["purpose"] for p in usage["by_purpose"]} == {"test"}
    assert api.get("/v1/admin/ai/usage?days=0").status_code == 422


def test_unconfigured_provider_degrades_chat_to_mock(seeded: SignedClient) -> None:
    seeded.put("/v1/admin/ai/settings", {"provider": "anthropic"})
    body = chat(seeded, "How many vacation days do employees get?", ai_external_allowed=True)
    assert body["degraded"] is True and body["provider"] == "mock" and body["refused"] is False
    assert body["model"] == "mock-extractive-1"

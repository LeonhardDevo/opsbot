from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import pytest
import yaml

from tests.helpers import SignedClient

pytestmark = pytest.mark.db

GOLDEN = yaml.safe_load((Path(__file__).parent / "golden" / "cleaning.yaml").read_text(encoding="utf-8"))
FIXTURE: dict[str, Any] = GOLDEN["fixture"]
SERVICE_ROOT = Path(__file__).resolve().parents[1]
PACK_CANDIDATES = (
    SERVICE_ROOT.parent / "web" / "resources" / "starter-packs" / "cleaning",
    SERVICE_ROOT.parent.parent / "docs" / "seed-documents" / "cleaning",
)
LOCALE_OFFSET = {"en": 100, "ru": 200}


def pack_dir() -> Path:
    configured = os.environ.get("STARTER_PACK_DIR")
    candidates = (Path(configured),) if configured else PACK_CANDIDATES
    for candidate in candidates:
        if (candidate / "pack.json").is_file():
            return candidate
    pytest.skip("the Cleaning starter pack is not available")


@dataclass(frozen=True, slots=True)
class PackDocument:
    document_id: int
    key: str
    locale: str
    title: str
    text: str
    tags: list[str]
    department_ids: list[int]
    roles: list[str]
    version: str


def load_pack() -> list[PackDocument]:
    root = pack_dir()
    manifest = json.loads((root / "pack.json").read_text(encoding="utf-8"))
    departments: dict[str, int] = FIXTURE["departments"]
    documents: list[PackDocument] = []
    for locale, offset in LOCALE_OFFSET.items():
        for index, entry in enumerate(manifest["documents"], start=1):
            documents.append(
                PackDocument(
                    document_id=offset + index,
                    key=entry["key"],
                    locale=locale,
                    title=entry["title"][locale],
                    text=(root / locale / entry["file"]).read_text(encoding="utf-8"),
                    tags=list(entry.get("tags", [])),
                    department_ids=[departments[code] for code in entry.get("department_codes", [])],
                    roles=list(entry.get("roles", [])),
                    version=str(entry.get("version") or "1.0"),
                )
            )
    return documents


def index_body(doc: PackDocument, **overrides: Any) -> dict[str, Any]:
    metadata: dict[str, Any] = {
        "department_ids": doc.department_ids,
        "roles": doc.roles,
        "tags": doc.tags,
        "version": doc.version,
        "effective_date": FIXTURE["effective_date"],
        "approval_status": "approved",
        "language": doc.locale,
    }
    metadata.update(overrides.pop("metadata", {}))
    return {
        "workspace_id": 100,
        "upload_rev": 1,
        "title": overrides.pop("title", doc.title),
        "mime": "text/markdown",
        "ocr_used": False,
        "archived": overrides.pop("archived", False),
        "metadata": metadata,
        "pages": [{"page": 1, "text": overrides.pop("text", doc.text)}],
    }


@pytest.fixture
def pack(api: SignedClient) -> dict[int, PackDocument]:
    documents = load_pack()
    for doc in documents:
        response = api.post(f"/v1/documents/{doc.document_id}/index", index_body(doc))
        assert response.status_code == 200, response.text
    return {doc.document_id: doc for doc in documents}


def ask(api: SignedClient, question: str, locale: str, user: dict[str, Any] | None = None) -> dict[str, Any]:
    profile = {**FIXTURE["user"], **(user or {})}
    departments: dict[str, int] = FIXTURE["departments"]
    payload = {
        "workspace_id": 100,
        "user": {
            "id": 12,
            "role": profile["role"],
            "locale": locale,
            "permissions": FIXTURE["user"]["permissions"],
            "department_ids": [departments[code] for code in profile["departments"]],
            "privileged": bool(profile.get("privileged", False)),
        },
        "channel": "telegram",
        "conversation_id": None,
        "message": question,
        "context": {
            "today": FIXTURE["today"],
            "timezone": "Europe/Moscow",
            "min_confidence": FIXTURE["min_confidence"],
            "ai_external_allowed": False,
        },
    }
    response = api.post("/v1/chat", payload)
    assert response.status_code == 200, response.text
    body: dict[str, Any] = response.json()
    return body


def cited_keys(body: dict[str, Any], pack: dict[int, PackDocument]) -> list[str]:
    return [pack[c["document_id"]].key for c in body["citations"] if c["document_id"] in pack]


@pytest.mark.parametrize("case", GOLDEN["cases"], ids=[f"q{case['id']}-{case['locale']}" for case in GOLDEN["cases"]])
def test_golden_question(pack: dict[int, PackDocument], api: SignedClient, case: dict[str, Any]) -> None:
    body = ask(api, case["question"], case["locale"])
    if case["expect"] == "answer":
        assert body["refused"] is False, body["answer_markdown"]
        keys = cited_keys(body, pack)
        assert keys and keys[0] == case["cites"], keys
        assert all(pack[c["document_id"]].locale == case["locale"] for c in body["citations"])
        assert body["answer_kind"] == "document" and body["confidence"] >= FIXTURE["min_confidence"]
        if case.get("incident"):
            incident = next(a for a in body["actions"] if a["type"] == "create_incident")
            assert incident["payload"]["category"] == case["incident"]
        if case.get("disclaimer"):
            assert body["disclaimers"] == [case["disclaimer"]]
            expected = {
                "en": "The final amount is confirmed by your manager.",
                "ru": "Итоговую сумму подтверждает менеджер.",
            }[case["locale"]]
            assert body["answer_markdown"].endswith(expected)
        assert ("compensation" in body["disclaimers"]) == ("compensation-rules" in keys)
    else:
        assert body["refused"] is True, body["answer_markdown"]
        assert body["citations"] == []
        assert any(a["type"] == "escalate" for a in body["actions"])
        assert body["provider"] in ("mock", "rules") or body["refusal_reason"] == "low_confidence"


@pytest.mark.parametrize("case", GOLDEN["acl"], ids=[case["id"] for case in GOLDEN["acl"]])
def test_golden_acl(pack: dict[int, PackDocument], api: SignedClient, case: dict[str, Any]) -> None:
    body = ask(api, case["question"], case["locale"], case["user"])
    keys = cited_keys(body, pack)
    if "must_cite" in case:
        assert body["refused"] is False and case["must_cite"] in keys, keys
    if "never_cite" in case:
        assert case["never_cite"] not in keys, keys


def test_golden_lifecycle_drafts_future_and_archived_copies_are_never_cited(
    pack: dict[int, PackDocument], api: SignedClient
) -> None:
    lifecycle = GOLDEN["lifecycle"]
    original = next(doc for doc in pack.values() if doc.key == "damage-property" and doc.locale == "en")
    for copy in lifecycle["copies"]:
        body = index_body(
            original,
            text=f"{original.text}\n\n## Blue folder\n\n{lifecycle['marker']}",
            archived=copy["archived"],
            metadata={"approval_status": copy["approval_status"], "effective_date": copy["effective_date"]},
        )
        response = api.post(f"/v1/documents/{copy['id']}/index", body)
        assert response.status_code == 200, response.text
    copy_ids = {copy["id"] for copy in lifecycle["copies"]}
    answer = ask(api, lifecycle["question"], "en")
    assert not copy_ids & {c["document_id"] for c in answer["citations"]}

    draft = lifecycle["copies"][0]
    patch = {
        "workspace_id": 100,
        "title": original.title,
        "department_ids": [],
        "roles": [],
        "tags": original.tags,
        "version": "1.1",
        "effective_date": FIXTURE["effective_date"],
        "approval_status": "approved",
        "archived": False,
    }
    assert api.patch(f"/v1/documents/{draft['id']}/metadata", patch).status_code == 200
    approved = ask(api, lifecycle["question"], "en")
    assert approved["refused"] is False
    assert draft["id"] in {c["document_id"] for c in approved["citations"]}


def test_golden_injection_chunk_never_creates_tasks(pack: dict[int, PackDocument], api: SignedClient) -> None:
    injection = GOLDEN["injection"]
    base = next(doc for doc in pack.values() if doc.key == "no-access" and doc.locale == "en")
    body = index_body(base, title="No access: waiting notes", text=f"# Waiting notes\n\n{injection['chunk']}")
    assert api.post("/v1/documents/950/index", body).status_code == 200
    answer = ask(api, injection["question"], "en")
    assert answer["refused"] is False
    assert "create_task" not in [a["type"] for a in answer["actions"]]

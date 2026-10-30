from __future__ import annotations

from typing import Any

import pytest

from app.actions.schemas import MAX_ACTIONS, validate_action
from app.actions.validate import finalize_actions, propose_actions
from app.agent.labels import count_phrase, ru_plural
from app.agent.templates import render_tool_answer
from app.privacy.redaction import redact_text
from app.tools.intents import infer_category, match_data_intent

ALL_TOOLS = ("search_documents", "who_handles", "my_tasks", "incident_status")
EMPLOYEE = frozenset({"assistant.use", "incidents.create", "tasks.create"})


@pytest.mark.parametrize(
    ("message", "tool", "args"),
    [
        ("Кому передать жалобу клиента?", "who_handles", {"category": "complaint"}),
        ("К кому обратиться, если я разбил вазу?", "who_handles", {"category": "damage"}),
        ("Кто отвечает за вопросы по ключам?", "who_handles", {"category": "question"}),
        ("Who handles client complaints?", "who_handles", {"category": "complaint"}),
        ("Who should I contact if nobody opens the door?", "who_handles", {"category": "no_access"}),
        ("who should i tell?", "who_handles", {"category": "task"}),
        ("Мои задачи", "my_tasks", {"status": "open"}),
        ("Какие у меня просроченные задачи?", "my_tasks", {"status": "overdue"}),
        ("Show my tasks please", "my_tasks", {"status": "open"}),
        ("what are my overdue tasks", "my_tasks", {"status": "overdue"}),
        ("Что с инцидентом № 12?", "incident_status", {"number": 12}),
        ("статус инцидента 7", "incident_status", {"number": 7}),
        ("What is the status of incident #34?", "incident_status", {"number": 34}),
        ("incident 5 status", "incident_status", {"number": 5}),
    ],
)
def test_data_intents_ru_en(message: str, tool: str, args: dict[str, Any]) -> None:
    match = match_data_intent(message, ALL_TOOLS)
    assert match is not None and match.tool == tool and match.args == args


@pytest.mark.parametrize(
    "message",
    [
        "Who won the football match yesterday?",
        "Кто выиграл вчерашний футбольный матч?",
        "What should I do if I scratched a client's table?",
        "Как поменять пароль от почты?",
        "Where do I return the client keys after the last visit?",
    ],
)
def test_ordinary_questions_do_not_match_data_intents(message: str) -> None:
    assert match_data_intent(message, ALL_TOOLS) is None


def test_intents_respect_available_tools() -> None:
    assert match_data_intent("my tasks", ("search_documents", "who_handles")) is None


@pytest.mark.parametrize(
    ("message", "category"),
    [
        ("Я поцарапала стол", "damage"),
        ("клиент недоволен уборкой", "complaint"),
        ("клиент не открывает дверь", "no_access"),
        ("я порезался осколком", "injury"),
        ("у клиента пропал браслет", "lost_item"),
        ("I broke a vase", "damage"),
        ("the client is unhappy", "complaint"),
        ("I am locked out", "no_access"),
        ("I burned my hand", "injury"),
        ("a ring is missing", "lost_item"),
        ("just a general thing", "task"),
    ],
)
def test_category_inference(message: str, category: str) -> None:
    assert infer_category(message) == category


@pytest.mark.parametrize(
    ("message", "category", "severity"),
    [
        ("Я поцарапала стол у клиента", "damage", "medium"),
        ("I cut myself on broken glass", "injury", "high"),
        ("Nobody opens the door", "no_access", "low"),
    ],
)
def test_incident_proposals(message: str, category: str, severity: str) -> None:
    [action] = [
        a
        for a in propose_actions(message, locale="en", permissions=EMPLOYEE, refused=False)
        if a["type"] == "create_incident"
    ]
    assert action["payload"] == {"category": category, "severity": severity, "description": message}


def test_task_proposal_strips_the_trigger_phrase() -> None:
    [action] = propose_actions(
        "Создай задачу: купить перчатки для бригады", locale="ru", permissions=EMPLOYEE, refused=False
    )
    assert action == {
        "type": "create_task",
        "label": "Создать задачу",
        "payload": {
            "title": "Купить перчатки для бригады",
            "description": "Создай задачу: купить перчатки для бригады",
        },
    }


def test_refusal_always_carries_escalate_and_order_is_fixed() -> None:
    actions = propose_actions(
        "I broke a vase, remind me to call the client",
        locale="en",
        permissions=EMPLOYEE,
        refused=True,
        refusal_reason="low_confidence",
    )
    assert [a["type"] for a in actions] == ["create_incident", "create_task", "escalate"]
    assert actions[-1]["payload"] == {"reason": "low_confidence"}
    no_permissions = propose_actions("I broke a vase", locale="en", permissions=(), refused=True)
    assert [a["type"] for a in no_permissions] == ["escalate"]


def test_open_source_for_the_top_citation() -> None:
    actions = propose_actions(
        "How long do I wait?",
        locale="ru",
        permissions=EMPLOYEE,
        refused=False,
        citations=[{"document_id": 4, "chunk_id": 9, "page": None}, {"document_id": 5}],
    )
    assert actions == [
        {"type": "open_source", "label": "Открыть источник", "payload": {"document_id": 4, "chunk_id": 9}}
    ]


@pytest.mark.parametrize(
    "raw",
    [
        {"type": "delete_everything", "label": "Do it", "payload": {}},
        {"type": "create_task", "label": "Task", "payload": {"title": "x"}},
        {"type": "create_task", "label": "Task", "payload": {"title": "Valid", "assignee_id": 1}},
        {
            "type": "create_incident",
            "label": "Inc",
            "payload": {"category": "fire", "severity": "low", "description": "x"},
        },
        {"type": "escalate", "label": "Esc", "payload": {"reason": "because"}},
        {"type": "open_source", "label": "Open", "payload": {"document_id": 0}},
        {"type": "ask", "label": "Ask", "payload": {"question": "x" * 201}},
        "not a dict",
    ],
)
def test_invalid_actions_are_dropped(raw: Any) -> None:
    assert validate_action(raw) is None
    assert finalize_actions([raw], frozenset({"*"}), refused=False, locale="en") == []


def test_at_most_four_actions() -> None:
    candidates = [
        {
            "type": "create_incident",
            "label": "a",
            "payload": {"category": "other", "severity": "low", "description": "d"},
        },
        {"type": "create_task", "label": "b", "payload": {"title": "Task title"}},
        {"type": "escalate", "label": "c", "payload": {"reason": "not_found"}},
        {"type": "open_source", "label": "d", "payload": {"document_id": 1}},
        {"type": "ask", "label": "e", "payload": {"question": "again?"}},
    ]
    actions = finalize_actions(list(reversed(candidates)), frozenset({"*"}), refused=True, locale="en")
    assert len(actions) == MAX_ACTIONS
    assert [a["type"] for a in actions] == ["create_incident", "create_task", "escalate", "open_source"]


@pytest.mark.parametrize(
    ("count", "expected"),
    [(1, "1 задача"), (2, "2 задачи"), (5, "5 задач"), (11, "11 задач"), (21, "21 задача"), (22, "22 задачи")],
)
def test_russian_plural_forms(count: int, expected: str) -> None:
    assert count_phrase(count, "ru", ("задача", "задачи", "задач"), ("task", "tasks")) == expected
    assert ru_plural(count, "one", "few", "many") in ("one", "few", "many")


@pytest.mark.parametrize(("count", "expected"), [(1, "1 task"), (2, "2 tasks"), (21, "21 tasks")])
def test_english_plural_forms(count: int, expected: str) -> None:
    assert count_phrase(count, "en", ("", "", ""), ("task", "tasks")) == expected


def test_templates_name_their_source_and_escape_markdown() -> None:
    who = render_tool_answer(
        "who_handles", {"category": "damage", "user": {"name": "Ann *Smith*"}, "source": "rule"}, "en"
    )
    assert who == "From the routing rules: **Ann \\*Smith\\*** handles damage."
    owner = render_tool_answer(
        "who_handles",
        {"category": "injury", "user": {"name": "Марина"}, "department": None, "source": "workspace_owner"},
        "ru",
    )
    assert owner.startswith("По правилам маршрутизации: тему «травмы» ведёт **Марина**")
    nobody = render_tool_answer("who_handles", {"category": "question", "user": None}, "en")
    assert "nobody is assigned to questions yet" in nobody
    tasks = render_tool_answer(
        "my_tasks",
        {
            "status": "open",
            "tasks": [{"display_number": "T-3", "title": "Mop", "due_local": "Oct 22, 2:30 PM", "overdue": True}],
        },
        "en",
    )
    assert tasks == "From your task list: you have 1 open task.\n\n- **T-3** Mop — due Oct 22, 2:30 PM (overdue)"
    incident = render_tool_answer(
        "incident_status",
        {
            "found": True,
            "number": 12,
            "display_number": "№ 12",
            "category": "damage",
            "status": "in_progress",
            "assignee_name": "Олег Петров",
            "updated_local": "22 окт., 14:30",
        },
        "ru",
    )
    assert incident == (
        "По журналу инцидентов: инцидент **№ 12** (Повреждение) — статус «в работе». "
        "Ответственный: Олег Петров. Последнее обновление: 22 окт., 14:30."
    )


def test_pii_redaction() -> None:
    text = (
        "Call +7 (916) 123-45-67 or mail ivan.petrov@example.ru, card 4111 1111 1111 1111, IBAN DE89370400440532013000"
    )
    redacted = redact_text(text)
    assert "916" not in redacted and "example.ru" not in redacted and "4111 1111" not in redacted
    assert "DE8937" not in redacted
    assert "[phone ****4567]" in redacted and "[email]" in redacted and "[card ****1111]" in redacted
    assert "[IBAN ****3000]" in redacted
    assert redact_text("Wait 15 minutes, call twice") == "Wait 15 minutes, call twice"
    assert redact_text("Incident № 12 and task T-34") == "Incident № 12 and task T-34"

from __future__ import annotations

import re
from collections.abc import Iterable
from dataclasses import dataclass, field
from typing import Any

from app.providers.mock import IntentRule
from app.text import fold

_FLAGS = re.IGNORECASE | re.UNICODE

CATEGORY_STEMS: tuple[tuple[str, tuple[str, ...]], ...] = (
    ("injury", ("травм", "порезал", "ожог", "отравил", "injur", "cut myself", "burn", "poison")),
    ("damage", ("поцарап", "слома", "разбил", "поврежд", "испорт", "scratch", "broke", "damag", "crack", "stain")),
    ("complaint", ("жалоб", "недоволен", "претенз", "complain", "unhappy", "dissatisfied")),
    ("no_access", ("не открыва", "нет доступа", "не пускают", "nobody opens", "no access", "locked out")),
    ("lost_item", ("пропал", "потерял", "украл", "missing", "lost", "stolen")),
)

_TASK_STEMS = ("создаи задачу", "поставь задачу", "напомни", "create a task", "remind me", "add a task")
_QUESTION_STEMS = ("вопрос", "question")

_WHO_HANDLES = (
    re.compile(r"\bкому\s+(?:передать|сообщить|отдать|написать|звонить|позвонить|обращаться|жаловаться)", _FLAGS),
    re.compile(r"\bк\s+кому\s+(?:обратиться|обращаться|идти|пойти)", _FLAGS),
    re.compile(r"\bкто\s+(?:отвечает|решает|занимается)\s+(?:за\s+|за\b)?", _FLAGS),
    re.compile(r"\bwho\s+(?:handles|is\s+handling|deals\s+with|is\s+responsible)", _FLAGS),
    re.compile(r"\bwho\s+(?:should|do|can)\s+i\s+(?:contact|tell|ask|call|report|talk|go|pass|send|escalate)", _FLAGS),
)
_MY_TASKS = (
    re.compile(r"\bмои\s+(?:открытые\s+|просроченные\s+|текущие\s+)?задачи\b", _FLAGS),
    re.compile(r"\b(?:какие|покажи|список)\s+(?:у\s+меня\s+|мои\s+)?(?:открытые\s+|просроченные\s+)?задач", _FLAGS),
    re.compile(r"\bmy\s+(?:open\s+|overdue\s+|current\s+)?tasks\b", _FLAGS),
    re.compile(r"\bwhat\s+(?:are|is)\s+(?:on\s+)?my\s+(?:open\s+|overdue\s+)?(?:tasks|task\s+list|to-?do)", _FLAGS),
)
_INCIDENT_NUMBER = (
    re.compile(r"\bинцидент\w*\s*(?:no\.?|#|номер|n)?\s*(?P<number>\d{1,9})\b", _FLAGS),
    re.compile(r"\bincident\s*(?:no\.?|#|number|n)?\s*(?P<number>\d{1,9})\b", _FLAGS),
)
_OVERDUE = re.compile(r"просроч|overdue|late", _FLAGS)
_SEARCH = (
    re.compile(r"^(?:please\s+)?(?:find|search(?:\s+for)?|look\s+up)\s+(?P<q>.{3,})$", _FLAGS),
    re.compile(r"^(?:пожалуйста\s+)?(?:наиди|наити|поищи|ищи|поиск)\s+(?P<q>.{3,})$", _FLAGS),
)


@dataclass(frozen=True, slots=True)
class IntentMatch:
    tool: str
    args: dict[str, Any] = field(default_factory=dict)


def infer_category(message: str, default: str = "task") -> str:
    text = fold(message)
    for category, stems in CATEGORY_STEMS:
        if any(fold(stem) in text for stem in stems):
            return category
    if any(fold(stem) in text for stem in _QUESTION_STEMS):
        return "question"
    return default


def incident_category(message: str) -> str | None:
    text = fold(message)
    for category, stems in CATEGORY_STEMS:
        if any(fold(stem) in text for stem in stems):
            return category
    return None


def wants_task(message: str) -> bool:
    text = fold(message)
    return any(fold(stem) in text for stem in _TASK_STEMS)


def _incident_args(_question: str, match: re.Match[str]) -> dict[str, Any]:
    return {"number": int(match.group("number"))}


def _tasks_args(question: str, _match: re.Match[str]) -> dict[str, Any]:
    return {"status": "overdue" if _OVERDUE.search(fold(question)) else "open"}


def _who_args(question: str, _match: re.Match[str]) -> dict[str, Any]:
    return {"category": infer_category(question)}


class _FoldedRule(IntentRule):
    def match(self, question: str) -> dict[str, Any] | None:
        folded = fold(question)
        for pattern in self.patterns:
            found = pattern.search(folded)
            if found:
                return self.build_args(question, found)
        return None


DATA_INTENT_RULES: tuple[IntentRule, ...] = (
    _FoldedRule(tool="incident_status", patterns=_INCIDENT_NUMBER, build_args=_incident_args),
    _FoldedRule(tool="my_tasks", patterns=_MY_TASKS, build_args=_tasks_args),
    _FoldedRule(tool="who_handles", patterns=_WHO_HANDLES, build_args=_who_args),
)

INTENT_RULES: tuple[IntentRule, ...] = (
    *DATA_INTENT_RULES,
    _FoldedRule(
        tool="search_documents",
        patterns=_SEARCH,
        build_args=lambda _question, match: {"query": match.group("q").strip(" ?.!")},
    ),
)


def match_data_intent(message: str, available: Iterable[str]) -> IntentMatch | None:
    offered = set(available)
    text = message.strip()
    for rule in DATA_INTENT_RULES:
        if rule.tool not in offered:
            continue
        args = rule.match(text)
        if args is not None:
            return IntentMatch(tool=rule.tool, args=args)
    return None

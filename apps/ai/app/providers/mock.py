from __future__ import annotations

import json
import re
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from typing import Any

from app.agent.sources import NOT_FOUND_MARKER, ParsedSource, parse_sources
from app.providers.catalog import MOCK_MODEL
from app.providers.types import ChatMessage, ChatRequest, ChatResult, ChatUsage, ToolCall
from app.text import content_terms, coverage, estimate_tokens, split_sentences

ArgsBuilder = Callable[[str, re.Match[str]], dict[str, Any]]

MAX_SENTENCES = 3
MIN_SENTENCE_COVERAGE = 0.25

_INTRO = {
    "en": "According to the approved documents:",
    "ru": "Согласно утверждённым документам:",
}
_DATA_INTRO = {"en": "Data from connected systems:", "ru": "Данные из подключённых систем:"}


@dataclass(frozen=True, slots=True)
class IntentRule:
    tool: str
    patterns: tuple[re.Pattern[str], ...]
    build_args: ArgsBuilder

    def match(self, question: str) -> dict[str, Any] | None:
        for pattern in self.patterns:
            found = pattern.search(question)
            if found:
                return self.build_args(question, found)
        return None


@dataclass(frozen=True, slots=True)
class _Candidate:
    score: float
    order: tuple[int, int]
    ref: int
    sentence: str


class MockProvider:
    def __init__(self, intent_rules: Sequence[IntentRule] = ()) -> None:
        self._rules = tuple(intent_rules)

    @property
    def id(self) -> str:
        return "mock"

    def chat(self, req: ChatRequest) -> ChatResult:
        prompt_tokens = sum(estimate_tokens(m.content) for m in req.messages)
        if req.metadata.get("ping"):
            return self._result("OK", [], prompt_tokens)

        turn = _current_turn(req.messages)
        question = str(req.metadata.get("question") or (turn[0].content if turn else ""))
        locale = "ru" if str(req.metadata.get("locale", "en")).startswith("ru") else "en"
        tool_messages = [m for m in turn if m.role == "tool"]

        if not tool_messages:
            call = self._route(question, {t.name for t in req.tools})
            if call is not None:
                return ChatResult(
                    content=None,
                    tool_calls=[call],
                    usage=ChatUsage(prompt_tokens=prompt_tokens, completion_tokens=estimate_tokens(call.arguments)),
                    finish_reason="tool_calls",
                    served_model=MOCK_MODEL,
                )

        sources: list[ParsedSource] = []
        data_notes: list[str] = []
        for message in turn:
            if message.role == "user":
                sources.extend(parse_sources(message.content))
            elif message.role == "tool":
                parsed = parse_sources(message.content)
                if parsed:
                    sources.extend(parsed)
                else:
                    note = _data_summary(message.content)
                    if note:
                        data_notes.append(note)
        return self._result(_compose(question, sources, data_notes, locale), [], prompt_tokens)

    def _route(self, question: str, offered: set[str]) -> ToolCall | None:
        for rule in self._rules:
            if rule.tool not in offered:
                continue
            args = rule.match(question.strip())
            if args is not None:
                return ToolCall(
                    id=f"mock_{rule.tool}_1", name=rule.tool, arguments=json.dumps(args, ensure_ascii=False)
                )
        return None

    @staticmethod
    def _result(text: str, calls: list[ToolCall], prompt_tokens: int) -> ChatResult:
        return ChatResult(
            content=text,
            tool_calls=calls,
            usage=ChatUsage(prompt_tokens=prompt_tokens, completion_tokens=estimate_tokens(text)),
            finish_reason="stop",
            served_model=MOCK_MODEL,
        )


def _current_turn(messages: list[ChatMessage]) -> list[ChatMessage]:
    for index in range(len(messages) - 1, -1, -1):
        if messages[index].role == "user":
            return messages[index:]
    return []


def _data_summary(raw: str) -> str | None:
    try:
        payload = json.loads(raw)
    except (TypeError, ValueError):
        return None
    if isinstance(payload, dict) and payload.get("summary"):
        return str(payload["summary"]).strip()
    return None


def _compose(question: str, sources: list[ParsedSource], data_notes: list[str], locale: str) -> str:
    terms = content_terms(question)
    candidates: list[_Candidate] = []
    seen: set[str] = set()
    for source_index, source in enumerate(sources):
        for sentence_index, sentence in enumerate(split_sentences(source.content)):
            key = sentence.lower()
            if key in seen or len(sentence) < 3:
                continue
            seen.add(key)
            score = coverage(terms, f"{source.title} {sentence}") if terms else 0.0
            if score >= MIN_SENTENCE_COVERAGE:
                candidates.append(_Candidate(score, (source_index, sentence_index), source.ref, sentence))
    candidates.sort(key=lambda c: (-c.score, c.order))
    picked = sorted(candidates[:MAX_SENTENCES], key=lambda c: c.order)

    parts: list[str] = []
    if data_notes:
        parts.append(_DATA_INTRO[locale] + "\n\n" + "\n".join(f"- {note}" for note in data_notes))
    if picked:
        lines = [f"{c.sentence.rstrip()} [{c.ref}]" for c in picked]
        body = lines[0] if len(lines) == 1 else "\n".join(f"- {line}" for line in lines)
        parts.append(f"{_INTRO[locale]}\n\n{body}")
    return "\n\n".join(parts) if parts else NOT_FOUND_MARKER

from __future__ import annotations

from datetime import date

from app.retrieval.conflicts import Conflict

STRINGS: dict[str, dict[str, str]] = {
    "refusal": {
        "en": "I couldn't find this in your company's approved documents. I can pass the question to a manager.",
        "ru": "Я не нашёл ответа в утверждённых документах компании. Могу передать вопрос менеджеру.",
    },
    "unavailable": {
        "en": "Answers are temporarily unavailable. I can pass the question to a manager.",
        "ru": "Ответы временно недоступны. Могу передать вопрос менеджеру.",
    },
    "model_refusal": {
        "en": "I can't help with this request. I can pass the question to a manager.",
        "ru": "Я не могу помочь с этим запросом. Могу передать вопрос менеджеру.",
    },
    "conflict_title": {"en": "Note: the documents differ.", "ru": "Обратите внимание: документы расходятся."},
    "conflict_line": {
        "en": "«{winner}»{winner_meta} supersedes «{loser}»{loser_meta}; the answer follows the newer one.",
        "ru": "«{winner}»{winner_meta} заменяет «{loser}»{loser_meta}; ответ дан по более новому документу.",
    },
    "effective": {"en": "effective {date}", "ru": "действует с {date}"},
    "version": {"en": "version {version}", "ru": "версия {version}"},
}


def t(key: str, locale: str, **params: str) -> str:
    table = STRINGS[key]
    template = table.get(locale) or table["en"]
    return template.format(**params) if params else template


def _format_date(value: date, locale: str) -> str:
    return value.strftime("%d.%m.%Y") if locale == "ru" else value.isoformat()


def _meta(version: str | None, effective: date | None, locale: str) -> str:
    parts = []
    if effective:
        parts.append(t("effective", locale, date=_format_date(effective, locale)))
    if version:
        parts.append(t("version", locale, version=version))
    return f" ({', '.join(parts)})" if parts else ""


def conflict_note(conflicts: list[Conflict], locale: str) -> str:
    seen: set[tuple[int, int]] = set()
    lines: list[str] = []
    for c in conflicts:
        key = (c.winner_document_id, c.superseded_document_id)
        if key in seen:
            continue
        seen.add(key)
        lines.append(
            "- "
            + t(
                "conflict_line",
                locale,
                winner=c.winner_title,
                winner_meta=_meta(c.winner_version, c.winner_effective_date, locale),
                loser=c.superseded_title,
                loser_meta=_meta(c.superseded_version, c.superseded_effective_date, locale),
            )
        )
    return f"> **{t('conflict_title', locale)}**\n>\n" + "\n".join(f"> {line}" for line in lines)

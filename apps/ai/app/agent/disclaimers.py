from __future__ import annotations

from collections.abc import Iterable, Sequence
from typing import Any

COMPENSATION = "compensation"

TEXTS: dict[str, dict[str, str]] = {
    COMPENSATION: {
        "en": "The final amount is confirmed by your manager.",
        "ru": "Итоговую сумму подтверждает менеджер.",
    },
}


def disclaimers_for(citations: Sequence[dict[str, Any]]) -> list[str]:
    tags: set[str] = set()
    for citation in citations:
        tags.update(str(tag) for tag in citation.get("tags") or [])
    return [COMPENSATION] if COMPENSATION in tags else []


def apply_disclaimers(answer: str, disclaimers: Iterable[str], locale: str) -> str:
    loc = "ru" if locale == "ru" else "en"
    lines = [TEXTS[key][loc] for key in disclaimers if key in TEXTS]
    if not lines:
        return answer
    return answer.rstrip() + "\n\n" + "\n".join(lines)

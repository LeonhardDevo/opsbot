from __future__ import annotations

import re
from datetime import date
from functools import lru_cache
from pathlib import Path
from string import Template

PROMPTS_DIR = Path(__file__).resolve().parent.parent / "prompts"
SUPPORTED_LOCALES = ("en", "ru")
_HTML_COMMENT = re.compile(r"<!--.*?-->", re.DOTALL)


def normalize_locale(locale: str | None) -> str:
    value = (locale or "en").strip().lower().replace("_", "-")
    return "ru" if value.startswith("ru") else "en"


@lru_cache(maxsize=8)
def _read(name: str) -> str:
    return _HTML_COMMENT.sub("", (PROMPTS_DIR / name).read_text(encoding="utf-8")).strip()


def system_prompt(locale: str, product_name: str, today: date | None = None) -> str:
    loc = normalize_locale(locale)
    template = Template(_read(f"system.{loc}.md"))
    return template.safe_substitute(
        product_name=product_name,
        domain=_read(f"domain.{loc}.md"),
        today=(today or date.today()).isoformat(),
    )

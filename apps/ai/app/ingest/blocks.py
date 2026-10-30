from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Literal

BlockKind = Literal["heading", "paragraph", "list", "table", "code"]


@dataclass(slots=True)
class Block:
    text: str
    kind: BlockKind = "paragraph"
    level: int = 0
    page: int | None = None


@dataclass(slots=True)
class ParsedDocument:
    blocks: list[Block]
    pages: int | None = None
    ocr_used: bool = False
    warnings: list[str] = field(default_factory=list)

    @property
    def text_length(self) -> int:
        return sum(len(b.text) for b in self.blocks)


_NUMBERED_HEADING = re.compile(r"^(?P<num>\d{1,2}(?:\.\d{1,2}){0,4})[.)]?\s+(?P<title>\S.{0,110})$")
_KEYWORD_HEADING = re.compile(
    r"^(?:глава|раздел|часть|статья|приложение|chapter|section|part|article|appendix)\s+[\w.\-IVXLC]+\b.{0,100}$",
    re.IGNORECASE,
)
_BULLET = re.compile(r"^\s*(?:[•·▪◦‣\-–—*]|\(?[a-zа-я]\)|\d{1,3}[.)])\s+", re.IGNORECASE)
_SENTENCE_END = re.compile(r"[.!?…:;]$")


def heading_level(line: str) -> int:
    stripped = line.strip()
    if not stripped or len(stripped) > 120:
        return 0
    numbered = _NUMBERED_HEADING.match(stripped)
    if numbered and not stripped.endswith((".", ";", ",")) and len(numbered.group("title").split()) <= 14:
        title = numbered.group("title")
        if title[:1].isupper():
            return min(6, numbered.group("num").count(".") + 1)
    if _KEYWORD_HEADING.match(stripped) and not stripped.endswith((".", ";", ",")):
        return 1
    letters = [ch for ch in stripped if ch.isalpha()]
    if (
        len(letters) >= 4
        and sum(ch.isupper() for ch in letters) / len(letters) > 0.85
        and len(stripped.split()) <= 10
        and not stripped.endswith((".", ";", ","))
    ):
        return 1
    return 0


def lines_to_blocks(text: str, page: int | None = None) -> list[Block]:
    blocks: list[Block] = []
    buffer: list[str] = []
    buffer_kind: BlockKind = "paragraph"

    def flush() -> None:
        nonlocal buffer, buffer_kind
        if buffer:
            joined = " ".join(buffer).strip()
            if joined:
                blocks.append(Block(joined, buffer_kind, page=page))
        buffer, buffer_kind = [], "paragraph"

    for raw_line in text.replace("\r\n", "\n").replace("\r", "\n").split("\n"):
        line = " ".join(raw_line.split())
        if not line:
            flush()
            continue
        level = heading_level(line)
        if level:
            flush()
            blocks.append(Block(line, "heading", level=level, page=page))
            continue
        if _BULLET.match(line):
            flush()
            buffer, buffer_kind = [line], "list"
            continue
        if buffer and buffer[-1].endswith("-") and line[:1].islower():
            buffer[-1] = buffer[-1][:-1] + line
            continue
        if buffer and _SENTENCE_END.search(buffer[-1]) and (line[:1].isupper() or line[:1].isdigit()):
            flush()
        buffer.append(line)
    flush()
    return blocks

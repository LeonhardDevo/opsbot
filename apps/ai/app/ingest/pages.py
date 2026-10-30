from __future__ import annotations

import re
from dataclasses import dataclass

from app.ingest.blocks import Block, lines_to_blocks

_MD_HEADING = re.compile(r"^(#{1,6})\s+(.+?)\s*#*\s*$")
_TABLE_ROW = re.compile(r"^\s*\|.*\|\s*$")
_TABLE_RULE = re.compile(r"^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$")
_LIST_ITEM = re.compile(r"^\s*(?:[-*+•]|\d{1,3}[.)])\s+")


@dataclass(frozen=True, slots=True)
class PageText:
    page: int
    text: str


def blocks_to_pages(blocks: list[Block]) -> list[PageText]:
    grouped: dict[int, list[str]] = {}
    for block in blocks:
        page = block.page or 1
        if block.kind == "heading":
            line = f"{'#' * max(1, min(6, block.level or 1))} {block.text.strip()}"
        elif block.kind == "table":
            line = "\n".join(f"| {row} |" for row in block.text.splitlines() if row.strip())
        elif block.kind == "list" and not _LIST_ITEM.match(block.text):
            line = f"- {block.text.strip()}"
        else:
            line = block.text.strip()
        if line:
            grouped.setdefault(page, []).append(line)
    return [PageText(page=page, text="\n\n".join(lines)) for page, lines in sorted(grouped.items())]


def _is_markdown(text: str) -> bool:
    return any(_MD_HEADING.match(line) or _TABLE_ROW.match(line) for line in text.splitlines())


def _markdown_blocks(text: str, page: int) -> list[Block]:
    blocks: list[Block] = []
    paragraph: list[str] = []
    table: list[str] = []
    in_list = False

    def flush_paragraph() -> None:
        if paragraph:
            blocks.extend(lines_to_blocks("\n".join(paragraph), page=page))
            paragraph.clear()

    def flush_table() -> None:
        if table:
            blocks.append(Block("\n".join(table), "table", page=page))
            table.clear()

    for raw in text.replace("\r\n", "\n").split("\n"):
        if in_list and raw.strip() and raw[:1].isspace() and not _LIST_ITEM.match(raw):
            blocks[-1].text = f"{blocks[-1].text} {raw.strip()}"
            continue
        in_list = False
        heading = _MD_HEADING.match(raw)
        if heading:
            flush_paragraph()
            flush_table()
            blocks.append(Block(heading.group(2).strip(), "heading", level=len(heading.group(1)), page=page))
            continue
        if _TABLE_ROW.match(raw):
            flush_paragraph()
            if _TABLE_RULE.match(raw):
                continue
            cells = [cell.strip() for cell in raw.strip().strip("|").split("|")]
            row = " | ".join(cell for cell in cells if cell)
            if row:
                table.append(row)
            continue
        flush_table()
        if not raw.strip():
            flush_paragraph()
            continue
        if _LIST_ITEM.match(raw):
            flush_paragraph()
            blocks.append(Block(_LIST_ITEM.sub("", raw).strip(), "list", page=page))
            in_list = True
            continue
        paragraph.append(raw)
    flush_paragraph()
    flush_table()
    return blocks


def pages_to_blocks(pages: list[PageText], *, paged: bool) -> list[Block]:
    blocks: list[Block] = []
    for page in sorted(pages, key=lambda p: p.page):
        number = page.page if paged else None
        text = page.text or ""
        if not text.strip():
            continue
        if _is_markdown(text):
            for block in _markdown_blocks(text, page.page):
                block.page = number
                blocks.append(block)
        else:
            blocks.extend(lines_to_blocks(text, page=number))
    return [b for b in blocks if b.text.strip()]

from __future__ import annotations

from dataclasses import dataclass, field

from app.ingest.blocks import Block
from app.text import estimate_tokens, split_sentences

SECTION_SEPARATOR = " > "


@dataclass(frozen=True, slots=True)
class ChunkDraft:
    ordinal: int
    content: str
    page: int | None
    section_path: str | None
    token_count: int


@dataclass(slots=True)
class _Unit:
    text: str
    tokens: int


@dataclass(slots=True)
class _Buffer:
    units: list[_Unit] = field(default_factory=list)
    overlap_units: int = 0
    page: int | None = None
    section: str | None = None

    @property
    def tokens(self) -> int:
        return sum(u.tokens for u in self.units)

    @property
    def fresh_tokens(self) -> int:
        return sum(u.tokens for u in self.units[self.overlap_units :])

    @property
    def has_fresh_content(self) -> bool:
        return len(self.units) > self.overlap_units


def common_section(a: str | None, b: str | None) -> str | None:
    if a is None or b is None:
        return None
    left, right = a.split(SECTION_SEPARATOR), b.split(SECTION_SEPARATOR)
    prefix = []
    for x, y in zip(left, right, strict=False):
        if x != y:
            break
        prefix.append(x)
    return SECTION_SEPARATOR.join(prefix) or None


def _leaf(section: str | None) -> str | None:
    return section.split(SECTION_SEPARATOR)[-1] if section else None


class Chunker:
    def __init__(self, target_tokens: int = 700, overlap_ratio: float = 0.15, min_tokens: int | None = None) -> None:
        if target_tokens < 50:
            raise ValueError("target_tokens must be >= 50")
        self.target = target_tokens
        self.overlap = int(target_tokens * overlap_ratio)
        self.min_tokens = min_tokens if min_tokens is not None else max(40, target_tokens // 4)

    def chunk(self, blocks: list[Block]) -> list[ChunkDraft]:
        drafts: list[ChunkDraft] = []
        stack: list[tuple[int, str]] = []
        buf = _Buffer()

        def section() -> str | None:
            return SECTION_SEPARATOR.join(text for _, text in stack) or None

        def can_merge_back() -> bool:
            if not drafts or buf.fresh_tokens >= self.min_tokens:
                return False
            prev = drafts[-1]
            same_page = prev.page is None or buf.page is None or prev.page == buf.page
            return same_page and prev.token_count + buf.fresh_tokens <= self.target

        def emit(carry_overlap: bool) -> None:
            nonlocal buf
            if not buf.has_fresh_content:
                buf = _Buffer(section=section())
                return
            content = "\n".join(u.text for u in buf.units).strip()
            if not carry_overlap and can_merge_back():
                prev = drafts[-1]
                fresh = "\n".join(u.text for u in buf.units[buf.overlap_units :]).strip()
                heading = _leaf(buf.section) if buf.section != prev.section_path else None
                merged = "\n".join(part for part in (prev.content, heading, fresh) if part)
                drafts[-1] = ChunkDraft(
                    ordinal=prev.ordinal,
                    content=merged,
                    page=prev.page,
                    section_path=common_section(prev.section_path, buf.section),
                    token_count=estimate_tokens(merged),
                )
            else:
                drafts.append(
                    ChunkDraft(
                        ordinal=len(drafts),
                        content=content,
                        page=buf.page,
                        section_path=buf.section,
                        token_count=estimate_tokens(content),
                    )
                )
            tail = self._overlap_tail(buf.units) if carry_overlap else []
            buf = _Buffer(units=tail, overlap_units=len(tail), page=buf.page, section=buf.section)

        for block in blocks:
            if block.kind == "heading":
                heading = block.text.strip()
                level = max(1, block.level or 1)
                absorb = buf.has_fresh_content and buf.fresh_tokens < self.min_tokens and not can_merge_back()
                if buf.has_fresh_content and not absorb:
                    emit(carry_overlap=False)
                while stack and stack[-1][0] >= level:
                    stack.pop()
                stack.append((level, heading))
                if absorb:
                    buf.units.append(_Unit(heading, estimate_tokens(heading)))
                    buf.section = common_section(buf.section, section())
                else:
                    buf = _Buffer(section=section(), page=block.page)
                continue

            page_changed = block.page is not None and buf.page is not None and block.page != buf.page
            if page_changed and buf.has_fresh_content and buf.fresh_tokens >= self.min_tokens:
                emit(carry_overlap=False)
            if not buf.has_fresh_content:
                current = section()
                if buf.section != current:
                    buf.units, buf.overlap_units = [], 0
                buf.section = current
                buf.page = block.page

            for unit in self._units(block):
                if buf.has_fresh_content and buf.tokens + unit.tokens > self.target:
                    emit(carry_overlap=True)
                    buf.page = block.page
                buf.units.append(unit)

        emit(carry_overlap=False)
        return drafts

    def _units(self, block: Block) -> list[_Unit]:
        text = block.text.strip()
        tokens = estimate_tokens(text)
        if tokens <= self.target:
            return [_Unit(text, tokens)]
        pieces = text.split("\n") if block.kind in ("table", "code") else split_sentences(text)
        units: list[_Unit] = []
        for piece in pieces:
            piece_tokens = estimate_tokens(piece)
            if piece_tokens <= self.target:
                units.append(_Unit(piece, piece_tokens))
                continue
            words = piece.split()
            step = max(1, int(len(words) * self.target / piece_tokens))
            for start in range(0, len(words), step):
                part = " ".join(words[start : start + step])
                units.append(_Unit(part, estimate_tokens(part)))
        return units

    def _overlap_tail(self, units: list[_Unit]) -> list[_Unit]:
        if self.overlap <= 0:
            return []
        tail: list[_Unit] = []
        total = 0
        for unit in reversed(units):
            for sentence in reversed(split_sentences(unit.text) or [unit.text]):
                tokens = estimate_tokens(sentence)
                if total + tokens > self.overlap:
                    return list(reversed(tail))
                tail.append(_Unit(sentence, tokens))
                total += tokens
        return list(reversed(tail))

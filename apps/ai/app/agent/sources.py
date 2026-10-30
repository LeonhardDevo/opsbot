from __future__ import annotations

import html
import re
from dataclasses import dataclass, field
from datetime import date

NOT_FOUND_MARKER = "[[NOT_FOUND]]"

_SOURCE_RE = re.compile(r'<source id="(?P<id>\d+)"(?P<attrs>[^>]*)>\n?(?P<body>.*?)\n?</source>', re.DOTALL)
_ATTR_RE = re.compile(r'(\w+)="([^"]*)"')


@dataclass(slots=True)
class Source:
    ref: int
    document_id: int
    title: str
    content: str
    chunk_id: int | None = None
    page: int | None = None
    section: str | None = None
    version: str | None = None
    effective_date: date | None = None
    tags: list[str] = field(default_factory=list)
    score: float = 0.0
    confidence: float = 0.0
    origin: str = "retrieval"


@dataclass(slots=True)
class SourcePool:
    sources: list[Source] = field(default_factory=list)
    _by_chunk: dict[int, Source] = field(default_factory=dict)

    def add(self, source: Source) -> Source:
        if source.chunk_id is not None and source.chunk_id in self._by_chunk:
            existing = self._by_chunk[source.chunk_id]
            existing.confidence = max(existing.confidence, source.confidence)
            return existing
        source.ref = len(self.sources) + 1
        self.sources.append(source)
        if source.chunk_id is not None:
            self._by_chunk[source.chunk_id] = source
        return source

    def get(self, ref: int) -> Source | None:
        return self.sources[ref - 1] if 1 <= ref <= len(self.sources) else None

    @property
    def max_confidence(self) -> float:
        return max((s.confidence for s in self.sources), default=0.0)


def _escape(value: str) -> str:
    return html.escape(value, quote=True)


def render_source(source: Source) -> str:
    attrs = [f'id="{source.ref}"', f'title="{_escape(source.title)}"']
    if source.page is not None:
        attrs.append(f'page="{source.page}"')
    if source.section:
        attrs.append(f'section="{_escape(source.section)}"')
    if source.version:
        attrs.append(f'version="{_escape(source.version)}"')
    if source.effective_date:
        attrs.append(f'effective_date="{source.effective_date.isoformat()}"')
    return f"<source {' '.join(attrs)}>\n{_escape(source.content)}\n</source>"


def render_documents(sources: list[Source]) -> str:
    if not sources:
        return "<documents>\n</documents>"
    return "<documents>\n" + "\n".join(render_source(s) for s in sources) + "\n</documents>"


@dataclass(frozen=True, slots=True)
class ParsedSource:
    ref: int
    title: str
    content: str
    attrs: dict[str, str]


def parse_sources(text: str) -> list[ParsedSource]:
    parsed: list[ParsedSource] = []
    for match in _SOURCE_RE.finditer(text):
        attrs = {k: html.unescape(v) for k, v in _ATTR_RE.findall(match.group("attrs"))}
        parsed.append(
            ParsedSource(
                ref=int(match.group("id")),
                title=attrs.get("title", ""),
                content=html.unescape(match.group("body")),
                attrs=attrs,
            )
        )
    return parsed

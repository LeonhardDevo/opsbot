from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any

from markdown_it import MarkdownIt

from app.agent.sources import Source, SourcePool
from app.text import content_terms, coverage, split_sentences, truncate

_CITATION = re.compile(r"\[(\d{1,3}(?:\s*[,;]\s*\d{1,3})*)\](?!\()")
_MD = MarkdownIt("commonmark", {"html": False, "linkify": False, "typographer": False}).enable("table")
SNIPPET_CHARS = 240


@dataclass(frozen=True, slots=True)
class CitedSource:
    number: int
    source: Source


def renumber_citations(answer: str, pool: SourcePool) -> tuple[str, list[CitedSource]]:
    mapping: dict[int, int] = {}
    cited: list[CitedSource] = []

    def replace(match: re.Match[str]) -> str:
        numbers: list[int] = []
        for raw in re.split(r"\s*[,;]\s*", match.group(1)):
            ref = int(raw)
            source = pool.get(ref)
            if source is None:
                continue
            if ref not in mapping:
                mapping[ref] = len(mapping) + 1
                cited.append(CitedSource(mapping[ref], source))
            if mapping[ref] not in numbers:
                numbers.append(mapping[ref])
        return "".join(f"[{n}]" for n in numbers)

    rewritten = _CITATION.sub(replace, answer)
    rewritten = re.sub(r"[ \t]+([.,;:!?])", r"\1", rewritten)
    return rewritten.strip(), cited


def snippet_for(source: Source, question: str) -> str:
    terms = content_terms(question)
    sentences = split_sentences(source.content) or [source.content]
    best = max(sentences, key=lambda s: coverage(terms, s)) if terms else sentences[0]
    return truncate(best, SNIPPET_CHARS)


def citation_payload(cited: CitedSource, question: str) -> dict[str, Any]:
    source = cited.source
    return {
        "index": cited.number,
        "document_id": source.document_id,
        "chunk_id": source.chunk_id,
        "title": source.title,
        "page": source.page,
        "section": source.section,
        "snippet": snippet_for(source, question),
        "version": source.version,
        "effective_date": source.effective_date.isoformat() if source.effective_date else None,
        "tags": list(source.tags),
    }


def markdown_to_html(markdown: str) -> str:
    return _MD.render(markdown).strip()

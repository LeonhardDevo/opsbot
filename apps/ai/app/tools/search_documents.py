from __future__ import annotations

from typing import Any, ClassVar

from app.agent.sources import Source, render_documents
from app.retrieval.search import RetrievedChunk
from app.tools.base import Tool, ToolContext, ToolError, ToolResult

MAX_K = 8


def source_from_hit(hit: RetrievedChunk, origin: str) -> Source:
    return Source(
        ref=0,
        document_id=hit.document_id,
        title=hit.title,
        content=hit.content,
        chunk_id=hit.chunk_id,
        page=hit.page,
        section=hit.section_path,
        version=hit.version,
        effective_date=hit.effective_date,
        tags=list(hit.tags),
        score=hit.score,
        confidence=hit.confidence,
        origin=origin,
    )


class SearchDocumentsTool(Tool):
    name = "search_documents"
    description = (
        "Search the organisation's approved knowledge-base documents (policies, procedures, guides). "
        "Use it when the provided sources are missing or insufficient, with a focused query in the "
        "language of the documents. Returns numbered sources to cite as [n]."
    )
    parameters: ClassVar[dict[str, Any]] = {
        "type": "object",
        "properties": {
            "query": {"type": "string", "description": "What to look for (keywords or a short question)."},
            "k": {"type": "integer", "minimum": 1, "maximum": MAX_K, "description": "How many passages to return."},
        },
        "required": ["query"],
        "additionalProperties": False,
    }
    required_permission = None
    data_tool = False

    def run(self, ctx: ToolContext, args: dict[str, Any]) -> ToolResult:
        query = str(args.get("query") or "").strip()
        if not query:
            raise ToolError("query is required")
        try:
            k = max(1, min(MAX_K, int(args.get("k") or ctx.top_k)))
        except (TypeError, ValueError):
            k = ctx.top_k
        result = ctx.retriever.search(
            workspace_id=ctx.workspace_id, query=query, embedder=ctx.embedder, viewer=ctx.viewer, k=k
        )
        sources = [ctx.sources.add(source_from_hit(hit, origin=self.name)) for hit in result.hits]
        if not sources:
            return ToolResult(content="No matching approved documents were found.", confidence=0.0)
        return ToolResult(content=render_documents(sources), sources=sources, confidence=result.confidence)

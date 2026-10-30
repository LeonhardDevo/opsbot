from __future__ import annotations

import time
from dataclasses import dataclass, field
from datetime import date
from typing import Any

from sqlalchemy import text
from sqlalchemy.orm import Session, sessionmaker

from app.embeddings.base import Embedder, calibrate
from app.observability import RETRIEVAL_LATENCY
from app.retrieval.conflicts import Conflict, resolve_conflicts
from app.retrieval.filters import VISIBILITY_SQL, SearchFilters, Viewer, visibility_params
from app.retrieval.indexes import HNSW_MAX_DIMS, sql_model_literal, vector_literal
from app.text import content_terms, coverage, query_lexemes

VECTOR_WEIGHT = 0.55
LEXICAL_WEIGHT = 0.45
CONFIDENCE_TOP_N = 3


@dataclass(slots=True)
class RetrievedChunk:
    chunk_id: int
    document_id: int
    title: str
    content: str
    page: int | None
    section_path: str | None
    department_ids: list[int]
    roles: list[str]
    tags: list[str]
    version: str | None
    effective_date: date | None
    score: float
    vector_similarity: float | None
    fts_rank: float | None
    confidence: float = 0.0

    def to_dict(self) -> dict[str, Any]:
        return {
            "chunk_id": self.chunk_id,
            "document_id": self.document_id,
            "title": self.title,
            "content": self.content,
            "page": self.page,
            "section_path": self.section_path,
            "department_ids": self.department_ids,
            "roles": self.roles,
            "tags": self.tags,
            "version": self.version,
            "effective_date": self.effective_date.isoformat() if self.effective_date else None,
            "score": round(self.score, 6),
            "vector_similarity": None if self.vector_similarity is None else round(self.vector_similarity, 4),
            "fts_rank": None if self.fts_rank is None else round(self.fts_rank, 4),
            "confidence": round(self.confidence, 4),
        }


@dataclass(slots=True)
class RetrievalResult:
    hits: list[RetrievedChunk]
    confidence: float
    conflicts: list[Conflict] = field(default_factory=list)
    embedding_model: str = ""
    latency_ms: int = 0


_ACL = VISIBILITY_SQL


def _hybrid_sql(model_id: str, dims: int, with_vector: bool) -> str:
    if with_vector:
        cast = f"vector({int(dims)})" if dims <= HNSW_MAX_DIMS else "vector"
        vec_cte = f"""
        vec AS (
            SELECT id, row_number() OVER (ORDER BY dist, id) AS rnk, 1 - dist AS sim FROM (
                SELECT c.id, (c.embedding::{cast} <=> CAST(:qvec AS {cast})) AS dist
                FROM rag.chunks c
                WHERE c.embedding_model = {sql_model_literal(model_id)} AND {_ACL}
                ORDER BY c.embedding::{cast} <=> CAST(:qvec AS {cast})
                LIMIT :cand
            ) v
        ),"""
    else:
        vec_cte = "vec AS (SELECT NULL::bigint AS id, NULL::bigint AS rnk, NULL::float8 AS sim WHERE false),"
    return f"""
    WITH {vec_cte}
    q AS (
        SELECT to_tsquery('simple', rag.f_unaccent(:q_text)) AS qs,
               to_tsquery('russian', rag.f_unaccent(:q_text)) AS qr,
               to_tsquery('english', rag.f_unaccent(:q_text)) AS qe
    ),
    fts AS (
        SELECT id, row_number() OVER (ORDER BY rank DESC, id) AS rnk, rank FROM (
            SELECT c.id,
                   ts_rank_cd(c.tsv_simple, q.qs, 32) + ts_rank_cd(c.tsv_russian, q.qr, 32)
                   + ts_rank_cd(c.tsv_english, q.qe, 32) AS rank
            FROM rag.chunks c, q
            WHERE :has_text AND {_ACL}
              AND (c.tsv_simple @@ q.qs OR c.tsv_russian @@ q.qr OR c.tsv_english @@ q.qe)
            ORDER BY rank DESC
            LIMIT :cand
        ) f
    ),
    fused AS (
        SELECT id, SUM(1.0 / (:rrf_k + rnk)) AS score
        FROM (SELECT id, rnk FROM vec UNION ALL SELECT id, rnk FROM fts) u
        GROUP BY id
    )
    SELECT c.id, c.document_id, c.title, c.content, c.page, c.section_path, c.department_ids, c.roles, c.tags,
           c.version, c.effective_date, fused.score, vec.sim, fts.rank AS fts_rank
    FROM fused
    JOIN rag.chunks c ON c.id = fused.id
    LEFT JOIN vec ON vec.id = fused.id
    LEFT JOIN fts ON fts.id = fused.id
    ORDER BY fused.score DESC, c.id
    LIMIT :lim
    """


class HybridRetriever:
    def __init__(self, session_factory: sessionmaker[Session], *, candidates: int = 40, rrf_k: int = 60) -> None:
        self._session_factory = session_factory
        self._candidates = candidates
        self._rrf_k = rrf_k

    def search(
        self,
        *,
        workspace_id: int,
        query: str,
        embedder: Embedder,
        viewer: Viewer,
        k: int = 6,
        filters: SearchFilters | None = None,
        resolve: bool = True,
    ) -> RetrievalResult:
        started = time.perf_counter()
        filters = filters or SearchFilters()
        lexemes = query_lexemes(query)
        query_vector = embedder.embed_query(query) if query.strip() else None
        with_vector = query_vector is not None and any(query_vector)
        params: dict[str, Any] = {
            **visibility_params(workspace_id, viewer, filters),
            "cand": max(self._candidates, k * 4),
            "rrf_k": self._rrf_k,
            "q_text": " | ".join(lexemes) if lexemes else "",
            "has_text": bool(lexemes),
            "lim": max(k * 3, k),
        }
        if with_vector and query_vector is not None:
            params["qvec"] = vector_literal(query_vector)
        sql = _hybrid_sql(embedder.model_id, embedder.dims, with_vector)
        with self._session_factory() as session:
            if with_vector:
                session.execute(text("SET LOCAL hnsw.ef_search = 100"))
                session.execute(text("SET LOCAL hnsw.iterative_scan = relaxed_order"))
            rows = session.execute(text(sql), params).mappings().all()
            session.rollback()

        terms = content_terms(query)
        hits: list[RetrievedChunk] = []
        for row in rows:
            similarity = float(row["sim"]) if row["sim"] is not None else None
            hit = RetrievedChunk(
                chunk_id=int(row["id"]),
                document_id=int(row["document_id"]),
                title=row["title"],
                content=row["content"],
                page=row["page"],
                section_path=row["section_path"],
                department_ids=list(row["department_ids"] or []),
                roles=list(row["roles"] or []),
                tags=list(row["tags"] or []),
                version=row["version"],
                effective_date=row["effective_date"],
                score=float(row["score"]),
                vector_similarity=similarity,
                fts_rank=float(row["fts_rank"]) if row["fts_rank"] is not None else None,
            )
            hit.confidence = self.hit_confidence(hit, terms, embedder)
            hits.append(hit)

        conflicts: list[Conflict] = []
        if resolve:
            hits, conflicts = resolve_conflicts(hits)
        hits = hits[:k]
        confidence = max((h.confidence for h in hits[:CONFIDENCE_TOP_N]), default=0.0)
        elapsed = time.perf_counter() - started
        RETRIEVAL_LATENCY.observe(elapsed)
        return RetrievalResult(
            hits=hits,
            confidence=confidence,
            conflicts=[c for c in conflicts if any(h.document_id == c.winner_document_id for h in hits)],
            embedding_model=embedder.model_id,
            latency_ms=int(elapsed * 1000),
        )

    @staticmethod
    def hit_confidence(hit: RetrievedChunk, terms: list[str], embedder: Embedder) -> float:
        vector_part = (
            calibrate(hit.vector_similarity, embedder.calibration) if hit.vector_similarity is not None else 0.0
        )
        lexical_part = coverage(terms, f"{hit.title} {hit.section_path or ''} {hit.content}")
        return round(VECTOR_WEIGHT * vector_part + LEXICAL_WEIGHT * lexical_part, 4)

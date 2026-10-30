from __future__ import annotations

from datetime import date

import pytest
from sqlalchemy import text

from app.deps import Container
from app.embeddings.hashing import HashEmbedder
from app.ingest.chunker import ChunkDraft
from app.retrieval.filters import SearchFilters, Viewer
from app.retrieval.indexes import index_name
from app.retrieval.store import DocumentMeta, DocumentOwnershipError, embedding_text

pytestmark = pytest.mark.db

EMBEDDER = HashEmbedder()
TODAY = date(2026, 10, 22)
MEMBER = Viewer(role="employee", department_ids=(1, 2), today=TODAY)
HR, FINANCE = 1, 2


def approved(document_id: int, workspace_id: int, title: str, mime: str, **kwargs: object) -> DocumentMeta:
    values: dict[str, object] = {"approval_status": "approved", "effective_date": date(2026, 1, 1)}
    values.update(kwargs)
    return DocumentMeta(document_id, workspace_id, title, mime, **values)  # type: ignore[arg-type]


def store_doc(container: Container, meta: DocumentMeta, contents: list[str], sections: list[str] | None = None) -> None:
    drafts = [
        ChunkDraft(
            ordinal=i,
            content=c,
            page=i + 1,
            section_path=sections[i] if sections is not None else None,
            token_count=20,
        )
        for i, c in enumerate(contents)
    ]
    vectors = EMBEDDER.embed_documents([embedding_text(meta.title, d.section_path, d.content) for d in drafts])
    container.chunk_store.replace_document(
        meta, drafts, vectors, EMBEDDER, pages=len(drafts), ocr_used=False, content_sha256="x" * 64
    )


@pytest.fixture
def corpus(container: Container) -> Container:
    store_doc(
        container,
        approved(1, 100, "Leave policy", "application/pdf", department_ids=(HR,)),
        [
            "Employees get 28 calendar days of paid vacation per year.",
            "Sick leave requires a doctor's note after three days.",
        ],
        ["Leave policy > Annual leave", "Leave policy > Sick leave"],
    )
    store_doc(
        container,
        approved(2, 100, "Регламент командировок", "application/pdf", department_ids=(FINANCE,)),
        ["Суточные при командировках по России составляют 700 рублей в день.", "Отчёт сдаётся в течение 3 дней."],
    )
    store_doc(
        container,
        approved(3, 100, "Payroll secrets", "text/plain", department_ids=(FINANCE,), roles=("manager",)),
        ["Salary bank file INV-2024-77 is uploaded on the 5th of every month."],
    )
    store_doc(
        container,
        approved(4, 200, "Leave policy", "text/plain", department_ids=(HR,)),
        ["Other workspace: employees get 99 vacation days."],
    )
    return container


def search(container: Container, query: str, viewer: Viewer = MEMBER, **kwargs: object) -> list[int]:
    result = container.retriever.search(workspace_id=100, query=query, embedder=EMBEDDER, viewer=viewer, **kwargs)  # type: ignore[arg-type]
    return [h.document_id for h in result.hits]


def test_relevant_chunk_ranks_first_with_confidence(corpus: Container) -> None:
    result = corpus.retriever.search(
        workspace_id=100, query="How many vacation days do employees get?", embedder=EMBEDDER, viewer=MEMBER, k=3
    )
    top = result.hits[0]
    assert top.document_id == 1 and "28 calendar days" in top.content
    assert top.section_path == "Leave policy > Annual leave" and top.page == 1
    assert top.vector_similarity is not None and top.fts_rank is not None
    assert result.confidence >= 0.35
    assert result.hits == sorted(result.hits, key=lambda h: -h.score)


def test_russian_query_uses_russian_fts_and_stemming(corpus: Container) -> None:
    result = corpus.retriever.search(
        workspace_id=100, query="Какие суточные в командировке?", embedder=EMBEDDER, viewer=MEMBER, k=3
    )
    assert result.hits[0].document_id == 2 and "700 рублей" in result.hits[0].content
    assert result.confidence >= 0.35


def test_workspace_isolation_is_absolute(corpus: Container) -> None:
    for query in ("vacation days", "99 vacation days other workspace"):
        assert 4 not in search(corpus, query, Viewer(role="owner", privileged=True, today=TODAY))


def test_role_filter(corpus: Container) -> None:
    query = "When is the salary bank file INV-2024-77 uploaded?"
    assert 3 not in search(corpus, query, MEMBER)
    assert search(corpus, query, Viewer(role="manager", department_ids=(FINANCE,), today=TODAY))[0] == 3
    assert search(corpus, query, Viewer(role="owner", privileged=True, today=TODAY))[0] == 3


def test_filters_department_tags_and_documents(corpus: Container) -> None:
    assert set(search(corpus, "days", filters=SearchFilters(department_ids=(HR,)))) == {1}
    assert set(search(corpus, "days", filters=SearchFilters(document_ids=(2,)))) == {2}
    assert search(corpus, "days", filters=SearchFilters(tags=("nope",))) == []


def test_unrelated_question_has_low_confidence(corpus: Container) -> None:
    result = corpus.retriever.search(
        workspace_id=100, query="What is the parking fee at the airport?", embedder=EMBEDDER, viewer=MEMBER
    )
    assert result.confidence < 0.35


def test_newer_version_wins_conflicts(container: Container) -> None:
    store_doc(
        container,
        approved(10, 100, "Remote work policy v1", "text/plain", version="1", effective_date=date(2024, 1, 1)),
        ["Remote work is allowed two days per week."],
    )
    store_doc(
        container,
        approved(11, 100, "Remote work policy v2", "text/plain", version="2", effective_date=date(2025, 6, 1)),
        ["Remote work is allowed three days per week."],
    )
    result = container.retriever.search(
        workspace_id=100, query="How many remote work days per week?", embedder=EMBEDDER, viewer=MEMBER
    )
    assert [h.document_id for h in result.hits] == [11]
    [conflict] = result.conflicts
    assert (conflict.winner_document_id, conflict.superseded_document_id) == (11, 10)
    unresolved = container.retriever.search(
        workspace_id=100, query="remote work days", embedder=EMBEDDER, viewer=MEMBER, resolve=False
    )
    assert {h.document_id for h in unresolved.hits} == {10, 11}


def test_hnsw_index_is_created_per_model_and_reingest_replaces(corpus: Container) -> None:
    with corpus.engine.connect() as conn:
        indexes = {row[0] for row in conn.execute(text("SELECT indexname FROM pg_indexes WHERE schemaname='rag'"))}
        assert index_name(EMBEDDER.model_id, 384) in indexes
        definition = conn.execute(
            text("SELECT indexdef FROM pg_indexes WHERE indexname = :n"), {"n": index_name(EMBEDDER.model_id, 384)}
        ).scalar_one()
        assert "hnsw" in definition and "vector(384)" in definition and "hash:hash-384" in definition
    store_doc(corpus, approved(1, 100, "Leave policy", "application/pdf"), ["Replaced content only."])
    with corpus.engine.connect() as conn:
        assert conn.execute(text("SELECT count(*) FROM rag.chunks WHERE document_id = 1")).scalar_one() == 1


def test_document_ownership_and_delete(corpus: Container) -> None:
    with pytest.raises(DocumentOwnershipError):
        corpus.chunk_store.delete_document(200, 1)
    with pytest.raises(DocumentOwnershipError):
        store_doc(corpus, DocumentMeta(1, 200, "Hijack", "text/plain"), ["x"])
    assert corpus.chunk_store.delete_document(100, 1) == 2
    assert corpus.chunk_store.delete_document(100, 1) == 0
    assert 1 not in search(corpus, "vacation days")


def test_reembed_switches_model(corpus: Container) -> None:
    other = HashEmbedder(dims=256)
    assert corpus.chunk_store.reembed_workspace(100, other, batch_size=2) == 5
    result = corpus.retriever.search(workspace_id=100, query="vacation days", embedder=other, viewer=MEMBER)
    assert result.hits[0].document_id == 1 and result.hits[0].vector_similarity is not None
    old = corpus.retriever.search(workspace_id=100, query="vacation days", embedder=EMBEDDER, viewer=MEMBER)
    assert all(h.vector_similarity is None for h in old.hits)

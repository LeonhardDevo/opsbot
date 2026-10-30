from __future__ import annotations

import itertools
from datetime import date

import pytest

from app.deps import Container
from app.embeddings.hashing import HashEmbedder
from app.ingest.chunker import ChunkDraft
from app.retrieval.filters import Viewer, is_visible
from app.retrieval.store import DocumentMeta, embedding_text

pytestmark = pytest.mark.db

EMBEDDER = HashEmbedder()
TODAY = date(2026, 10, 22)
QUESTION = "zebra mop protocol for marble floors"
CONTENT = "The zebra mop protocol for marble floors uses a dry microfibre pad first."

DEPARTMENTS = {"empty": (), "match": (1,), "miss": (9,)}
ROLES = {"empty": (), "match": ("employee",), "miss": ("manager",)}


def store(container: Container, meta: DocumentMeta) -> None:
    drafts = [ChunkDraft(ordinal=0, content=CONTENT, page=1, section_path=None, token_count=20)]
    vectors = EMBEDDER.embed_documents([embedding_text(meta.title, None, CONTENT)])
    container.chunk_store.replace_document(
        meta, drafts, vectors, EMBEDDER, pages=1, ocr_used=False, content_sha256="0" * 64
    )


def visible_ids(container: Container, viewer: Viewer) -> set[int]:
    result = container.retriever.search(workspace_id=100, query=QUESTION, embedder=EMBEDDER, viewer=viewer, k=50)
    return {hit.document_id for hit in result.hits}


def test_acl_truth_table_matches_the_python_rule(container: Container) -> None:
    cases = list(itertools.product(DEPARTMENTS.items(), ROLES.items()))
    for index, ((_, departments), (_, roles)) in enumerate(cases, start=1):
        store(
            container,
            DocumentMeta(
                index,
                100,
                f"Zebra protocol {index}",
                "text/plain",
                department_ids=departments,
                roles=roles,
                approval_status="approved",
                effective_date=date(2026, 1, 1),
            ),
        )
    for privileged in (False, True):
        viewer = Viewer(role="employee", department_ids=(1,), privileged=privileged, today=TODAY)
        expected = {
            index
            for index, ((_, departments), (_, roles)) in enumerate(cases, start=1)
            if is_visible(
                department_ids=list(departments),
                roles=list(roles),
                approval_status="approved",
                archived=False,
                effective_date=date(2026, 1, 1),
                viewer=viewer,
            )
        }
        assert visible_ids(container, viewer) == expected
    assert visible_ids(container, Viewer(role="employee", department_ids=(1,), today=TODAY)) == {1, 2, 4, 5}
    assert len(visible_ids(container, Viewer(role="owner", privileged=True, today=TODAY))) == 9


def test_lifecycle_filter(container: Container) -> None:
    variants = [
        ("approved", date(2026, 1, 1), False, True),
        ("draft", date(2026, 1, 1), False, False),
        ("approved", date(2026, 12, 1), False, False),
        ("approved", TODAY, False, True),
        ("approved", date(2026, 1, 1), True, False),
        ("approved", None, False, False),
    ]
    for index, (status, effective, archived, _) in enumerate(variants, start=1):
        store(
            container,
            DocumentMeta(
                index,
                100,
                f"Zebra protocol {index}",
                "text/plain",
                approval_status=status,
                effective_date=effective,
                archived=archived,
            ),
        )
    viewer = Viewer(role="owner", privileged=True, today=TODAY)
    assert visible_ids(container, viewer) == {i for i, v in enumerate(variants, start=1) if v[3]}
    drafts_viewer = Viewer(role="owner", privileged=True, today=TODAY, include_drafts=True)
    assert visible_ids(container, drafts_viewer) == set(range(1, len(variants) + 1))
    tomorrow = Viewer(role="owner", privileged=True, today=date(2026, 12, 1))
    assert 3 in visible_ids(container, tomorrow)

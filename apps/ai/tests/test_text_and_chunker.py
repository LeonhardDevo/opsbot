from __future__ import annotations

import itertools

import pytest

from app.ingest.blocks import Block, heading_level, lines_to_blocks
from app.ingest.chunker import Chunker
from app.text import content_terms, coverage, estimate_tokens, fold, query_lexemes, split_sentences, stem


def test_fold_and_stemming_are_bilingual() -> None:
    assert fold("Ёлка Café") == "елка cafe"
    assert stem("отпуска") == stem("отпуск") == "отпуск"
    assert stem("сотрудникам") == stem("сотруднику") == "сотрудник"
    assert stem("employees") == stem("employee") or stem("employees").startswith("employe")
    assert content_terms("How many vacation days do I get?") == ["many", "vacation", "day"]
    assert "сколько" not in content_terms("Сколько дней отпуска?")


def test_coverage_and_lexemes() -> None:
    terms = content_terms("Сколько дней отпуска положено сотруднику?")
    assert coverage(terms, "Сотрудникам предоставляется отпуск 28 календарных дней.") == pytest.approx(0.75)
    assert coverage([], "anything") == 0.0
    assert query_lexemes("What is the refund LIMIT for INV-2024?") == ["refund", "limit", "inv", "2024"]


def test_sentences_and_token_estimate() -> None:
    assert split_sentences("First one. Second one! Третье? 4th") == ["First one.", "Second one!", "Третье?", "4th"]
    assert estimate_tokens("") == 0
    assert 8 <= estimate_tokens("one two three four five six, seven.") <= 12


@pytest.mark.parametrize(
    ("line", "level"),
    [
        ("1. Introduction", 1),
        ("2.1 Annual leave", 2),
        ("3.2.1 Carry-over rules", 3),
        ("Раздел 4. Командировки", 1),
        ("GENERAL PROVISIONS", 1),
        ("1. Submit the form to HR.", 0),
        ("This is a normal sentence that happens to be fairly short", 0),
    ],
)
def test_heading_heuristics(line: str, level: int) -> None:
    assert heading_level(line) == level


def test_lines_to_blocks_rebuilds_paragraphs_lists_and_hyphenation() -> None:
    text = (
        "1. Scope\nThis policy applies to all\nemployees and contrac-\ntors.\n\n- first item\n- second item\ncontinued"
    )
    blocks = lines_to_blocks(text, page=3)
    assert [(b.kind, b.text) for b in blocks] == [
        ("heading", "1. Scope"),
        ("paragraph", "This policy applies to all employees and contractors."),
        ("list", "- first item"),
        ("list", "- second item continued"),
    ]
    assert all(b.page == 3 for b in blocks)


def _para(words: int, word: str = "lorem", page: int | None = None) -> Block:
    sentences = []
    for i in range(0, words, 10):
        sentences.append((" ".join([word] * min(10, words - i)) + ".").capitalize())
    return Block(" ".join(sentences), "paragraph", page=page)


def test_chunker_tracks_section_path_and_merges_tiny_sections() -> None:
    blocks = [
        Block("Leave policy", "heading", level=1),
        Block("1. Annual leave", "heading", level=2),
        _para(200, "annual"),
        Block("1.1 Carry over", "heading", level=3),
        Block("Unused days can be carried over once.", "paragraph"),
        Block("2. Sick leave", "heading", level=2),
        _para(200, "sick"),
    ]
    chunks = Chunker(target_tokens=700).chunk(blocks)
    assert [c.section_path for c in chunks] == ["Leave policy > 1. Annual leave", "Leave policy > 2. Sick leave"]
    first = chunks[0].content
    assert "1.1 Carry over" in first and "carried over once" in first
    assert all(c.ordinal == i for i, c in enumerate(chunks))


def test_chunker_respects_target_and_overlap() -> None:
    blocks = [Block("Handbook", "heading", level=1)] + [_para(120, f"w{i}") for i in range(12)]
    chunker = Chunker(target_tokens=300, overlap_ratio=0.15)
    chunks = chunker.chunk(blocks)
    assert len(chunks) >= 4
    assert all(c.token_count <= 300 + 60 for c in chunks)
    for previous, current in itertools.pairwise(chunks):
        tail_sentence = previous.content.split(". ")[-1].strip(". ")
        assert current.content.startswith(tail_sentence)


def test_chunker_splits_on_pages_and_keeps_page_numbers() -> None:
    blocks = [_para(150, "alpha", page=1), _para(150, "beta", page=2), Block("Tiny tail.", "paragraph", page=2)]
    chunks = Chunker(target_tokens=700).chunk(blocks)
    assert [c.page for c in chunks] == [1, 2]
    assert "Tiny tail." in chunks[1].content
    chunks = Chunker(target_tokens=700).chunk([*blocks, Block("Signed by the CEO.", "paragraph", page=3)])
    assert [c.page for c in chunks] == [1, 2, 3]


def test_chunker_tiny_intro_is_carried_into_first_section() -> None:
    blocks = [
        Block("Handbook", "heading", level=1),
        Block("Read this first.", "paragraph"),
        Block("1. Basics", "heading", level=2),
        _para(60, "basic"),
    ]
    chunks = Chunker(target_tokens=700).chunk(blocks)
    assert len(chunks) == 1
    assert chunks[0].section_path == "Handbook"
    assert chunks[0].content.startswith("Read this first.\n1. Basics\n")


def test_chunker_splits_oversized_tables_by_rows() -> None:
    rows = "\n".join(f"row {i} | value {i} | note" for i in range(400))
    chunks = Chunker(target_tokens=200).chunk([Block(rows, "table")])
    assert len(chunks) > 3
    assert chunks[0].content.startswith("row 0 | value 0")


def test_chunker_rejects_silly_targets_and_handles_empty() -> None:
    with pytest.raises(ValueError, match="target_tokens"):
        Chunker(target_tokens=10)
    assert Chunker().chunk([]) == []
    assert Chunker().chunk([Block("Only a heading", "heading", level=1)]) == []

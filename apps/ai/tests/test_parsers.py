from __future__ import annotations

import io

import pytest

from app.ingest.ocr import OcrEngine
from app.ingest.parsers import (
    DocumentParseError,
    DocumentParser,
    UnsupportedDocumentError,
    decode_text,
    detect_format,
)
from tests.conftest import requires_tesseract
from tests.helpers import image_bytes, make_docx, make_text_image, make_text_pdf


@pytest.fixture
def parser() -> DocumentParser:
    return DocumentParser(OcrEngine("rus+eng"))


class _NoOcr(OcrEngine):
    def available(self) -> bool:
        return False


def test_detect_format_by_mime_extension_and_magic() -> None:
    pdf = make_text_pdf([["Hello"]])
    assert detect_format(pdf, "application/pdf") == "pdf"
    assert detect_format(pdf, "application/octet-stream") == "pdf"
    assert detect_format(b"# Title", "text/markdown") == "md"
    assert detect_format(b"plain", None, "notes.md") == "md"
    assert detect_format(b"<!doctype html><p>x</p>", "application/octet-stream") == "html"
    assert detect_format(b"\x89PNG\r\n\x1a\n....", None) == "image"
    assert detect_format(b"text", "text/plain") == "txt"
    with pytest.raises(UnsupportedDocumentError):
        detect_format(b"\x00\x01binary", "application/x-msdownload")


def test_decode_text_handles_utf8_bom_and_cp1251() -> None:
    assert decode_text("﻿Привет".encode()) == "Привет"
    assert decode_text("Отпуск и командировки".encode("cp1251")) == "Отпуск и командировки"
    assert decode_text("café".encode("latin-1")) == "café"


def test_pdf_with_text_layer_keeps_pages_and_headings(parser: DocumentParser) -> None:
    pdf = make_text_pdf(
        [
            ["1. Expense policy", "Employees submit expense reports within 30 days."],
            ["2. Approvals", "Reports above 500 EUR need a second approval."],
        ]
    )
    doc = parser.parse(pdf, "application/pdf", "policy.pdf")
    assert doc.pages == 2 and not doc.ocr_used
    headings = [(b.text, b.page) for b in doc.blocks if b.kind == "heading"]
    assert headings == [("1. Expense policy", 1), ("2. Approvals", 2)]
    assert any("second approval" in b.text and b.page == 2 for b in doc.blocks)


def test_scanned_pdf_without_ocr_reports_clear_error() -> None:
    from PIL import Image

    scanned = io.BytesIO()
    Image.new("RGB", (300, 200), "white").save(scanned, "PDF")
    parser = DocumentParser(_NoOcr())
    with pytest.raises(DocumentParseError, match="no text"):
        parser.parse(scanned.getvalue(), "application/pdf")


@requires_tesseract
@pytest.mark.ocr
def test_scanned_pdf_is_ocred(parser: DocumentParser) -> None:
    image = make_text_image(["Travel policy", "Hotel limit is 150 EUR per night"])
    scanned = io.BytesIO()
    image.save(scanned, "PDF", resolution=150)
    doc = parser.parse(scanned.getvalue(), "application/pdf", "scan.pdf")
    assert doc.ocr_used
    text = " ".join(b.text for b in doc.blocks).lower()
    assert "hotel" in text and "150" in text
    if "rus" in OcrEngine("rus+eng").missing_languages:
        assert any("rus" in w for w in doc.warnings)


@requires_tesseract
@pytest.mark.ocr
def test_image_is_ocred(parser: DocumentParser) -> None:
    png = image_bytes(make_text_image(["Support hours", "Monday to Friday 9 to 18"]))
    doc = parser.parse(png, "image/png", "hours.png")
    assert doc.ocr_used and doc.pages == 1
    assert "monday" in " ".join(b.text for b in doc.blocks).lower()


def test_image_without_ocr_is_rejected() -> None:
    from app.ingest.ocr import OcrUnavailableError

    png = image_bytes(make_text_image(["x"]))
    with pytest.raises(OcrUnavailableError):
        DocumentParser(_NoOcr()).parse(png, "image/png")


def test_docx_headings_lists_and_tables(parser: DocumentParser) -> None:
    def build(document: object) -> None:
        document.add_heading("Регламент командировок", level=1)  # type: ignore[attr-defined]
        document.add_heading("Суточные", level=2)  # type: ignore[attr-defined]
        document.add_paragraph("Суточные выплачиваются за каждый день командировки.")  # type: ignore[attr-defined]
        document.add_paragraph("Билеты эконом-класса", style="List Bullet")  # type: ignore[attr-defined]
        table = document.add_table(rows=2, cols=2)  # type: ignore[attr-defined]
        table.cell(0, 0).text, table.cell(0, 1).text = "Город", "Лимит"
        table.cell(1, 0).text, table.cell(1, 1).text = "Москва", "7000"

    doc = parser.parse(make_docx(build), "application/vnd.openxmlformats-officedocument.wordprocessingml.document")
    kinds = [(b.kind, b.level, b.text) for b in doc.blocks]
    assert kinds[0] == ("heading", 1, "Регламент командировок")
    assert kinds[1] == ("heading", 2, "Суточные")
    assert ("list", 0, "Билеты эконом-класса") in kinds
    assert ("table", 0, "Город | Лимит\nМосква | 7000") in kinds
    assert doc.pages is None


def test_markdown_structure(parser: DocumentParser) -> None:
    md = b"""# Onboarding

Welcome to the team.

## Accounts

- Laptop is issued on day one
- Email is created by IT

| System | Owner |
|---|---|
| CRM | Sales |

```
code sample
```
"""
    doc = parser.parse(md, "text/markdown")
    assert [(b.kind, b.text) for b in doc.blocks] == [
        ("heading", "Onboarding"),
        ("paragraph", "Welcome to the team."),
        ("heading", "Accounts"),
        ("list", "Laptop is issued on day one"),
        ("list", "Email is created by IT"),
        ("table", "System | Owner\nCRM | Sales"),
        ("code", "code sample"),
    ]


def test_html_strips_chrome_and_keeps_structure(parser: DocumentParser) -> None:
    html = """<html><head><style>p{}</style><script>alert(1)</script></head><body>
    <nav>Menu</nav><h1>Security</h1><div><p>Use <b>two-factor</b> authentication.</p></div>
    <ul><li>Lock your screen</li></ul><table><tr><th>Level</th><th>Action</th></tr>
    <tr><td>High</td><td>Call security</td></tr></table><footer>Copyright</footer></body></html>"""
    doc = parser.parse(html.encode(), "text/html")
    assert [(b.kind, b.text) for b in doc.blocks] == [
        ("heading", "Security"),
        ("paragraph", "Use two-factor authentication."),
        ("list", "Lock your screen"),
        ("table", "Level | Action\nHigh | Call security"),
    ]


def test_txt_and_empty_inputs(parser: DocumentParser) -> None:
    doc = parser.parse("ОБЩИЕ ПОЛОЖЕНИЯ\nТекст регламента.".encode("cp1251"), "text/plain")
    assert doc.blocks[0].kind == "heading"
    assert doc.blocks[1].text == "Текст регламента."
    with pytest.raises(DocumentParseError, match="empty"):
        parser.parse(b"", "text/plain")
    with pytest.raises(DocumentParseError):
        parser.parse(b"%PDF-1.4 broken", "application/pdf")


DOCX_MIME = "application/vnd.openxmlformats-officedocument.wordprocessingml.document"


def _zip_bomb_docx(uncompressed_mb: int) -> bytes:
    import zipfile

    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("[Content_Types].xml", "<Types/>")
        chunk = b"\0" * (1024 * 1024)
        with archive.open("word/document.xml", "w", force_zip64=True) as entry:
            for _ in range(uncompressed_mb):
                entry.write(chunk)
    return buffer.getvalue()


def test_docx_zip_bomb_is_rejected_before_parsing(parser: DocumentParser) -> None:
    bomb = _zip_bomb_docx(120)
    assert len(bomb) < 1024 * 1024
    with pytest.raises(DocumentParseError, match=r"expands beyond|compression ratio"):
        parser.parse(bomb, DOCX_MIME, "bomb.docx")


def test_docx_with_suspicious_compression_ratio_is_rejected(parser: DocumentParser) -> None:
    with pytest.raises(DocumentParseError, match="compression ratio"):
        parser.parse(_zip_bomb_docx(20), DOCX_MIME, "ratio.docx")


@requires_tesseract
def test_oversized_image_is_rejected_as_decompression_bomb(parser: DocumentParser) -> None:
    from PIL import Image

    buffer = io.BytesIO()
    Image.new("1", (12_000, 9_000)).save(buffer, format="PNG")
    with pytest.raises(DocumentParseError):
        parser.parse(buffer.getvalue(), "image/png", "huge.png")


def test_ocr_timeout_is_reported() -> None:
    from typing import Any

    from app.ingest.ocr import OcrTimeoutError

    class _SlowTesseract:
        def image_to_string(self, *_: Any, **kwargs: Any) -> str:
            assert kwargs["timeout"] == 0.5
            raise RuntimeError("Tesseract process timeout")

    class _Engine(OcrEngine):
        def _resolve(self) -> str:
            return "eng"

        def _pytesseract(self) -> Any:
            return _SlowTesseract()

    with pytest.raises(OcrTimeoutError, match="timed out"):
        _Engine("eng", timeout_seconds=0.5).image_to_text(object())

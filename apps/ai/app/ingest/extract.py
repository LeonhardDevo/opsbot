from __future__ import annotations

import time
from dataclasses import dataclass, field
from typing import Any

from app.ingest.ocr import OcrUnavailableError
from app.ingest.pages import PageText, blocks_to_pages
from app.ingest.parsers import DocumentParseError, DocumentParser, TooManyPagesError, UnsupportedDocumentError
from app.observability import get_logger
from app.text import language_guess

log = get_logger(__name__)


class ExtractionError(RuntimeError):
    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code


@dataclass(slots=True)
class Extraction:
    pages: list[PageText]
    pages_total: int
    ocr_used: bool
    language_guess: str | None
    warnings: list[str] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return {
            "pages": [{"page": p.page, "text": p.text} for p in self.pages],
            "pages_total": self.pages_total,
            "ocr_used": self.ocr_used,
            "language_guess": self.language_guess,
            "warnings": self.warnings,
        }


class ExtractService:
    def __init__(self, parser: DocumentParser) -> None:
        self._parser = parser

    def extract(
        self, data: bytes, mime: str | None, filename: str | None = None, *, max_pages: int | None = None
    ) -> Extraction:
        started = time.perf_counter()
        try:
            parsed = self._parser.parse(data, mime, filename, max_pages=max_pages)
        except TooManyPagesError as exc:
            raise ExtractionError("too_many_pages", str(exc)) from exc
        except UnsupportedDocumentError as exc:
            raise ExtractionError("unsupported_type", str(exc)) from exc
        except OcrUnavailableError as exc:
            raise ExtractionError("ocr_failed", str(exc)) from exc
        except DocumentParseError as exc:
            raise ExtractionError("empty_text", str(exc)) from exc
        pages = blocks_to_pages(parsed.blocks)
        if not pages:
            raise ExtractionError("empty_text", "the document contains no extractable text")
        total = max(parsed.pages or 0, len(pages), pages[-1].page)
        log.info(
            "document_extracted",
            pages=total,
            ocr_used=parsed.ocr_used,
            ms=int((time.perf_counter() - started) * 1000),
        )
        return Extraction(
            pages=pages,
            pages_total=total,
            ocr_used=parsed.ocr_used,
            language_guess=language_guess(" ".join(p.text for p in pages[:3])),
            warnings=list(parsed.warnings),
        )

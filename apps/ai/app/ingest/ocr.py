from __future__ import annotations

import threading
from typing import Any

from app.observability import get_logger

log = get_logger(__name__)


class OcrUnavailableError(RuntimeError):
    pass


class OcrTimeoutError(OcrUnavailableError):
    pass


class OcrEngine:
    def __init__(
        self,
        languages: str = "rus+eng",
        tesseract_cmd: str | None = None,
        dpi: int = 300,
        timeout_seconds: float = 120.0,
    ) -> None:
        self._requested = [lang for lang in languages.replace(",", "+").split("+") if lang.strip()]
        self._tesseract_cmd = tesseract_cmd
        self.dpi = dpi
        self.timeout_seconds = timeout_seconds
        self._resolved: str | None = None
        self._missing: list[str] = []
        self._error: str | None = None
        self._lock = threading.Lock()

    def _pytesseract(self) -> Any:
        import pytesseract

        if self._tesseract_cmd:
            pytesseract.pytesseract.tesseract_cmd = self._tesseract_cmd
        return pytesseract

    def _resolve(self) -> str:
        with self._lock:
            if self._resolved is not None:
                return self._resolved
            if self._error is not None:
                raise OcrUnavailableError(self._error)
            try:
                installed = set(self._pytesseract().get_languages(config=""))
            except Exception as exc:
                self._error = f"tesseract is not available: {exc}"
                log.warning("ocr_unavailable", error=self._error)
                raise OcrUnavailableError(self._error) from exc
            usable = [lang for lang in self._requested if lang in installed]
            self._missing = [lang for lang in self._requested if lang not in installed]
            if self._missing:
                log.warning("ocr_languages_missing", missing=self._missing, installed=sorted(installed))
            if not usable:
                usable = ["eng"] if "eng" in installed else sorted(installed - {"osd"})[:1]
            if not usable:
                self._error = "tesseract has no usable language data"
                raise OcrUnavailableError(self._error)
            self._resolved = "+".join(usable)
            return self._resolved

    @property
    def missing_languages(self) -> list[str]:
        try:
            self._resolve()
        except OcrUnavailableError:
            return list(self._requested)
        return list(self._missing)

    def available(self) -> bool:
        try:
            self._resolve()
        except OcrUnavailableError:
            return False
        return True

    def languages(self) -> str:
        return self._resolve()

    def image_to_text(self, image: Any) -> str:
        languages = self._resolve()
        try:
            text: str = self._pytesseract().image_to_string(
                image, lang=languages, config="--psm 3", timeout=self.timeout_seconds
            )
        except RuntimeError as exc:
            if "timeout" in str(exc).lower():
                raise OcrTimeoutError(f"OCR timed out after {self.timeout_seconds:g}s per page") from exc
            raise OcrUnavailableError(f"OCR failed: {exc}") from exc
        except Exception as exc:
            raise OcrUnavailableError(f"OCR failed: {exc}") from exc
        return text

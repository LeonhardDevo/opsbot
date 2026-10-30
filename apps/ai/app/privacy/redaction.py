from __future__ import annotations

import re

LOCAL_PROVIDERS: frozenset[str] = frozenset({"mock", "ollama"})

_NOT_AFTER_CODE = r"(?<![^\W\d_]-)(?<![#№/])"
_REFERENCE = re.compile(r"(?<![\w-])(?:[^\W\d_]{2,10}-|#|№ ?)\d+(?:-\d+)*(?![\w-])")
_SLOT = re.compile(r"(\d+)")

_EMAIL = re.compile(r"(?<![\w.+-])[\w.+-]{1,64}@[\w-]{1,63}(?:\.[\w-]{1,63})+")
_IBAN = re.compile(r"\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}\b")
_SNILS = re.compile(r"(?<![\d-])\d{3}-\d{3}-\d{3}[ -]\d{2}(?![\d-])")
_PASSPORT_RU = re.compile(r"(?<![\d-])\d{2} ?\d{2} \d{6}(?![\d-])")
_CARD = re.compile(_NOT_AFTER_CODE + r"(?<![\d-])\d(?:[ -]?\d){12,18}(?![\d-])")
_CARD_GROUPED = re.compile(r"\d{4}(?:[ -]\d{4}){2,3}(?:[ -]\d{1,3})?|\d{4}[ -]\d{6}[ -]\d{4,5}")
_ACCOUNT = re.compile(_NOT_AFTER_CODE + r"(?<!\d)\d{10,34}(?!\d)")
_PHONE = re.compile(_NOT_AFTER_CODE + r"(?<![\w+(])(?:\+|\()?\d[\d ().-]{6,20}\d(?!\w)")


def is_local_provider(provider_id: str) -> bool:
    return provider_id in LOCAL_PROVIDERS


def _digits(value: str) -> str:
    return re.sub(r"\D", "", value)


def _luhn_valid(number: str) -> bool:
    total = 0
    for index, char in enumerate(reversed(number)):
        digit = int(char)
        if index % 2 == 1:
            digit *= 2
            if digit > 9:
                digit -= 9
        total += digit
    return total % 10 == 0


def _mask(kind: str, value: str) -> str:
    tail = _digits(value)[-4:]
    return f"[{kind} ****{tail}]" if tail else f"[{kind}]"


def _card(match: re.Match[str]) -> str:
    value = match.group(0)
    number = _digits(value)
    if not 13 <= len(number) <= 19:
        return value
    if _luhn_valid(number):
        return _mask("card", number)
    if _CARD_GROUPED.fullmatch(value):
        return _mask("number", number)
    return value


def _phone(match: re.Match[str]) -> str:
    value = match.group(0)
    digits = _digits(value)
    if (value.startswith("+") and 8 <= len(digits) <= 15) or 10 <= len(digits) <= 15:
        return _mask("phone", digits)
    return value


def redact_text(text: str) -> str:
    if not text:
        return text
    references: list[str] = []

    def _keep(match: re.Match[str]) -> str:
        references.append(match.group(0))
        return f"\ue000{len(references) - 1}\ue001"

    redacted = _EMAIL.sub("[email]", text)
    redacted = _REFERENCE.sub(_keep, redacted)
    redacted = _IBAN.sub(lambda m: _mask("IBAN", m.group(0)), redacted)
    redacted = _SNILS.sub("[SNILS]", redacted)
    redacted = _PASSPORT_RU.sub(lambda m: _mask("passport", m.group(0)), redacted)
    redacted = _CARD.sub(_card, redacted)
    redacted = _ACCOUNT.sub(lambda m: _mask("account", m.group(0)), redacted)
    redacted = _PHONE.sub(_phone, redacted)
    return _SLOT.sub(lambda m: references[int(m.group(1))], redacted)

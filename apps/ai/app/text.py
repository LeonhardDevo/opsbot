from __future__ import annotations

import math
import re
import unicodedata
from collections.abc import Iterable

_WORD_RE = re.compile(r"[^\W_]+", re.UNICODE)
_TOKEN_RE = re.compile(r"\w+|[^\w\s]", re.UNICODE)
_SENTENCE_RE = re.compile(r"(?<=[.!?…])\s+(?=[\"'«(\[]?[A-ZА-ЯЁ0-9])|\n+")
_CYRILLIC_RE = re.compile(r"[а-яё]", re.IGNORECASE)

STOPWORDS: frozenset[str] = frozenset(
    """
    a an the and or but if of to in on at by for with from as is are was were be been being am do does did have
    has had not no yes it its this that these those there here what which who whom whose when where why how i me
    my we our you your he him his she her they them their can could should would will shall may might must about
    into over under than then so such any all some each other more most very just also only own same too please
    tell show give get let know need want find search и в во не что он на я с со как а то все она так его но да
    ты к у же вы за бы по только ее её мне было вот от меня еще ещё нет о из ему теперь когда даже ну вдруг ли
    если уже или ни быть был него до вас нибудь опять уж вам ведь там потом себя ничего ей может они тут где
    есть надо ней для мы тебя их чем была сам чтоб без будто чего раз тоже себе под будет ж тогда кто этот того
    потому этого какой совсем ним здесь этом один почти мой тем чтобы нее неё сейчас были куда зачем всех
    никогда можно при наконец два об другой хоть после над больше тот через эти нас про всего них какая много
    разве три эту моя впрочем хорошо свою этой перед иногда лучше чуть том нельзя такой им более всегда конечно
    всю между это мне меня сколько скажи подскажи расскажи покажи найди какие каков какова каковы ли нужно хочу
    """.split()
)

_RU_SUFFIXES = (
    "иями",
    "ями",
    "ами",
    "ией",
    "ого",
    "его",
    "ому",
    "ему",
    "ыми",
    "ими",
    "иях",
    "ах",
    "ях",
    "ов",
    "ев",
    "ей",
    "ой",
    "ий",
    "ый",
    "ая",
    "яя",
    "ое",
    "ее",
    "ие",
    "ые",
    "ом",
    "ем",
    "ам",
    "ям",
    "ую",
    "юю",
    "а",
    "я",
    "о",
    "е",
    "и",
    "ы",
    "у",
    "ю",
    "ь",
    "й",
)
_EN_SUFFIXES = ("ingly", "edly", "ing", "ies", "ied", "ed", "es", "ly", "s")


def fold(text: str) -> str:
    lowered = text.lower().replace("ё", "е").replace("й", "и")
    decomposed = unicodedata.normalize("NFKD", lowered)
    return "".join(ch for ch in decomposed if not unicodedata.combining(ch))


def words(text: str) -> list[str]:
    return _WORD_RE.findall(fold(text))


def stem(token: str) -> str:
    if token.isdigit() or len(token) <= 3:
        return token
    suffixes = _RU_SUFFIXES if _CYRILLIC_RE.search(token) else _EN_SUFFIXES
    for suffix in suffixes:
        if token.endswith(suffix) and len(token) - len(suffix) >= 3:
            return token[: -len(suffix)]
    return token


_FOLDED_STOPWORDS = frozenset(fold(w) for w in STOPWORDS)


def content_terms(text: str) -> list[str]:
    seen: dict[str, None] = {}
    for token in words(text):
        if token in _FOLDED_STOPWORDS or (len(token) < 2 and not token.isdigit()):
            continue
        seen.setdefault(stem(token), None)
    return list(seen)


def query_lexemes(text: str, limit: int = 24) -> list[str]:
    seen: dict[str, None] = {}
    for token in words(text):
        if token in _FOLDED_STOPWORDS or len(token) < 2:
            continue
        seen.setdefault(token, None)
        if len(seen) >= limit:
            break
    return list(seen)


def coverage(query_terms: Iterable[str], text: str) -> float:
    terms = list(query_terms)
    if not terms:
        return 0.0
    present = set(content_terms(text))
    return sum(1 for term in terms if term in present) / len(terms)


def estimate_tokens(text: str) -> int:
    pieces = _TOKEN_RE.findall(text)
    word_count = sum(1 for p in pieces if p[0].isalnum() or p[0] == "_")
    return max(1, math.ceil(word_count * 1.3 + (len(pieces) - word_count) * 0.5)) if pieces else 0


def split_sentences(text: str) -> list[str]:
    return [s.strip() for s in _SENTENCE_RE.split(text) if s and s.strip()]


def truncate(text: str, limit: int) -> str:
    clean = " ".join(text.split())
    if len(clean) <= limit:
        return clean
    cut = clean[:limit].rsplit(" ", 1)[0]
    return f"{cut}…"


_LATIN_RE = re.compile(r"[a-z]", re.IGNORECASE)


def language_guess(text: str) -> str | None:
    cyrillic = len(_CYRILLIC_RE.findall(text))
    latin = len(_LATIN_RE.findall(text))
    if cyrillic + latin < 20:
        return None
    return "ru" if cyrillic >= latin else "en"

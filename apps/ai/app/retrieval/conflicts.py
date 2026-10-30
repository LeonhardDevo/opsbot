from __future__ import annotations

import re
from dataclasses import dataclass
from datetime import date
from typing import Protocol

from app.text import fold

_VERSION_MARKERS = re.compile(
    r"\b(?:v|ver|version|rev|revision|версия|ред|редакция|вер)\.?\s*\d+(?:[.\-]\d+)*\b"
    r"|\b\d{1,2}[./]\d{1,2}[./]\d{2,4}\b|\b(?:19|20)\d{2}\b",
    re.IGNORECASE,
)
_NON_WORD = re.compile(r"[^\w]+")


class HitLike(Protocol):
    document_id: int
    title: str
    department_ids: list[int]
    version: str | None
    effective_date: date | None


@dataclass(frozen=True, slots=True)
class Conflict:
    winner_document_id: int
    winner_title: str
    winner_version: str | None
    winner_effective_date: date | None
    superseded_document_id: int
    superseded_title: str
    superseded_version: str | None
    superseded_effective_date: date | None


def family_key(title: str, department_ids: list[int] | tuple[int, ...] = ()) -> str:
    cleaned = _VERSION_MARKERS.sub(" ", fold(title))
    cleaned = _NON_WORD.sub(" ", cleaned).strip()
    departments = ",".join(str(d) for d in sorted(set(department_ids)))
    return f"{departments}::{cleaned}"


def version_key(version: str | None) -> tuple[tuple[int, int | str], ...]:
    if not version:
        return ()
    parts = re.findall(r"\d+|[^\d\W]+", version.lower())
    return tuple((0, int(p)) if p.isdigit() else (1, p) for p in parts)


def recency_key(hit: HitLike) -> tuple[date, tuple[tuple[int, int | str], ...], int]:
    return (hit.effective_date or date.min, version_key(hit.version), hit.document_id)


def resolve_conflicts[T: HitLike](hits: list[T]) -> tuple[list[T], list[Conflict]]:
    families: dict[str, list[T]] = {}
    for hit in hits:
        families.setdefault(family_key(hit.title, hit.department_ids), []).append(hit)

    superseded: set[int] = set()
    conflicts: list[Conflict] = []
    for members in families.values():
        documents = {h.document_id: h for h in members}
        if len(documents) < 2:
            continue
        ordered = sorted(documents.values(), key=recency_key, reverse=True)
        winner = ordered[0]
        for loser in ordered[1:]:
            superseded.add(loser.document_id)
            conflicts.append(
                Conflict(
                    winner_document_id=winner.document_id,
                    winner_title=winner.title,
                    winner_version=winner.version,
                    winner_effective_date=winner.effective_date,
                    superseded_document_id=loser.document_id,
                    superseded_title=loser.title,
                    superseded_version=loser.version,
                    superseded_effective_date=loser.effective_date,
                )
            )
    kept = [h for h in hits if h.document_id not in superseded]
    return kept, conflicts

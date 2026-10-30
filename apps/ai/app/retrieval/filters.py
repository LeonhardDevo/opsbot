from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date
from typing import Any

APPROVED = "approved"


@dataclass(frozen=True, slots=True)
class Viewer:
    role: str
    department_ids: tuple[int, ...] = ()
    privileged: bool = False
    today: date = field(default_factory=date.today)
    include_drafts: bool = False


@dataclass(frozen=True, slots=True)
class SearchFilters:
    department_ids: tuple[int, ...] = ()
    tags: tuple[str, ...] = ()
    document_ids: tuple[int, ...] = ()


VISIBILITY_SQL = """
    c.workspace_id = :ws
    AND (
        :privileged
        OR (
            (cardinality(c.department_ids) = 0 OR c.department_ids && CAST(:viewer_departments AS integer[]))
            AND (cardinality(c.roles) = 0 OR CAST(:viewer_role AS text) = ANY(c.roles))
        )
    )
    AND (
        :include_drafts
        OR (c.approval_status = 'approved' AND c.archived_at IS NULL AND c.effective_date <= CAST(:today AS date))
    )
    AND (CAST(:filter_departments AS integer[]) IS NULL OR c.department_ids && CAST(:filter_departments AS integer[]))
    AND (CAST(:tags AS text[]) IS NULL OR c.tags && CAST(:tags AS text[]))
    AND (CAST(:doc_ids AS bigint[]) IS NULL OR c.document_id = ANY(CAST(:doc_ids AS bigint[])))
"""


def visibility_params(workspace_id: int, viewer: Viewer, filters: SearchFilters) -> dict[str, Any]:
    return {
        "ws": workspace_id,
        "privileged": viewer.privileged,
        "viewer_role": viewer.role,
        "viewer_departments": list(viewer.department_ids),
        "include_drafts": viewer.include_drafts,
        "today": viewer.today,
        "filter_departments": list(filters.department_ids) or None,
        "tags": list(filters.tags) or None,
        "doc_ids": list(filters.document_ids) or None,
    }


def is_visible(
    *,
    department_ids: list[int],
    roles: list[str],
    approval_status: str,
    archived: bool,
    effective_date: date | None,
    viewer: Viewer,
) -> bool:
    acl = viewer.privileged or (
        (not department_ids or bool(set(department_ids) & set(viewer.department_ids)))
        and (not roles or viewer.role in roles)
    )
    lifecycle = viewer.include_drafts or (
        approval_status == APPROVED and not archived and effective_date is not None and effective_date <= viewer.today
    )
    return acl and lifecycle

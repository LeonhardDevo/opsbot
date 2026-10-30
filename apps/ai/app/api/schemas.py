from __future__ import annotations

from datetime import date
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

_ID_PATTERN = r"^[A-Za-z0-9_\-:.]+$"


def _clean_list(values: list[str]) -> list[str]:
    seen: dict[str, None] = {}
    for value in values:
        cleaned = value.strip()
        if cleaned:
            seen.setdefault(cleaned, None)
    return list(seen)


ApprovalStatus = Literal["draft", "approved", "archived"]
Locale = Literal["ru", "en"]


class ChatUserIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: int | None = None
    role: str = Field(min_length=1, max_length=64)
    locale: str = Field(default="en", max_length=16)
    permissions: list[str] = Field(default_factory=list, max_length=200)
    department_ids: list[int] = Field(default_factory=list, max_length=500)
    privileged: bool = False


class ChatContextIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    today: date | None = None
    timezone: str | None = Field(default=None, max_length=64)
    min_confidence: float | None = Field(default=None, ge=0.0, le=1.0)
    ai_external_allowed: bool = False


class ChatIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    workspace_id: int = Field(gt=0)
    user: ChatUserIn
    channel: Literal["telegram", "web"]
    conversation_id: str | None = Field(default=None, max_length=120, pattern=_ID_PATTERN)
    message: str = Field(min_length=1, max_length=4000)
    context: ChatContextIn = Field(default_factory=ChatContextIn)

    @field_validator("message")
    @classmethod
    def _not_blank(cls, value: str) -> str:
        if not value.strip():
            raise ValueError("message must not be blank")
        return value


class SearchUserIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    role: str = Field(min_length=1, max_length=64)
    department_ids: list[int] = Field(default_factory=list, max_length=500)
    privileged: bool = False


class SearchFiltersIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    department_ids: list[int] = Field(default_factory=list, max_length=500)
    tags: list[str] = Field(default_factory=list, max_length=50)
    document_ids: list[int] = Field(default_factory=list, max_length=500)


class SearchIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    workspace_id: int = Field(gt=0)
    query: str = Field(min_length=1, max_length=1000)
    k: int = Field(default=8, ge=1, le=50)
    user: SearchUserIn | None = None
    include_drafts: bool = False
    today: date | None = None
    filters: SearchFiltersIn | None = None
    ai_external_allowed: bool = False


class DocumentMetadataIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    department_ids: list[int] = Field(default_factory=list, max_length=500)
    roles: list[str] = Field(default_factory=list, max_length=50)
    tags: list[str] = Field(default_factory=list, max_length=50)
    version: str | None = Field(default=None, max_length=64)
    effective_date: date | None = None
    approval_status: ApprovalStatus = "draft"
    language: Locale | None = None

    @field_validator("roles", "tags")
    @classmethod
    def _dedupe(cls, values: list[str]) -> list[str]:
        return _clean_list(values)

    @field_validator("department_ids")
    @classmethod
    def _unique_ids(cls, values: list[int]) -> list[int]:
        return sorted({v for v in values if v > 0})

    @field_validator("version")
    @classmethod
    def _blank_to_none(cls, value: str | None) -> str | None:
        return value.strip() or None if value else None


class PageIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    page: int = Field(ge=1, le=100_000)
    text: str = Field(default="", max_length=2_000_000)


class IndexIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    workspace_id: int = Field(gt=0)
    upload_rev: int = Field(ge=0)
    title: str = Field(min_length=1, max_length=500)
    mime: str = Field(default="application/octet-stream", max_length=255)
    ocr_used: bool = False
    archived: bool = False
    metadata: DocumentMetadataIn = Field(default_factory=DocumentMetadataIn)
    pages: list[PageIn] = Field(default_factory=list, max_length=5000)
    ai_external_allowed: bool = False


class MetadataPatchIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    workspace_id: int = Field(gt=0)
    title: str = Field(min_length=1, max_length=500)
    department_ids: list[int] = Field(default_factory=list, max_length=500)
    roles: list[str] = Field(default_factory=list, max_length=50)
    tags: list[str] = Field(default_factory=list, max_length=50)
    version: str | None = Field(default=None, max_length=64)
    effective_date: date | None = None
    approval_status: ApprovalStatus = "draft"
    archived: bool = False

    @field_validator("roles", "tags")
    @classmethod
    def _dedupe(cls, values: list[str]) -> list[str]:
        return _clean_list(values)


class ReembedIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    cursor: int = Field(default=0, ge=0)
    batch_documents: int | None = Field(default=None, ge=1, le=500)
    ai_external_allowed: bool = False


class EmbeddingsSettingsIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    provider: Literal["local", "openai-compatible", "hash"] | None = None
    model: str | None = Field(default=None, max_length=200)


class AiSettingsIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    provider: str | None = Field(default=None, max_length=64)
    model: str | None = Field(default=None, max_length=200)
    fallback_model: str | None = Field(default=None, max_length=200)
    temperature: float | None = Field(default=None, ge=0.0, le=2.0)
    embeddings: EmbeddingsSettingsIn | None = None


class ProviderKeyIn(BaseModel):
    model_config = ConfigDict(extra="ignore")

    api_key: str | None = Field(default=None, max_length=1000)

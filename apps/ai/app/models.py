from __future__ import annotations

from datetime import date, datetime
from decimal import Decimal
from typing import Any

from pgvector.sqlalchemy import Vector
from sqlalchemy import (
    BigInteger,
    Boolean,
    Computed,
    Date,
    DateTime,
    ForeignKey,
    Identity,
    Index,
    Integer,
    MetaData,
    Numeric,
    String,
    Text,
    func,
)
from sqlalchemy.dialects.postgresql import ARRAY, JSONB, TSVECTOR
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship

SCHEMA = "rag"


def _tsv_expression(config: str) -> str:
    head = "coalesce(title, '') || ' ' || coalesce(section_path, '')"
    return (
        f"setweight(to_tsvector('{config}'::regconfig, rag.f_unaccent({head})), 'A') || "
        f"setweight(to_tsvector('{config}'::regconfig, rag.f_unaccent(content)), 'B')"
    )


TSV_SIMPLE_SQL = _tsv_expression("simple")
TSV_RUSSIAN_SQL = _tsv_expression("russian")
TSV_ENGLISH_SQL = _tsv_expression("english")


class Base(DeclarativeBase):
    metadata = MetaData(
        schema=SCHEMA,
        naming_convention={
            "ix": "ix_%(table_name)s_%(column_0_N_name)s",
            "uq": "uq_%(table_name)s_%(column_0_N_name)s",
            "fk": "fk_%(table_name)s_%(column_0_name)s_%(referred_table_name)s",
            "pk": "pk_%(table_name)s",
        },
    )


class Document(Base):
    __tablename__ = "documents"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=False)
    workspace_id: Mapped[int] = mapped_column(BigInteger, index=True)
    title: Mapped[str] = mapped_column(Text)
    mime: Mapped[str] = mapped_column(String(255))
    department_ids: Mapped[list[int]] = mapped_column(ARRAY(Integer), server_default="{}", default=list)
    approval_status: Mapped[str] = mapped_column(String(16), server_default="draft", default="draft")
    archived_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    language: Mapped[str | None] = mapped_column(String(5))
    upload_rev: Mapped[int] = mapped_column(Integer, server_default="0", default=0)
    roles: Mapped[list[str]] = mapped_column(ARRAY(Text), server_default="{}", default=list)
    tags: Mapped[list[str]] = mapped_column(ARRAY(Text), server_default="{}", default=list)
    version: Mapped[str | None] = mapped_column(String(64))
    effective_date: Mapped[date | None] = mapped_column(Date)
    status: Mapped[str] = mapped_column(String(20), server_default="queued", default="queued")
    pages: Mapped[int | None] = mapped_column(Integer)
    chunks_count: Mapped[int] = mapped_column(Integer, server_default="0", default=0)
    ocr_used: Mapped[bool] = mapped_column(Boolean, server_default="false", default=False)
    error: Mapped[str | None] = mapped_column(Text)
    embedding_model: Mapped[str | None] = mapped_column(String(200))
    content_sha256: Mapped[str | None] = mapped_column(String(64))
    warnings: Mapped[list[str]] = mapped_column(JSONB, server_default="[]", default=list)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )
    indexed_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    chunks: Mapped[list[Chunk]] = relationship(
        back_populates="document", cascade="all, delete-orphan", passive_deletes=True
    )


class Chunk(Base):
    __tablename__ = "chunks"
    __table_args__ = (
        Index("ix_chunks_workspace_model", "workspace_id", "embedding_model"),
        Index("ix_chunks_document_ordinal", "document_id", "ordinal", unique=True),
        Index("ix_chunks_tsv_simple", "tsv_simple", postgresql_using="gin"),
        Index("ix_chunks_tsv_russian", "tsv_russian", postgresql_using="gin"),
        Index("ix_chunks_tsv_english", "tsv_english", postgresql_using="gin"),
        Index("ix_chunks_roles", "roles", postgresql_using="gin"),
        Index("ix_chunks_department_ids", "department_ids", postgresql_using="gin"),
        Index("ix_chunks_workspace_approval_effective", "workspace_id", "approval_status", "effective_date"),
    )

    id: Mapped[int] = mapped_column(BigInteger, Identity(always=False), primary_key=True)
    document_id: Mapped[int] = mapped_column(BigInteger, ForeignKey(f"{SCHEMA}.documents.id", ondelete="CASCADE"))
    workspace_id: Mapped[int] = mapped_column(BigInteger)
    ordinal: Mapped[int] = mapped_column(Integer)
    content: Mapped[str] = mapped_column(Text)
    title: Mapped[str] = mapped_column(Text)
    page: Mapped[int | None] = mapped_column(Integer)
    section_path: Mapped[str | None] = mapped_column(Text)
    department_ids: Mapped[list[int]] = mapped_column(ARRAY(Integer), server_default="{}", default=list)
    approval_status: Mapped[str] = mapped_column(String(16), server_default="draft", default="draft")
    archived_at: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))
    roles: Mapped[list[str]] = mapped_column(ARRAY(Text), server_default="{}", default=list)
    tags: Mapped[list[str]] = mapped_column(ARRAY(Text), server_default="{}", default=list)
    version: Mapped[str | None] = mapped_column(String(64))
    effective_date: Mapped[date | None] = mapped_column(Date)
    token_count: Mapped[int] = mapped_column(Integer, server_default="0", default=0)
    embedding: Mapped[Any] = mapped_column(Vector(), nullable=True)
    embedding_model: Mapped[str | None] = mapped_column(String(200))
    embedding_dims: Mapped[int | None] = mapped_column(Integer)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    tsv_simple: Mapped[Any] = mapped_column(TSVECTOR, Computed(TSV_SIMPLE_SQL, persisted=True))
    tsv_russian: Mapped[Any] = mapped_column(TSVECTOR, Computed(TSV_RUSSIAN_SQL, persisted=True))
    tsv_english: Mapped[Any] = mapped_column(TSVECTOR, Computed(TSV_ENGLISH_SQL, persisted=True))

    document: Mapped[Document] = relationship(back_populates="chunks")


class Setting(Base):
    __tablename__ = "settings"

    key: Mapped[str] = mapped_column(String(64), primary_key=True)
    value: Mapped[Any] = mapped_column(JSONB)
    updated_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), server_default=func.now(), onupdate=func.now()
    )


class AiUsage(Base):
    __tablename__ = "ai_usage"
    __table_args__ = (Index("ix_ai_usage_created_at", "created_at"),)

    id: Mapped[int] = mapped_column(BigInteger, Identity(always=False), primary_key=True)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    workspace_id: Mapped[int | None] = mapped_column(BigInteger, index=True)
    provider: Mapped[str] = mapped_column(String(64))
    model: Mapped[str] = mapped_column(String(200))
    purpose: Mapped[str] = mapped_column(String(64))
    prompt_tokens: Mapped[int] = mapped_column(Integer, server_default="0", default=0)
    completion_tokens: Mapped[int] = mapped_column(Integer, server_default="0", default=0)
    cached_tokens: Mapped[int] = mapped_column(Integer, server_default="0", default=0)
    cost_usd: Mapped[Decimal] = mapped_column(Numeric(14, 6), server_default="0", default=Decimal(0))
    latency_ms: Mapped[int] = mapped_column(Integer, server_default="0", default=0)
    ok: Mapped[bool] = mapped_column(Boolean, server_default="true", default=True)
    error: Mapped[str | None] = mapped_column(Text)
    request_id: Mapped[str | None] = mapped_column(String(64))


class ConversationTurn(Base):
    __tablename__ = "conversation_turns"
    __table_args__ = (Index("ix_conversation_turns_lookup", "workspace_id", "conversation_id", "id"),)

    id: Mapped[int] = mapped_column(BigInteger, Identity(always=False), primary_key=True)
    workspace_id: Mapped[int] = mapped_column(BigInteger)
    conversation_id: Mapped[str] = mapped_column(String(120))
    user_id: Mapped[int | None] = mapped_column(BigInteger)
    role: Mapped[str] = mapped_column(String(16))
    content: Mapped[str] = mapped_column(Text)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

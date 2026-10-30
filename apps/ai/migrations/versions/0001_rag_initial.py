from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op
from pgvector.sqlalchemy import Vector
from sqlalchemy.dialects import postgresql

from app.models import TSV_ENGLISH_SQL, TSV_RUSSIAN_SQL, TSV_SIMPLE_SQL

revision: str = "0001_rag_initial"
down_revision: str | None = None
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

S = "rag"


def _timestamps() -> list[sa.Column]:
    return [sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False)]


def upgrade() -> None:
    op.execute("CREATE EXTENSION IF NOT EXISTS vector")
    op.execute("CREATE EXTENSION IF NOT EXISTS unaccent")
    op.execute(f"CREATE SCHEMA IF NOT EXISTS {S}")
    op.execute(
        f"""
        CREATE OR REPLACE FUNCTION {S}.f_unaccent(text) RETURNS text
        LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
        AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        """
    )

    op.create_table(
        "documents",
        sa.Column("id", sa.BigInteger(), autoincrement=False, nullable=False),
        sa.Column("workspace_id", sa.BigInteger(), nullable=False),
        sa.Column("title", sa.Text(), nullable=False),
        sa.Column("mime", sa.String(255), nullable=False),
        sa.Column("department", sa.String(120)),
        sa.Column("roles", postgresql.ARRAY(sa.Text()), server_default="{}", nullable=False),
        sa.Column("tags", postgresql.ARRAY(sa.Text()), server_default="{}", nullable=False),
        sa.Column("version", sa.String(64)),
        sa.Column("effective_date", sa.Date()),
        sa.Column("status", sa.String(20), server_default="queued", nullable=False),
        sa.Column("pages", sa.Integer()),
        sa.Column("chunks_count", sa.Integer(), server_default="0", nullable=False),
        sa.Column("ocr_used", sa.Boolean(), server_default="false", nullable=False),
        sa.Column("error", sa.Text()),
        sa.Column("embedding_model", sa.String(200)),
        sa.Column("content_sha256", sa.String(64)),
        sa.Column("warnings", postgresql.JSONB(), server_default="[]", nullable=False),
        *_timestamps(),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("indexed_at", sa.DateTime(timezone=True)),
        sa.PrimaryKeyConstraint("id", name="pk_documents"),
        schema=S,
    )
    op.create_index("ix_documents_workspace_id", "documents", ["workspace_id"], schema=S)

    op.create_table(
        "chunks",
        sa.Column("id", sa.BigInteger(), sa.Identity(always=False), nullable=False),
        sa.Column("document_id", sa.BigInteger(), nullable=False),
        sa.Column("workspace_id", sa.BigInteger(), nullable=False),
        sa.Column("ordinal", sa.Integer(), nullable=False),
        sa.Column("content", sa.Text(), nullable=False),
        sa.Column("title", sa.Text(), nullable=False),
        sa.Column("page", sa.Integer()),
        sa.Column("section_path", sa.Text()),
        sa.Column("department", sa.String(120)),
        sa.Column("roles", postgresql.ARRAY(sa.Text()), server_default="{}", nullable=False),
        sa.Column("tags", postgresql.ARRAY(sa.Text()), server_default="{}", nullable=False),
        sa.Column("version", sa.String(64)),
        sa.Column("effective_date", sa.Date()),
        sa.Column("token_count", sa.Integer(), server_default="0", nullable=False),
        sa.Column("embedding", Vector()),
        sa.Column("embedding_model", sa.String(200)),
        sa.Column("embedding_dims", sa.Integer()),
        *_timestamps(),
        sa.Column("tsv_simple", postgresql.TSVECTOR(), sa.Computed(TSV_SIMPLE_SQL, persisted=True)),
        sa.Column("tsv_russian", postgresql.TSVECTOR(), sa.Computed(TSV_RUSSIAN_SQL, persisted=True)),
        sa.Column("tsv_english", postgresql.TSVECTOR(), sa.Computed(TSV_ENGLISH_SQL, persisted=True)),
        sa.PrimaryKeyConstraint("id", name="pk_chunks"),
        sa.ForeignKeyConstraint(
            ["document_id"], [f"{S}.documents.id"], ondelete="CASCADE", name="fk_chunks_document_id_documents"
        ),
        schema=S,
    )
    op.create_index("ix_chunks_workspace_model", "chunks", ["workspace_id", "embedding_model"], schema=S)
    op.create_index("ix_chunks_document_ordinal", "chunks", ["document_id", "ordinal"], unique=True, schema=S)
    for name in ("tsv_simple", "tsv_russian", "tsv_english", "roles"):
        op.create_index(f"ix_chunks_{name}", "chunks", [name], postgresql_using="gin", schema=S)

    op.create_table(
        "settings",
        sa.Column("key", sa.String(64), nullable=False),
        sa.Column("value", postgresql.JSONB(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.PrimaryKeyConstraint("key", name="pk_settings"),
        schema=S,
    )

    op.create_table(
        "ai_usage",
        sa.Column("id", sa.BigInteger(), sa.Identity(always=False), nullable=False),
        *_timestamps(),
        sa.Column("workspace_id", sa.BigInteger()),
        sa.Column("provider", sa.String(64), nullable=False),
        sa.Column("model", sa.String(200), nullable=False),
        sa.Column("purpose", sa.String(64), nullable=False),
        sa.Column("prompt_tokens", sa.Integer(), server_default="0", nullable=False),
        sa.Column("completion_tokens", sa.Integer(), server_default="0", nullable=False),
        sa.Column("cached_tokens", sa.Integer(), server_default="0", nullable=False),
        sa.Column("cost_usd", sa.Numeric(14, 6), server_default="0", nullable=False),
        sa.Column("latency_ms", sa.Integer(), server_default="0", nullable=False),
        sa.Column("ok", sa.Boolean(), server_default="true", nullable=False),
        sa.Column("error", sa.Text()),
        sa.Column("request_id", sa.String(64)),
        sa.PrimaryKeyConstraint("id", name="pk_ai_usage"),
        schema=S,
    )
    op.create_index("ix_ai_usage_created_at", "ai_usage", ["created_at"], schema=S)
    op.create_index("ix_ai_usage_workspace_id", "ai_usage", ["workspace_id"], schema=S)

    op.create_table(
        "conversation_turns",
        sa.Column("id", sa.BigInteger(), sa.Identity(always=False), nullable=False),
        sa.Column("workspace_id", sa.BigInteger(), nullable=False),
        sa.Column("conversation_id", sa.String(120), nullable=False),
        sa.Column("user_id", sa.BigInteger()),
        sa.Column("role", sa.String(16), nullable=False),
        sa.Column("content", sa.Text(), nullable=False),
        *_timestamps(),
        sa.PrimaryKeyConstraint("id", name="pk_conversation_turns"),
        schema=S,
    )
    op.create_index(
        "ix_conversation_turns_lookup", "conversation_turns", ["workspace_id", "conversation_id", "id"], schema=S
    )


def downgrade() -> None:
    op.drop_table("conversation_turns", schema=S)
    op.drop_table("ai_usage", schema=S)
    op.drop_table("settings", schema=S)
    op.drop_table("chunks", schema=S)
    op.drop_table("documents", schema=S)
    op.execute(f"DROP FUNCTION IF EXISTS {S}.f_unaccent(text)")

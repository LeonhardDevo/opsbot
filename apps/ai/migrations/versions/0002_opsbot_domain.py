from collections.abc import Sequence

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects import postgresql

revision: str = "0002_opsbot_domain"
down_revision: str | None = "0001_rag_initial"
branch_labels: str | Sequence[str] | None = None
depends_on: str | Sequence[str] | None = None

S = "rag"


def _int_array() -> sa.Column:
    return sa.Column(
        "department_ids", postgresql.ARRAY(sa.Integer()), server_default=sa.text("'{}'::integer[]"), nullable=False
    )


def upgrade() -> None:
    op.add_column("documents", _int_array(), schema=S)
    op.add_column(
        "documents", sa.Column("approval_status", sa.String(16), server_default="draft", nullable=False), schema=S
    )
    op.add_column("documents", sa.Column("archived_at", sa.DateTime(timezone=True)), schema=S)
    op.add_column("documents", sa.Column("language", sa.String(5)), schema=S)
    op.add_column("documents", sa.Column("upload_rev", sa.Integer(), server_default="0", nullable=False), schema=S)
    op.drop_column("documents", "department", schema=S)

    op.add_column("chunks", _int_array(), schema=S)
    op.add_column(
        "chunks", sa.Column("approval_status", sa.String(16), server_default="draft", nullable=False), schema=S
    )
    op.add_column("chunks", sa.Column("archived_at", sa.DateTime(timezone=True)), schema=S)
    op.drop_column("chunks", "department", schema=S)
    op.create_index("ix_chunks_department_ids", "chunks", ["department_ids"], postgresql_using="gin", schema=S)
    op.create_index(
        "ix_chunks_workspace_approval_effective",
        "chunks",
        ["workspace_id", "approval_status", "effective_date"],
        schema=S,
    )


def downgrade() -> None:
    op.drop_index("ix_chunks_workspace_approval_effective", table_name="chunks", schema=S)
    op.drop_index("ix_chunks_department_ids", table_name="chunks", schema=S)
    op.add_column("chunks", sa.Column("department", sa.String(120)), schema=S)
    op.drop_column("chunks", "archived_at", schema=S)
    op.drop_column("chunks", "approval_status", schema=S)
    op.drop_column("chunks", "department_ids", schema=S)

    op.add_column("documents", sa.Column("department", sa.String(120)), schema=S)
    op.drop_column("documents", "upload_rev", schema=S)
    op.drop_column("documents", "language", schema=S)
    op.drop_column("documents", "archived_at", schema=S)
    op.drop_column("documents", "approval_status", schema=S)
    op.drop_column("documents", "department_ids", schema=S)

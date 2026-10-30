from __future__ import annotations

from alembic import context
from sqlalchemy import create_engine, pool, text

from app.config import get_settings
from app.models import SCHEMA, Base

config = context.config
target_metadata = Base.metadata


def _database_url() -> str:
    x_args = context.get_x_argument(as_dictionary=True)
    return x_args.get("dburl") or get_settings().database_url


def _include_name(name: str | None, type_: str, parent_names: object) -> bool:
    if type_ == "schema":
        return name == SCHEMA
    return True


def run_migrations_offline() -> None:
    context.configure(
        url=_database_url(),
        target_metadata=target_metadata,
        literal_binds=True,
        dialect_opts={"paramstyle": "named"},
        version_table_schema=SCHEMA,
        include_schemas=True,
        include_name=_include_name,
    )
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    engine = create_engine(_database_url(), poolclass=pool.NullPool)
    with engine.connect() as connection:
        connection.execute(text(f"CREATE SCHEMA IF NOT EXISTS {SCHEMA}"))
        connection.commit()
        context.configure(
            connection=connection,
            target_metadata=target_metadata,
            version_table_schema=SCHEMA,
            include_schemas=True,
            include_name=_include_name,
            compare_type=True,
        )
        with context.begin_transaction():
            context.run_migrations()
    engine.dispose()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()

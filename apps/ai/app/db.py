from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from functools import lru_cache

from sqlalchemy import Engine, create_engine, text
from sqlalchemy.orm import Session, sessionmaker

from app.config import get_settings


def make_engine(url: str, *, pool_size: int = 10) -> Engine:
    return create_engine(
        url,
        pool_size=pool_size,
        max_overflow=pool_size,
        pool_pre_ping=True,
        pool_recycle=1800,
        future=True,
    )


@lru_cache(maxsize=1)
def get_engine() -> Engine:
    settings = get_settings()
    return make_engine(settings.database_url, pool_size=settings.db_pool_size)


def make_session_factory(engine: Engine) -> sessionmaker[Session]:
    return sessionmaker(bind=engine, expire_on_commit=False, autoflush=False)


@lru_cache(maxsize=1)
def get_session_factory() -> sessionmaker[Session]:
    return make_session_factory(get_engine())


@contextmanager
def session_scope(factory: sessionmaker[Session] | None = None) -> Iterator[Session]:
    session = (factory or get_session_factory())()
    try:
        yield session
        session.commit()
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()


def ping(engine: Engine) -> bool:
    try:
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
        return True
    except Exception:
        return False

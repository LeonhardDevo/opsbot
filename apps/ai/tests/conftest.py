from __future__ import annotations

import os
import shutil
from collections.abc import Iterator
from pathlib import Path

import pytest
from alembic import command
from alembic.config import Config
from sqlalchemy import Engine, text
from sqlalchemy.exc import OperationalError

from app.config import SERVICE_DIR, Settings
from app.db import make_engine
from app.deps import Container, build_container
from app.retrieval.indexes import forget_known_indexes
from tests.helpers import FakeWeb, SignedClient, make_settings

TEST_DATABASE_URL = os.environ.get("TEST_DATABASE_URL", "postgresql+psycopg://app:app@127.0.0.1:5442/opsbot_test")
RAG_TABLES = ("conversation_turns", "ai_usage", "settings", "chunks", "documents")

_db_state: dict[str, object] = {}


def _tesseract_available() -> bool:
    return shutil.which("tesseract") is not None


requires_tesseract = pytest.mark.skipif(not _tesseract_available(), reason="Tesseract binary not on PATH")


@pytest.fixture
def settings() -> Settings:
    return make_settings(database_url=TEST_DATABASE_URL)


@pytest.fixture(scope="session")
def db_engine() -> Iterator[Engine]:
    if "error" in _db_state:
        pytest.skip(str(_db_state["error"]))
    engine = make_engine(TEST_DATABASE_URL, pool_size=5)
    try:
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
    except OperationalError as exc:
        engine.dispose()
        _db_state["error"] = f"Postgres test database unavailable at {TEST_DATABASE_URL.split('@')[-1]}: {exc.orig}"
        pytest.skip(str(_db_state["error"]))
    config = Config(str(Path(SERVICE_DIR) / "alembic.ini"))
    config.set_main_option("script_location", str(Path(SERVICE_DIR) / "migrations"))
    config.cmd_opts = type("Opts", (), {"x": [f"dburl={TEST_DATABASE_URL}"]})()
    command.upgrade(config, "head")
    yield engine
    engine.dispose()


@pytest.fixture
def clean_db(db_engine: Engine) -> Engine:
    with db_engine.begin() as conn:
        conn.execute(text("TRUNCATE " + ", ".join(f"rag.{t}" for t in RAG_TABLES) + " RESTART IDENTITY CASCADE"))
    forget_known_indexes()
    return db_engine


@pytest.fixture
def fake_web() -> FakeWeb:
    return FakeWeb()


@pytest.fixture
def container(clean_db: Engine, settings: Settings, fake_web: FakeWeb) -> Iterator[Container]:
    built = build_container(settings, engine=clean_db, web_transport=fake_web.transport(), sleep=lambda _s: None)
    yield built
    built.web.close()


@pytest.fixture
def api(container: Container) -> Iterator[SignedClient]:
    from fastapi.testclient import TestClient

    from app.main import create_app

    app = create_app(container, warm_up=False)
    with TestClient(app) as client:
        yield SignedClient(client)

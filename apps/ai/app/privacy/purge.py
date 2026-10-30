from __future__ import annotations

from dataclasses import asdict, dataclass
from datetime import UTC, datetime, timedelta

from sqlalchemy import delete, func, select
from sqlalchemy.orm import Session, sessionmaker

from app.agent.memory import RETENTION_DAYS as MEMORY_CAP_DAYS
from app.models import AiUsage, Chunk, ConversationTurn, Document


@dataclass(frozen=True, slots=True)
class PurgeCounts:
    documents: int
    chunks: int
    conversation_turns: int
    usage_rows: int

    def to_dict(self) -> dict[str, int]:
        return asdict(self)


class WorkspacePurger:
    def __init__(self, session_factory: sessionmaker[Session]) -> None:
        self._session_factory = session_factory

    def purge(self, workspace_id: int) -> PurgeCounts:
        tables = (
            ("documents", Document),
            ("chunks", Chunk),
            ("conversation_turns", ConversationTurn),
            ("usage_rows", AiUsage),
        )
        counts: dict[str, int] = {}
        with self._session_factory() as session, session.begin():
            for name, model in tables:
                column = model.__table__.c.workspace_id
                counts[name] = int(session.scalar(select(func.count()).where(column == workspace_id)) or 0)
            for _name, model in (tables[1], tables[0], tables[2], tables[3]):
                session.execute(delete(model).where(model.__table__.c.workspace_id == workspace_id))
        return PurgeCounts(**counts)


class UserPurger:
    def __init__(self, session_factory: sessionmaker[Session]) -> None:
        self._session_factory = session_factory

    def purge(self, user_id: int) -> int:
        with self._session_factory() as session, session.begin():
            result = session.execute(delete(ConversationTurn).where(ConversationTurn.user_id == user_id))
            return int(getattr(result, "rowcount", 0) or 0)


class MemoryPruner:
    def __init__(self, session_factory: sessionmaker[Session], cap_days: int = MEMORY_CAP_DAYS) -> None:
        self._session_factory = session_factory
        self._cap_days = cap_days

    def prune(self, default_days: int, overrides: dict[int, int]) -> int:
        now = datetime.now(UTC)
        default = min(default_days, self._cap_days)
        total = 0
        with self._session_factory() as session, session.begin():
            special = [ws for ws, days in overrides.items() if min(days, self._cap_days) != default]
            query = delete(ConversationTurn).where(ConversationTurn.created_at < now - timedelta(days=default))
            if special:
                query = query.where(ConversationTurn.workspace_id.not_in(special))
            total += int(getattr(session.execute(query), "rowcount", 0) or 0)
            for workspace_id in special:
                days = min(overrides[workspace_id], self._cap_days)
                result = session.execute(
                    delete(ConversationTurn).where(
                        ConversationTurn.workspace_id == workspace_id,
                        ConversationTurn.created_at < now - timedelta(days=days),
                    )
                )
                total += int(getattr(result, "rowcount", 0) or 0)
        return total

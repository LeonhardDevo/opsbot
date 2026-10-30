from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime, timedelta

from sqlalchemy import delete, select
from sqlalchemy.orm import Session, sessionmaker

from app.models import ConversationTurn

MAX_TURN_CHARS = 4000
RETENTION_DAYS = 30


@dataclass(frozen=True, slots=True)
class Turn:
    role: str
    content: str


class ConversationMemory:
    def __init__(self, session_factory: sessionmaker[Session], max_turns: int = 6) -> None:
        self._session_factory = session_factory
        self._max_turns = max_turns

    def load(self, workspace_id: int, conversation_id: str | None, user_id: int | None) -> list[Turn]:
        if not conversation_id or self._max_turns <= 0:
            return []
        with self._session_factory() as session:
            stmt = (
                select(ConversationTurn.role, ConversationTurn.content)
                .where(
                    ConversationTurn.workspace_id == workspace_id,
                    ConversationTurn.conversation_id == conversation_id,
                    ConversationTurn.created_at > datetime.now(UTC) - timedelta(days=RETENTION_DAYS),
                )
                .order_by(ConversationTurn.id.desc())
                .limit(self._max_turns)
            )
            if user_id is not None:
                stmt = stmt.where(ConversationTurn.user_id == user_id)
            rows = session.execute(stmt).all()
        turns = [Turn(role=r.role, content=r.content) for r in reversed(rows)]
        while turns and turns[0].role != "user":
            turns.pop(0)
        return turns

    def append(
        self, workspace_id: int, conversation_id: str | None, user_id: int | None, question: str, answer: str
    ) -> None:
        if not conversation_id:
            return
        with self._session_factory() as session:
            session.add_all(
                [
                    ConversationTurn(
                        workspace_id=workspace_id,
                        conversation_id=conversation_id,
                        user_id=user_id,
                        role="user",
                        content=question[:MAX_TURN_CHARS],
                    ),
                    ConversationTurn(
                        workspace_id=workspace_id,
                        conversation_id=conversation_id,
                        user_id=user_id,
                        role="assistant",
                        content=answer[:MAX_TURN_CHARS],
                    ),
                ]
            )
            session.commit()

    def prune(self, older_than_days: int = RETENTION_DAYS) -> int:
        with self._session_factory() as session:
            result = session.execute(
                delete(ConversationTurn).where(
                    ConversationTurn.created_at < datetime.now(UTC) - timedelta(days=older_than_days)
                )
            )
            session.commit()
            return int(getattr(result, "rowcount", 0) or 0)

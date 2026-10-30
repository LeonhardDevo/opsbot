from __future__ import annotations

import re
import time
from dataclasses import dataclass, field
from datetime import date
from typing import Any, Literal

from app.actions.validate import propose_actions
from app.agent.disclaimers import apply_disclaimers, disclaimers_for
from app.agent.i18n import conflict_note, t
from app.agent.loop import AgentLoop, LoopOutcome
from app.agent.memory import ConversationMemory, Turn
from app.agent.prompts import normalize_locale, system_prompt
from app.agent.render import CitedSource, citation_payload, markdown_to_html, renumber_citations
from app.agent.sources import NOT_FOUND_MARKER, SourcePool, render_documents
from app.agent.templates import render_tool_answer
from app.clients.web import WebClient
from app.config import Settings
from app.embeddings.registry import EmbeddingsRegistry
from app.observability import CHAT_ANSWERS, get_logger
from app.privacy import is_local_provider
from app.providers.catalog import get_entry
from app.providers.manager import AiManager
from app.providers.types import AiProvider, ChatMessage, ProviderError
from app.retrieval.filters import Viewer
from app.retrieval.search import HybridRetriever
from app.text import content_terms
from app.tools.base import ChatUser, Tool, ToolContext, ToolError, ToolRegistry
from app.tools.intents import IntentMatch, match_data_intent
from app.tools.search_documents import source_from_hit

Channel = Literal["telegram", "web"]
RefusalReason = Literal["low_confidence", "not_found", "model_refusal", "unavailable"]
AnswerKind = Literal["document", "tool_data"]
ConfidenceBand = Literal["high", "medium", "low"]

FOLLOW_UP_MAX_TERMS = 4
HIGH_CONFIDENCE = 0.70
TOOL_PROVIDER = "rules"
TOOL_MODEL = "intent-template"
_MARKER_RE = re.compile(r"\s?\[\d{1,3}\]")
_QUESTION_LABEL = {"en": "Question", "ru": "Вопрос"}

log = get_logger(__name__)


@dataclass(frozen=True, slots=True)
class ChatContext:
    today: date | None = None
    timezone: str | None = None
    min_confidence: float | None = None
    ai_external_allowed: bool = False


@dataclass(frozen=True, slots=True)
class ChatInput:
    workspace_id: int
    user: ChatUser
    channel: Channel
    message: str
    conversation_id: str | None = None
    context: ChatContext = field(default_factory=ChatContext)


@dataclass(slots=True)
class ChatOutput:
    answer_markdown: str
    answer_html: str
    citations: list[dict[str, Any]]
    confidence: float
    confidence_band: ConfidenceBand
    refused: bool
    tools_used: list[str]
    actions: list[dict[str, Any]]
    provider: str
    model: str
    latency_ms: int
    conversation_id: str | None
    answer_kind: AnswerKind = "document"
    degraded: bool = False
    refusal_reason: RefusalReason | None = None
    disclaimers: list[str] = field(default_factory=list)
    external_blocked: bool = False

    def to_response(self) -> dict[str, Any]:
        return {
            "answer_markdown": self.answer_markdown,
            "answer_html": self.answer_html,
            "citations": self.citations,
            "confidence": round(self.confidence, 4),
            "confidence_band": self.confidence_band,
            "refused": self.refused,
            "refusal_reason": self.refusal_reason,
            "answer_kind": self.answer_kind,
            "disclaimers": self.disclaimers,
            "tools_used": self.tools_used,
            "actions": self.actions,
            "provider": self.provider,
            "model": self.model,
            "latency_ms": self.latency_ms,
            "conversation_id": self.conversation_id,
            "degraded": self.degraded,
            "external_blocked": self.external_blocked,
        }


def confidence_band(confidence: float, min_confidence: float, *, refused: bool) -> ConfidenceBand:
    if refused or confidence < min_confidence:
        return "low"
    return "high" if confidence >= HIGH_CONFIDENCE else "medium"


@dataclass(slots=True)
class _Request:
    req: ChatInput
    locale: str
    question: str
    started: float
    min_confidence: float
    viewer: Viewer


class ChatService:
    def __init__(
        self,
        settings: Settings,
        manager: AiManager,
        embeddings: EmbeddingsRegistry,
        retriever: HybridRetriever,
        memory: ConversationMemory,
        registry: ToolRegistry,
        web: WebClient,
    ) -> None:
        self._settings = settings
        self._manager = manager
        self._embeddings = embeddings
        self._retriever = retriever
        self._memory = memory
        self._registry = registry
        self._web = web
        self._loop = AgentLoop(manager, max_iterations=settings.agent_max_iterations)

    def answer(self, req: ChatInput) -> ChatOutput:
        context = req.context
        today = context.today or date.today()
        min_confidence = (
            context.min_confidence if context.min_confidence is not None else self._settings.rag_min_confidence
        )
        privileged = req.user.privileged or req.user.role in self._settings.privileged_roles
        state = _Request(
            req=req,
            locale=normalize_locale(req.user.locale),
            question=req.message.strip(),
            started=time.perf_counter(),
            min_confidence=min_confidence,
            viewer=Viewer(
                role=req.user.role, department_ids=tuple(req.user.department_ids), privileged=privileged, today=today
            ),
        )
        tools = self._registry.available_for(req.user)
        intent = match_data_intent(state.question, (tool.name for tool in tools))
        if intent is not None:
            tool = self._registry.get(intent.tool)
            if tool is not None and tool.data_tool:
                return self._tool_answer(state, tool, intent)
        blocked = self._external_blocked(req, self._manager.settings().provider)
        output = self._document_answer(state, tools, blocked)
        output.external_blocked = blocked
        return output

    def _tool_context(self, state: _Request, pool: SourcePool, embedder: Any) -> ToolContext:
        return ToolContext(
            workspace_id=state.req.workspace_id,
            user=state.req.user,
            viewer=state.viewer,
            retriever=self._retriever,
            embedder=embedder,
            web=self._web,
            sources=pool,
            top_k=self._settings.rag_top_k,
            public_web_url=self._settings.public_web_url,
        )

    def _tool_answer(self, state: _Request, tool: Tool, intent: IntentMatch) -> ChatOutput:
        ctx = self._tool_context(state, SourcePool(), None)
        try:
            result = tool.run(ctx, dict(intent.args))
        except ToolError as exc:
            log.info("intent_tool_rejected", tool=tool.name, error=str(exc))
            return self._refuse(state, "unavailable", 0.0, TOOL_PROVIDER, TOOL_MODEL, tools_used=[tool.name])
        except Exception as exc:
            log.warning("intent_tool_failed", tool=tool.name, error=str(exc))
            return self._refuse(state, "unavailable", 0.0, TOOL_PROVIDER, TOOL_MODEL, tools_used=[tool.name])
        answer = render_tool_answer(tool.name, result.data or {}, state.locale)
        actions = propose_actions(
            state.question, locale=state.locale, permissions=state.req.user.permissions, refused=False
        )
        return self._finish(
            state,
            answer=answer,
            citations=[],
            confidence=1.0,
            provider=TOOL_PROVIDER,
            model=TOOL_MODEL,
            answer_kind="tool_data",
            tools_used=[tool.name],
            actions=actions,
        )

    def _document_answer(self, state: _Request, tools: list[Tool], blocked: bool) -> ChatOutput:
        req = state.req
        history = self._memory.load(req.workspace_id, req.conversation_id, req.user.id)
        embedder = self._embeddings.active(
            wait=self._settings.embeddings_load_wait_seconds, external_allowed=req.context.ai_external_allowed
        )
        retrieval = self._retriever.search(
            workspace_id=req.workspace_id,
            query=self._retrieval_query(state.question, history),
            embedder=embedder,
            viewer=state.viewer,
            k=self._settings.rag_top_k,
        )
        ai = self._manager.settings()
        local_override = self._local_provider() if blocked else None
        provider_id = local_override.id if local_override is not None else ai.provider
        model_id = self._default_model(provider_id) if local_override is not None else ai.model
        degraded = local_override is not None
        if retrieval.confidence < state.min_confidence:
            return self._refuse(state, "low_confidence", retrieval.confidence, provider_id, model_id, degraded=degraded)

        pool = SourcePool()
        for hit in retrieval.hits:
            pool.add(source_from_hit(hit, origin="retrieval"))
        ctx = self._tool_context(state, pool, embedder)
        metadata = {"locale": state.locale, "question": state.question, "workspace_id": req.workspace_id}
        try:
            outcome = self._loop.run(
                self._messages(state, history, pool), tools, ctx, metadata=metadata, provider=local_override
            )
        except ProviderError as exc:
            log.warning("chat_provider_failed_degrading_to_mock", error=str(exc))
            degraded = True
            try:
                outcome = self._loop.run(
                    self._messages(state, history, pool),
                    tools,
                    ctx,
                    metadata=metadata,
                    provider=self._manager.factory.mock,
                )
            except ProviderError:
                return self._refuse(state, "unavailable", 0.0, provider_id, model_id, degraded=True)

        confidence = max(retrieval.confidence, outcome.tool_confidence)
        text = (outcome.text or "").strip()
        if outcome.finish_reason == "refusal":
            return self._refuse(state, "model_refusal", confidence, outcome.provider, outcome.model, outcome, degraded)
        if not text or NOT_FOUND_MARKER in text:
            return self._refuse(state, "not_found", confidence, outcome.provider, outcome.model, outcome, degraded)
        if confidence < state.min_confidence:
            return self._refuse(state, "low_confidence", confidence, outcome.provider, outcome.model, outcome, degraded)

        answer, cited = renumber_citations(text, pool)
        if not cited:
            return self._refuse(state, "not_found", confidence, outcome.provider, outcome.model, outcome, degraded)
        citations = [self._citation(c, state.question) for c in cited]
        cited_ids = {c["document_id"] for c in citations}
        conflicts = [c for c in retrieval.conflicts if c.winner_document_id in cited_ids]
        if conflicts:
            answer = f"{answer}\n\n{conflict_note(conflicts, state.locale)}"
        disclaimers = disclaimers_for(citations)
        answer = apply_disclaimers(answer, disclaimers, state.locale)
        actions = propose_actions(
            state.question,
            locale=state.locale,
            permissions=req.user.permissions,
            refused=False,
            citations=citations,
        )
        return self._finish(
            state,
            answer=answer,
            citations=citations,
            confidence=confidence,
            provider=outcome.provider,
            model=outcome.model,
            answer_kind="document",
            tools_used=list(outcome.tools_used),
            actions=actions,
            disclaimers=disclaimers,
            degraded=degraded,
        )

    @staticmethod
    def _external_blocked(req: ChatInput, provider_id: str) -> bool:
        return not req.context.ai_external_allowed and not is_local_provider(provider_id)

    def _local_provider(self) -> AiProvider:
        if self._settings.local_llm_provider == "ollama":
            provider = self._manager.factory.build("ollama")
            if provider is not None:
                return provider
        return self._manager.factory.mock

    @staticmethod
    def _default_model(provider_id: str) -> str:
        entry = get_entry(provider_id)
        return entry.default_model if entry is not None else provider_id

    @staticmethod
    def _citation(cited: CitedSource, question: str) -> dict[str, Any]:
        return citation_payload(cited, question)

    @staticmethod
    def _retrieval_query(question: str, history: list[Turn]) -> str:
        if len(content_terms(question)) > FOLLOW_UP_MAX_TERMS:
            return question
        previous = next((turn.content for turn in reversed(history) if turn.role == "user"), None)
        return f"{previous} {question}" if previous else question

    def _messages(self, state: _Request, history: list[Turn], pool: SourcePool) -> list[ChatMessage]:
        locale = state.locale
        today = state.viewer.today
        messages = [ChatMessage(role="system", content=system_prompt(locale, self._settings.product_name, today))]
        for turn in history:
            content = _MARKER_RE.sub("", turn.content) if turn.role == "assistant" else turn.content
            messages.append(ChatMessage(role="user" if turn.role == "user" else "assistant", content=content))
        body = f"{render_documents(pool.sources)}\n\n{_QUESTION_LABEL[locale]}: {state.question}"
        messages.append(ChatMessage(role="user", content=body))
        return messages

    def _refuse(
        self,
        state: _Request,
        reason: RefusalReason,
        confidence: float,
        provider: str,
        model: str,
        outcome: LoopOutcome | None = None,
        degraded: bool = False,
        *,
        tools_used: list[str] | None = None,
    ) -> ChatOutput:
        key = {"model_refusal": "model_refusal", "unavailable": "unavailable"}.get(reason, "refusal")
        actions = propose_actions(
            state.question,
            locale=state.locale,
            permissions=state.req.user.permissions,
            refused=True,
            refusal_reason=reason,
        )
        return self._finish(
            state,
            answer=t(key, state.locale),
            citations=[],
            confidence=confidence,
            provider=provider,
            model=model,
            answer_kind="document",
            tools_used=tools_used if tools_used is not None else (list(outcome.tools_used) if outcome else []),
            actions=actions,
            refusal=reason,
            degraded=degraded,
        )

    def _finish(
        self,
        state: _Request,
        *,
        answer: str,
        citations: list[dict[str, Any]],
        confidence: float,
        provider: str,
        model: str,
        answer_kind: AnswerKind,
        tools_used: list[str],
        actions: list[dict[str, Any]],
        refusal: RefusalReason | None = None,
        disclaimers: list[str] | None = None,
        degraded: bool = False,
    ) -> ChatOutput:
        req = state.req
        refused = refusal is not None
        band = (
            "high" if answer_kind == "tool_data" else confidence_band(confidence, state.min_confidence, refused=refused)
        )
        output = ChatOutput(
            answer_markdown=answer,
            answer_html=markdown_to_html(answer),
            citations=citations,
            confidence=confidence,
            confidence_band=band,
            refused=refused,
            tools_used=tools_used,
            actions=actions,
            provider=provider,
            model=model,
            latency_ms=int((time.perf_counter() - state.started) * 1000),
            conversation_id=req.conversation_id,
            answer_kind=answer_kind,
            degraded=degraded,
            refusal_reason=refusal,
            disclaimers=disclaimers or [],
        )
        try:
            self._memory.append(req.workspace_id, req.conversation_id, req.user.id, state.question, answer)
        except Exception as exc:
            log.warning("conversation_memory_write_failed", error=str(exc))
        CHAT_ANSWERS.labels(req.channel, str(refused).lower(), str(degraded).lower()).inc()
        return output

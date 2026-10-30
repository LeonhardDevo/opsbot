from __future__ import annotations

import time
from collections.abc import Callable
from dataclasses import dataclass, replace
from decimal import Decimal
from typing import Protocol

from sqlalchemy.orm import Session, sessionmaker

from app.models import AiUsage
from app.observability import LLM_CALLS, LLM_COST, LLM_TOKENS, get_logger
from app.privacy.redaction import is_local_provider, redact_text
from app.providers.catalog import get_entry
from app.providers.factory import ProviderFactory
from app.providers.pricing import estimate_cost_usd
from app.providers.types import (
    AiProvider,
    ChatRequest,
    ChatResult,
    ChatUsage,
    ProviderError,
    ProviderNotConfiguredError,
)
from app.security import request_id_var
from app.settings_store import AiSettings, SettingsStore

MAX_ATTEMPTS = 6
FALLBACK_AFTER_ATTEMPTS = 3
TRANSIENT_STATUSES = frozenset({500, 502, 503, 504, 529})

log = get_logger(__name__)


@dataclass(frozen=True, slots=True)
class UsageRecord:
    provider: str
    model: str
    purpose: str
    usage: ChatUsage
    cost_usd: Decimal
    latency_ms: int
    workspace_id: int | None
    ok: bool = True
    error: str | None = None
    request_id: str | None = None


class UsageRecorder(Protocol):
    def record(self, record: UsageRecord) -> None: ...


class DbUsageRecorder:
    def __init__(self, session_factory: sessionmaker[Session]) -> None:
        self._session_factory = session_factory

    def record(self, record: UsageRecord) -> None:
        with self._session_factory() as session:
            session.add(
                AiUsage(
                    workspace_id=record.workspace_id,
                    provider=record.provider,
                    model=record.model[:200],
                    purpose=record.purpose[:64],
                    prompt_tokens=record.usage.prompt_tokens,
                    completion_tokens=record.usage.completion_tokens,
                    cached_tokens=record.usage.cached_tokens,
                    cost_usd=record.cost_usd,
                    latency_ms=record.latency_ms,
                    ok=record.ok,
                    error=(record.error or None) and record.error[:2000],
                    request_id=record.request_id,
                )
            )
            session.commit()


@dataclass(slots=True)
class ManagedChatResult:
    result: ChatResult
    provider: str
    model: str
    cost_usd: Decimal
    latency_ms: int
    attempts: int

    @property
    def content(self) -> str | None:
        return self.result.content


class AiManager:
    def __init__(
        self,
        store: SettingsStore,
        factory: ProviderFactory,
        recorder: UsageRecorder,
        *,
        retry_base_seconds: float = 2.0,
        retry_max_seconds: float = 30.0,
        sleep: Callable[[float], None] = time.sleep,
        max_tokens: int = 4096,
    ) -> None:
        self._store = store
        self._factory = factory
        self._recorder = recorder
        self._retry_base = retry_base_seconds
        self._retry_max = retry_max_seconds
        self._sleep = sleep
        self._max_tokens = max_tokens

    @property
    def factory(self) -> ProviderFactory:
        return self._factory

    def settings(self) -> AiSettings:
        return self._store.ai_settings()

    def active(self) -> tuple[AiProvider, AiSettings]:
        ai = self._store.ai_settings()
        provider = self._factory.build(ai.provider)
        if provider is None:
            raise ProviderNotConfiguredError(ai.provider)
        return provider, ai

    def chat(
        self,
        req: ChatRequest,
        *,
        purpose: str,
        workspace_id: int | None = None,
        provider: AiProvider | None = None,
    ) -> ManagedChatResult:
        if provider is None:
            try:
                provider, ai = self.active()
            except ProviderNotConfiguredError as exc:
                ai = self._store.ai_settings()
                self._record_failure(
                    ai.provider, req.model or ai.model, purpose, workspace_id, time.perf_counter(), exc
                )
                raise
        else:
            ai = self._store.ai_settings()
            if provider.id != ai.provider:
                entry = get_entry(provider.id)
                model = entry.default_model if entry else ai.model
                ai = replace(ai, provider=provider.id, model=model, fallback_model=None)
        if not is_local_provider(provider.id):
            req = replace(req, messages=[replace(m, content=redact_text(m.content)) for m in req.messages])
        used_model = req.model or ai.model
        fallback = (ai.fallback_model or "").strip() or None
        temperature = req.temperature if req.temperature is not None else ai.temperature
        max_tokens = req.max_tokens or self._max_tokens
        session_id = req.session_id or (f"ws-{workspace_id}-{purpose}" if workspace_id else f"ai-{purpose}")
        started = time.perf_counter()
        attempts_on_model = 0
        attempt = 0
        while True:
            attempt += 1
            attempts_on_model += 1
            try:
                attempt_req = replace(
                    req, model=used_model, temperature=temperature, max_tokens=max_tokens, session_id=session_id
                )
                result = provider.chat(attempt_req)
                break
            except ProviderError as exc:
                status = exc.status
                if status in (403, 404) and fallback and fallback != used_model:
                    log.warning(
                        "llm_model_unavailable_fallback",
                        provider=provider.id,
                        frm=used_model,
                        to=fallback,
                        status=status,
                    )
                    used_model, attempts_on_model = fallback, 0
                    continue
                retryable = status == 429 or status is None or status in TRANSIENT_STATUSES
                if not retryable or isinstance(exc, ProviderNotConfiguredError):
                    self._record_failure(provider.id, used_model, purpose, workspace_id, started, exc)
                    raise
                if attempt >= FALLBACK_AFTER_ATTEMPTS and fallback and fallback != used_model:
                    log.warning(
                        "llm_rate_limited_fallback", provider=provider.id, frm=used_model, to=fallback, status=status
                    )
                    used_model, attempts_on_model = fallback, 0
                    continue
                transient_exhausted = status != 429 and attempts_on_model >= FALLBACK_AFTER_ATTEMPTS
                if attempt >= MAX_ATTEMPTS or transient_exhausted:
                    self._record_failure(provider.id, used_model, purpose, workspace_id, started, exc)
                    raise
                delay = self._backoff(attempts_on_model, exc.retry_after)
                log.info(
                    "llm_retry", provider=provider.id, model=used_model, status=status, attempt=attempt, delay=delay
                )
                self._sleep(delay)

        latency_ms = int((time.perf_counter() - started) * 1000)
        cost = estimate_cost_usd(used_model, result.usage)
        if result.served_model and result.served_model != used_model:
            log.debug("llm_served_model", requested=used_model, served=result.served_model)
        self._safe_record(
            UsageRecord(
                provider=provider.id,
                model=used_model,
                purpose=purpose,
                usage=result.usage,
                cost_usd=cost,
                latency_ms=latency_ms,
                workspace_id=workspace_id,
                request_id=request_id_var.get(),
            )
        )
        LLM_CALLS.labels(provider.id, used_model, purpose, "ok").inc()
        LLM_TOKENS.labels(provider.id, used_model, "prompt").inc(result.usage.prompt_tokens)
        LLM_TOKENS.labels(provider.id, used_model, "completion").inc(result.usage.completion_tokens)
        LLM_COST.labels(provider.id, used_model).inc(float(cost))
        return ManagedChatResult(
            result=result,
            provider=provider.id,
            model=used_model,
            cost_usd=cost,
            latency_ms=latency_ms,
            attempts=attempt,
        )

    def _backoff(self, attempt: int, retry_after: float | None) -> float:
        if retry_after is not None and retry_after >= 0:
            return min(self._retry_max, retry_after)
        return min(self._retry_max, self._retry_base * (2 ** (attempt - 1)))

    def _record_failure(
        self, provider_id: str, model: str, purpose: str, workspace_id: int | None, started: float, exc: Exception
    ) -> None:
        LLM_CALLS.labels(provider_id, model, purpose, "error").inc()
        self._safe_record(
            UsageRecord(
                provider=provider_id,
                model=model,
                purpose=purpose,
                usage=ChatUsage(),
                cost_usd=Decimal(0),
                latency_ms=int((time.perf_counter() - started) * 1000),
                workspace_id=workspace_id,
                ok=False,
                error=str(exc),
                request_id=request_id_var.get(),
            )
        )

    def _safe_record(self, record: UsageRecord) -> None:
        try:
            self._recorder.record(record)
        except Exception as exc:
            log.warning("ai_usage_record_failed", error=str(exc))

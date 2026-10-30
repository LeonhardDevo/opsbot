from __future__ import annotations

import logging
import sys
from collections.abc import MutableMapping
from typing import Any

import structlog
from prometheus_client import CollectorRegistry, Counter, Histogram

from app.security import request_id_var

_SECRET_KEYS = frozenset({"api_key", "authorization", "x-api-key", "secret", "password", "token"})


def _add_request_id(_: Any, __: str, event_dict: MutableMapping[str, Any]) -> MutableMapping[str, Any]:
    request_id = request_id_var.get()
    if request_id and "request_id" not in event_dict:
        event_dict["request_id"] = request_id
    return event_dict


def _redact_secrets(_: Any, __: str, event_dict: MutableMapping[str, Any]) -> MutableMapping[str, Any]:
    for key in list(event_dict):
        if key.lower() in _SECRET_KEYS:
            event_dict[key] = "[redacted]"
    return event_dict


def configure_logging(level: str = "INFO", json_output: bool = False) -> None:
    log_level = logging.getLevelNamesMapping().get(level.upper(), logging.INFO)
    logging.basicConfig(format="%(message)s", stream=sys.stderr, level=log_level, force=True)
    renderer: Any = structlog.processors.JSONRenderer() if json_output else structlog.dev.ConsoleRenderer()
    structlog.configure(
        processors=[
            structlog.contextvars.merge_contextvars,
            structlog.processors.add_log_level,
            structlog.processors.TimeStamper(fmt="iso", utc=True),
            _add_request_id,
            _redact_secrets,
            structlog.processors.StackInfoRenderer(),
            structlog.processors.format_exc_info,
            renderer,
        ],
        wrapper_class=structlog.make_filtering_bound_logger(log_level),
        logger_factory=structlog.PrintLoggerFactory(file=sys.stderr),
        cache_logger_on_first_use=False,
    )


def get_logger(name: str) -> Any:
    return structlog.get_logger(name)


REGISTRY = CollectorRegistry(auto_describe=True)

HTTP_REQUESTS = Counter(
    "ai_http_requests_total", "HTTP requests served", ["method", "route", "status"], registry=REGISTRY
)
HTTP_LATENCY = Histogram(
    "ai_http_request_duration_seconds", "HTTP request latency", ["method", "route"], registry=REGISTRY
)
LLM_CALLS = Counter(
    "ai_llm_calls_total", "LLM provider calls", ["provider", "model", "purpose", "outcome"], registry=REGISTRY
)
LLM_TOKENS = Counter("ai_llm_tokens_total", "LLM tokens", ["provider", "model", "kind"], registry=REGISTRY)
LLM_COST = Counter("ai_llm_cost_usd_total", "Estimated LLM cost (USD)", ["provider", "model"], registry=REGISTRY)
CHAT_ANSWERS = Counter("ai_chat_answers_total", "Chat answers", ["channel", "refused", "degraded"], registry=REGISTRY)
INGEST_RESULTS = Counter("ai_ingest_total", "Document ingests", ["status", "ocr_used"], registry=REGISTRY)
INGEST_DURATION = Histogram(
    "ai_ingest_duration_seconds",
    "Document ingest duration",
    registry=REGISTRY,
    buckets=(0.5, 1, 2, 5, 10, 30, 60, 120, 300),
)
RETRIEVAL_LATENCY = Histogram("ai_retrieval_duration_seconds", "Hybrid retrieval latency", registry=REGISTRY)
ACTIONS_DROPPED = Counter(
    "ai_actions_dropped_total", "Proposed actions dropped by validation", ["reason"], registry=REGISTRY
)

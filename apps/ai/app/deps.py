from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass

import httpx
from fastapi import Request
from sqlalchemy import Engine
from sqlalchemy.orm import Session, sessionmaker

from app.agent.memory import ConversationMemory
from app.agent.pipeline import ChatService
from app.clients.web import WebClient
from app.config import Settings
from app.crypto import secret_box_for
from app.db import make_engine, make_session_factory
from app.embeddings.registry import EmbeddingsRegistry
from app.ingest.chunker import Chunker
from app.ingest.extract import ExtractService
from app.ingest.index import IndexService
from app.ingest.ocr import OcrEngine
from app.ingest.parsers import DocumentParser
from app.providers.factory import ProviderFactory
from app.providers.keys import ProviderKeys
from app.providers.live_models import LiveModels
from app.providers.manager import AiManager, DbUsageRecorder, UsageRecorder
from app.retrieval.search import HybridRetriever
from app.retrieval.store import ChunkStore
from app.settings_store import SettingsStore
from app.tools import INTENT_RULES, ToolRegistry, build_registry


@dataclass(slots=True)
class Container:
    settings: Settings
    engine: Engine
    session_factory: sessionmaker[Session]
    store: SettingsStore
    keys: ProviderKeys
    factory: ProviderFactory
    manager: AiManager
    live_models: LiveModels
    embeddings: EmbeddingsRegistry
    retriever: HybridRetriever
    chunk_store: ChunkStore
    memory: ConversationMemory
    registry: ToolRegistry
    web: WebClient
    ocr: OcrEngine
    extractor: ExtractService
    indexer: IndexService
    chat: ChatService

    def close(self) -> None:
        self.web.close()
        self.engine.dispose()


def build_container(
    settings: Settings,
    *,
    engine: Engine | None = None,
    web_transport: httpx.BaseTransport | None = None,
    live_models_http: httpx.Client | None = None,
    factory: ProviderFactory | None = None,
    recorder: UsageRecorder | None = None,
    sleep: Callable[[float], None] | None = None,
) -> Container:
    engine = engine or make_engine(settings.database_url, pool_size=settings.db_pool_size)
    session_factory = make_session_factory(engine)
    store = SettingsStore(session_factory, settings)
    keys = ProviderKeys(store, settings, secret_box_for(settings))
    factory = factory or ProviderFactory(settings, keys, INTENT_RULES)
    manager_kwargs = {"sleep": sleep} if sleep is not None else {}
    manager = AiManager(
        store,
        factory,
        recorder or DbUsageRecorder(session_factory),
        retry_base_seconds=settings.ai_retry_base_seconds,
        retry_max_seconds=settings.ai_retry_max_seconds,
        max_tokens=settings.ai_max_tokens,
        **manager_kwargs,
    )
    embeddings = EmbeddingsRegistry(settings, store, keys)
    retriever = HybridRetriever(session_factory, candidates=settings.rag_candidates, rrf_k=settings.rag_rrf_k)
    chunk_store = ChunkStore(session_factory)
    memory = ConversationMemory(session_factory, max_turns=settings.rag_memory_turns)
    registry = build_registry()
    web = WebClient(settings, transport=web_transport)
    ocr = OcrEngine(settings.ocr_languages, settings.tesseract_cmd, settings.ocr_dpi, settings.ocr_timeout_seconds)
    extractor = ExtractService(DocumentParser(ocr))
    indexer = IndexService(Chunker(settings.rag_chunk_tokens, settings.rag_chunk_overlap), embeddings, chunk_store)
    chat = ChatService(settings, manager, embeddings, retriever, memory, registry, web)
    return Container(
        settings=settings,
        engine=engine,
        session_factory=session_factory,
        store=store,
        keys=keys,
        factory=factory,
        manager=manager,
        live_models=LiveModels(settings, keys, http_client=live_models_http),
        embeddings=embeddings,
        retriever=retriever,
        chunk_store=chunk_store,
        memory=memory,
        registry=registry,
        web=web,
        ocr=ocr,
        extractor=extractor,
        indexer=indexer,
        chat=chat,
    )


def get_container(request: Request) -> Container:
    container: Container = request.app.state.container
    return container

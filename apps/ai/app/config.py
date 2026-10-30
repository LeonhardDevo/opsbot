from __future__ import annotations

from functools import lru_cache
from pathlib import Path
from typing import Literal

from pydantic import Field, field_validator, model_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

APP_DIR = Path(__file__).resolve().parent
DEV_FALLBACK_SECRET = "dev-internal-secret-not-for-production"
MIN_PRODUCTION_SECRET_LENGTH = 32
SERVICE_DIR = APP_DIR.parent

EmbeddingsProviderName = Literal["local", "openai-compatible", "hash"]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=SERVICE_DIR / ".env",
        env_file_encoding="utf-8",
        extra="ignore",
        case_sensitive=False,
    )

    app_env: Literal["local", "development", "testing", "staging", "production"] = "local"
    port: int = 8202
    product_name: str = "OpsBot"
    product_slug: str = "opsbot"
    log_level: str = "INFO"
    log_json: bool = False

    database_url: str = "postgresql+psycopg://app:app@127.0.0.1:5442/opsbot"
    db_pool_size: int = 10
    db_schema: str = "rag"

    internal_api_secret: str = ""
    internal_key_web_ai: str = ""
    internal_key_worker_ai: str = ""
    internal_api_tolerance: int = 300
    service_name: str = "ai"

    web_url: str = "http://127.0.0.1:8200"
    web_public_url: str | None = None
    web_timeout_seconds: float = 30.0
    max_file_mb: int = 50

    ai_settings_key: str = ""

    ai_provider: str = "mock"
    ai_model: str = ""
    ai_fallback_model: str = ""
    ai_temperature: float = 0.2
    ai_max_tokens: int = 4096
    ai_retry_base_seconds: float = 2.0
    ai_retry_max_seconds: float = 30.0
    ai_request_timeout_seconds: float = 90.0
    anthropic_server_fallbacks: bool = True

    anthropic_api_key: str | None = None
    openai_api_key: str | None = None
    openai_base_url: str = "https://api.openai.com/v1"
    deepseek_api_key: str | None = None
    deepseek_base_url: str = "https://api.deepseek.com"
    openrouter_api_key: str | None = None
    openrouter_base_url: str = "https://openrouter.ai/api/v1"
    nvidia_api_key: str | None = None
    nvidia_base_url: str = "https://integrate.api.nvidia.com/v1"
    opencode_zen_api_key: str | None = None
    opencode_zen_base_url: str = "https://opencode.ai/zen/v1"
    opencode_go_api_key: str | None = None
    opencode_go_base_url: str = "https://opencode.ai/zen/go/v1"
    ollama_base_url: str = "http://localhost:11434/v1"
    local_llm_provider: Literal["mock", "ollama"] = "mock"

    embeddings_provider: EmbeddingsProviderName = "local"
    embeddings_model: str = ""
    embeddings_base_url: str | None = None
    embeddings_api_key: str | None = None
    embeddings_dims: int = 384
    embeddings_batch_size: int = 32
    fastembed_cache_path: str | None = None
    embeddings_load_wait_seconds: float = 15.0

    rag_min_confidence: float = Field(default=0.35, ge=0.0, le=1.0)
    rag_top_k: int = 6
    rag_candidates: int = 40
    rag_rrf_k: int = 60
    rag_chunk_tokens: int = 700
    rag_chunk_overlap: float = Field(default=0.15, ge=0.0, lt=0.5)
    rag_memory_turns: int = 6
    rag_privileged_roles: str = ""
    agent_max_iterations: int = 4

    ocr_languages: str = "rus+eng"
    tesseract_cmd: str | None = None
    ocr_dpi: int = 300
    ocr_timeout_seconds: float = Field(default=120.0, gt=0)

    @field_validator("web_url", "openai_base_url", "ollama_base_url")
    @classmethod
    def _strip_slash(cls, value: str) -> str:
        return value.rstrip("/")

    @model_validator(mode="after")
    def _production_secrets(self) -> Settings:
        if not self.is_production:
            return self
        web, worker = self.internal_key_web_ai.strip(), self.internal_key_worker_ai.strip()
        for name, value in (("INTERNAL_KEY_WEB_AI", web), ("INTERNAL_KEY_WORKER_AI", worker)):
            if len(value) < MIN_PRODUCTION_SECRET_LENGTH:
                raise ValueError(f"{name} must be set (>= {MIN_PRODUCTION_SECRET_LENGTH} chars) in production")
        if web == worker:
            raise ValueError("INTERNAL_KEY_WEB_AI and INTERNAL_KEY_WORKER_AI must differ")
        return self

    @property
    def signing_secret(self) -> str:
        return self.internal_api_secret.strip() or DEV_FALLBACK_SECRET

    def peer_secret(self, service: str) -> str | None:
        if service == "web":
            key = self.internal_key_web_ai.strip()
        elif service == "worker":
            key = self.internal_key_worker_ai.strip()
        else:
            return None
        return key or self.signing_secret

    @property
    def is_production(self) -> bool:
        return self.app_env == "production"

    @property
    def public_web_url(self) -> str:
        return (self.web_public_url or self.web_url).rstrip("/")

    @property
    def privileged_roles(self) -> frozenset[str]:
        return frozenset(r.strip() for r in self.rag_privileged_roles.split(",") if r.strip())

    @property
    def fastembed_cache_dir(self) -> Path:
        if self.fastembed_cache_path:
            return Path(self.fastembed_cache_path).expanduser()
        return Path.home() / ".cache" / "fastembed"


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()

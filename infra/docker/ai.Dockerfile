# syntax=docker/dockerfile:1.7

ARG PYTHON_IMAGE=python:3.12-slim-bookworm
ARG UV_IMAGE=ghcr.io/astral-sh/uv:0.11

FROM ${UV_IMAGE} AS uv

FROM ${PYTHON_IMAGE} AS build
COPY --from=uv /uv /uvx /usr/local/bin/
ENV UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy \
    UV_PYTHON_DOWNLOADS=never \
    UV_PROJECT_ENVIRONMENT=/app/.venv
WORKDIR /app
COPY apps/ai/pyproject.toml apps/ai/uv.lock apps/ai/.python-version ./
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --locked --no-dev --no-install-project
COPY apps/ai/ ./
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --locked --no-dev

FROM ${PYTHON_IMAGE} AS runtime
ARG APP_UID=10001
ARG PRELOAD_EMBEDDINGS=0
ARG EMBEDDINGS_MODEL=sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2
RUN apt-get update \
    && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-eng tesseract-ocr-rus libgomp1 \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid "${APP_UID}" --user-group --create-home --home-dir /home/app --shell /usr/sbin/nologin app

ENV PATH=/app/.venv/bin:$PATH \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    APP_ENV=production \
    PORT=8202 \
    LOG_JSON=true \
    OCR_LANGUAGES=rus+eng \
    FASTEMBED_CACHE_PATH=/home/app/.cache/fastembed
WORKDIR /app
COPY --from=build --chown=app:app /app /app

USER app
RUN mkdir -p "$FASTEMBED_CACHE_PATH" \
    && if [ "$PRELOAD_EMBEDDINGS" = "1" ]; then \
         EMBEDDINGS_MODEL="$EMBEDDINGS_MODEL" python -c "import os; from fastembed import TextEmbedding; TextEmbedding(os.environ['EMBEDDINGS_MODEL'], cache_dir=os.environ['FASTEMBED_CACHE_PATH'])"; \
       fi

EXPOSE 8202
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
    CMD ["python", "-c", "import os, sys, urllib.request; urllib.request.urlopen('http://127.0.0.1:%s/healthz' % os.environ.get('PORT', '8202'), timeout=4); sys.exit(0)"]

CMD ["sh", "-c", "exec uvicorn app.main:app --host 0.0.0.0 --port \"${PORT}\" --proxy-headers --forwarded-allow-ips='*' --workers \"${WEB_CONCURRENCY:-2}\" --no-server-header"]

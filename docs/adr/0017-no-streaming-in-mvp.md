# ADR-0017 — No token streaming in the MVP

- Status: accepted
- Date: 2026-10-22
- Related: FOUNDATION_CONTRACT `/assistant/ask`, PLATFORM §4.4 `/v1/chat`, design-system `pages/assistant.md`

## Context

The generated assistant page spec asked for token-by-token streaming, but `/assistant/ask` and ai
`/v1/chat` return one JSON response, and Telegram cannot stream either (editing a message repeatedly
hits rate limits and breaks HTML parsing mid-tag).

## Decision

- MVP: no streaming anywhere. Web shows a typing indicator (three dots; static "Thinking…" text when
  `prefers-reduced-motion: reduce`), then renders the whole answer at once with citation chips, the
  confidence meter and action buttons. Telegram shows `sendChatAction('typing')` every 4.5 s (foundation).
- If streaming is wanted later, it needs a new ADR adding `POST /v1/chat/stream` (SSE) for the web
  channel only; the JSON contract stays the source of truth for Telegram and logging.

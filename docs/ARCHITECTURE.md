# OpsBot — Architecture

> Companion to `DOMAIN_CONTRACT.md` (names, payloads) and `adr/*` (decisions). Service boundaries and
> shared mechanics (signing, outbox, provider manager) come from `PLATFORM.md`.

## 1. Context

```mermaid
flowchart LR
  subgraph Company["Customer company"]
    EMP["Field employee<br/>(Telegram only)"]
    MGR["Manager<br/>(web + Telegram)"]
    ADM["Owner / admin<br/>(web)"]
  end
  TG["Telegram Bot API<br/>(company's own bot)"]
  OPS["OpsBot<br/>web · worker · ai"]
  STRIPE["Stripe (primary) / YooKassa (RUB)<br/>(optional, fake driver offline)"]
  LLM["LLM provider<br/>(optional, mock default)"]
  SMTP["SMTP / Mailpit"]

  EMP <-->|questions, incidents, tasks| TG
  MGR <-->|alerts, answers| TG
  TG <-->|webhook / Bot API| OPS
  ADM -->|browser| OPS
  MGR -->|browser| OPS
  EMP -.->|signed source link, 30 min| OPS
  OPS -->|checkout, portal, webhooks| STRIPE
  OPS -->|chat completions, only if enabled| LLM
  OPS -->|e-mail fallback| SMTP
```

## 2. Containers and data ownership

```mermaid
flowchart TB
  subgraph web["web — Laravel 13 + Inertia + Vue (port 8200)"]
    UI["Inertia pages<br/>admin, viewer, marketing"]
    DOM["Domain services<br/>employees, bots, documents, incidents,<br/>tasks, routing, actions, analytics"]
    INT["/internal/v1/* (signed)"]
    OBX[("public.outbox_messages")]
  end
  subgraph worker["worker — Node 24 + BullMQ + grammY + Fastify (port 8201)"]
    REL["Outbox relay<br/>LISTEN outbox + poll"]
    REG["BotRegistry<br/>one grammY Bot per bot_key"]
    SND["TelegramSender<br/>per-bot / per-chat buckets"]
    Q["Queues: telegram, telegram-updates,<br/>telegram-admin, documents, emails,<br/>stripe-events, opsbot-schedules"]
    EXT["document.extract<br/>pdfjs · mammoth · tesseract rus+eng<br/>(sandboxed child, no secrets)"]
    WH["/webhooks/telegram/{bot_key}<br/>/webhooks/stripe<br/>/internal/v1/telegram/bots/{id}/verify"]
  end
  subgraph ai["ai — FastAPI + pgvector (port 8202)"]
    CHAT["/v1/chat<br/>retrieval + gate + tools + actions"]
    ING["/v1/documents/*<br/>index pages → chunks · embeddings · metadata"]
  end
  PG[("Postgres 17 + pgvector<br/>schemas: public · worker · rag")]
  RDS[("Redis 7<br/>BullMQ, caches, locks, buckets")]

  UI --> DOM --> OBX
  OBX --> REL --> Q
  Q --> SND --> REG
  WH --> Q
  Q -->|signed HTTP| INT
  Q -->|signed HTTP| CHAT
  Q --> EXT
  EXT -->|file fetch, progress, signed| INT
  Q -->|document.index, signed| ING
  CHAT -->|tools, signed| INT
  DOM -->|sync verify, signed| WH
  web --- PG
  worker --- PG
  ai --- PG
  worker --- RDS
```

| Schema / store | Owner | Contents |
|---|---|---|
| `public` | web | users, workspaces, roles, departments, employees & links, telegram_bots (encrypted token), telegram_accounts, documents (approval), incidents, tasks, reminders, routing, unanswered questions, ai_queries, actions, billing, audit, outbox |
| `worker` | worker | webhook_events, telegram_chats `(bot_key, telegram_user_id)`, telegram_group_notices |
| `rag` | ai | documents mirror (ACL + approval columns, `upload_rev`), chunks + embeddings + tsvectors, settings, ai_usage, conversation_turns |
| Redis | worker | BullMQ, extracted text (24 h), account cache, stored actions, flows, reply state, poll locks, rate buckets, registry pub/sub |

## 3. Key flows

### 3.1 Employee asks a question (Telegram)

```mermaid
sequenceDiagram
  autonumber
  participant E as Employee (Telegram)
  participant T as Telegram
  participant W as worker
  participant WEB as web /internal
  participant AI as ai /v1/chat
  E->>T: "I scratched a client's table, what do I do?"
  T->>W: POST /webhooks/telegram/{bot_key} (secret header)
  W->>W: enqueue telegram-updates jobId tg-update-{bot_key}-{update_id}
  W->>WEB: GET telegram/context?bot_key&telegram_user_id (cache 60 s)
  WEB-->>W: user, role, department_ids, today, min_confidence, notice?, subscription_state
  alt notice pending / paused / limit exhausted / group chat
    W-->>T: notice | paused text + "Report incident" (incidents/tasks keep working) | limit text + escalate | private-only notice
  else normal
    W->>AI: POST /v1/chat {user{department_ids, privileged}, context{today, min_confidence}}
    AI->>AI: intent rules (RU/EN) → templated tool-data answer, else hybrid retrieval (ACL + approved + effective) → gate (refuse before any LLM call) → agent (read-only tools) → post-gate (citations required)
    AI-->>W: answer_html, citations{document_id, chunk_id}, actions[], disclaimers
    W->>WEB: POST ai-queries (log + actions)
    WEB-->>W: {id, actions[{id,type,label,url}], citations[{index,url (signed 30 min)}]}
    W-->>T: sendMessage (sanitized HTML, sources, buttons) via per-bot buckets
  end
```

### 3.2 Proposed action → incident (human confirms)

```mermaid
sequenceDiagram
  autonumber
  participant E as Employee
  participant W as worker
  participant WEB as web
  participant M as Manager (Telegram)
  E->>W: tap "Create incident" (callback a:{id})
  W->>WEB: POST telegram/actions {type:'opsbot.action', action_id, step:'preview'}
  WEB-->>W: confirmation card + [Confirm][Cancel]
  E->>W: tap Confirm
  W->>WEB: POST telegram/actions {step:'confirm'}
  WEB->>WEB: lock action row · permission · idempotency · RoutingResolver
  WEB->>WEB: incident № N + incident_events + audit + outbox opsbot.incident.created
  WEB-->>W: "Incident № N created, responsible: Oleg"
  WEB--)W: outbox relay → telegram queue
  W-->>M: "New incident № N" [Take][Open]
```

### 3.3 Escalation round trip

```mermaid
sequenceDiagram
  autonumber
  participant E as Employee
  participant W as worker
  participant WEB as web
  participant M as Manager
  Note over E,W: answer refused (confidence < threshold)
  E->>W: tap "Pass to manager"
  W->>WEB: telegram/actions confirm(escalate)
  WEB->>WEB: unanswered_questions (unique per ai_query) · routing 'question'
  WEB--)W: outbox opsbot.question.escalated
  W-->>M: question Q-15 [Answer][Open in web][Dismiss]
  M->>W: tap Answer
  W-->>M: force_reply prompt (Redis reply state 30 min)
  M->>W: reply with text
  W->>WEB: POST opsbot/questions/15/answer
  WEB--)W: outbox opsbot.question.answered
  W-->>E: "Manager's answer (not from the regulations)" + text
  M->>WEB: Convert to FAQ → draft document → admin approval required
```

### 3.4 Connecting the company bot

```mermaid
sequenceDiagram
  autonumber
  participant A as Admin (browser)
  participant WEB as web
  participant W as worker
  participant T as Telegram
  A->>WEB: POST /bot {token}
  WEB->>WEB: format check · tg_bot_id unique (live) · save encrypted, status connecting
  WEB->>W: POST /internal/v1/telegram/bots/{id}/verify (signed, 15 s)
  W->>WEB: GET /internal/v1/telegram/bots/{id}/credentials (signed)
  W->>T: getMe → setWebhook(/webhooks/telegram/{bot_key}, secret) → setMyCommands(ru, en)
  W-->>WEB: {ok, tg_bot_id, username, mode} | {ok:false, error_code}
  WEB-->>A: status pill Active / localized error
  Note over W: token only in process memory, logs redacted
```

### 3.5 Employee onboarding

```mermaid
sequenceDiagram
  autonumber
  participant D as Dispatcher (web)
  participant WEB as web
  participant E as Employee
  participant W as worker
  D->>WEB: POST /employees (name, departments) — seat check
  D->>WEB: POST /employees/{id}/link
  WEB-->>D: t.me/<bot>?start=emp_<token> (QR, 7 days)
  E->>W: /start emp_<token>
  W->>WEB: POST telegram/link {bot_key, token, telegram_user_id}
  WEB->>WEB: verify hash · bot_key matches · unused · conflict check → active
  WEB-->>W: notice_required
  W-->>E: employee notice [I understand]
  E->>W: tap → POST telegram/notice/ack → legal_acceptances
```

### 3.6 Document ingest and approval

```mermaid
sequenceDiagram
  autonumber
  participant A as Admin
  participant WEB as web
  participant W as worker
  participant AI as ai
  A->>WEB: upload (draft, status queued, upload_rev++)
  WEB--)W: outbox document.uploaded {upload_rev, limits}
  W->>WEB: GET /internal/v1/documents/{id}/file
  W->>W: document.extract — sandboxed child: pdfjs / mammoth / tesseract rus+eng, page limit
  W->>WEB: status processing {pages_done, pages_total} (every 5 pages)
  W->>AI: document.index → POST /v1/documents/{id}/index {upload_rev, pages[]}
  AI->>AI: chunk · embed · replace chunks in one tx (idempotent per rev)
  AI-->>W: {pages, chunks}
  W->>WEB: status indexed (or failed + error_code after BullMQ retries)
  A->>WEB: Approve (effective_date, review_due_at, supersedes?)
  WEB--)W: outbox document.metadata_changed
  W->>AI: PATCH /v1/documents/{id}/metadata (approval_status=approved, no re-embed)
```

### 3.7 Task reminders

```mermaid
sequenceDiagram
  autonumber
  participant S as worker scheduler
  participant WEB as web
  participant W as worker telegram queue
  participant U as Assignee
  Note over WEB: task create/update → TaskReminderPlanner → task_reminders (unique task_id, kind), quiet hours shifted
  loop every 60 s
    S->>WEB: POST opsbot/reminders/dispatch
    WEB->>WEB: SELECT planned ≤ now FOR UPDATE SKIP LOCKED · re-read task · skip done/cancelled
    WEB--)W: outbox opsbot.task.reminder_due / opsbot.task.escalated
  end
  W-->>U: reminder [Done]
```

## 4. Cross-cutting

- **Security:** HMAC-signed internal calls (PLATFORM §4.1); bot tokens encrypted and confined to the
  credentials endpoint (ADR-0003); signed share links (ADR-0010); CSP `default-src 'self'`; private chats only.
- **Idempotency:** outbox job id = outbox id; Telegram update job id includes `bot_key`; action execution
  locked per `ai_query_actions` row; reminders unique per `(task_id, kind)`; extraction/indexing jobs keyed by `(document_id, upload_rev)`, stale revisions rejected.
- **Observability:** request id end to end; worker metrics per queue and per bot class; ai metrics for
  retrieval latency, refusals, dropped actions; audit log for every security-relevant action.
- **Offline-first:** fake Telegram transport (`TEST` tokens), fake billing, `mock` LLM, `hash`/`local`
  embeddings — the full E2E runs without network.

## 5. Deployment

`docker-compose.yml` (dev infra: pgvector 17, Redis 7, Mailpit on 5442/6392/1032+8231) and
`docker-compose.prod.yml` (web + worker + ai + scheduler). Production uses `TELEGRAM_MODE=webhook` with
`WORKER_PUBLIC_URL` on HTTPS; the worker can run several replicas (webhooks are stateless; polling is
dev-only under leader locks); document extraction/OCR runs in the worker (`documents` queue, extract
concurrency 2 per replica; the worker image installs `tesseract-ocr` with `rus` + `eng`; locally
`TESSDATA_PREFIX` points to a user-writable directory with `rus.traineddata`, ADR-0008 §8); the ai image
has no OCR dependencies. Dev compose adds `stripe/stripe-mock` on 12121 (PLATFORM §11.2). Stack per
PLATFORM §0.10 and the foundation baseline: ADR-0019.

## 6. ADR index

| ADR | Title |
|---|---|
| [0001](adr/0001-vertical-first-cleaning.md) | Vertical-first: cleaning & field service, starter pack |
| [0002](adr/0002-multi-bot-registry.md) | One Telegram bot per company: the multi-bot registry |
| [0003](adr/0003-bot-token-custody-and-connect-flow.md) | Bot token custody, connect / rotate / disconnect |
| [0004](adr/0004-telegram-only-employees.md) | Employees who exist only in Telegram; seats |
| [0005](adr/0005-document-approval-lifecycle.md) | Only approved, effective documents are answerable |
| [0006](adr/0006-confidence-gate.md) | Deterministic confidence gate and the golden set |
| [0007](adr/0007-ai-proposes-humans-execute.md) | The AI proposes actions; a human tap executes them |
| [0008](adr/0008-async-ingest-and-ocr.md) | Document processing + OCR in the worker (BullMQ); indexing in FastAPI |
| [0009](adr/0009-department-role-acl.md) | Department & role ACL by ids, cache invalidation |
| [0010](adr/0010-signed-citation-links.md) | Signed, short-lived source links bound to the employee |
| [0011](adr/0011-private-chats-only.md) | The bot answers only in private chats |
| [0012](adr/0012-plans-limits-and-grace.md) | Plans by seats and documents; over-limit; past due |
| [0013](adr/0013-reminders-and-quiet-hours.md) | Task reminders and quiet hours |
| [0014](adr/0014-escalation-round-trip.md) | Escalation round trip; manager answers are not policy |
| [0015](adr/0015-employee-privacy-and-query-visibility.md) | Question logging visibility and the employee notice |
| [0016](adr/0016-deterministic-analytics.md) | Deterministic analytics |
| [0017](adr/0017-no-streaming-in-mvp.md) | No token streaming in the MVP |
| [0018](adr/0018-notifications-rate-limits-and-polling.md) | Notification topics, e-mail fallback, per-bot limits, polling lock |
| [0019](adr/0019-foundation-baseline-and-strict-stack.md) | Foundation pinned to payflow-guard `002cb4e`; strict stack; single signing key |

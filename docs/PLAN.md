# OpsBot — Product & Production Plan

> Status: approved for build (M1+) after plan reviews 1 and 2 of 2026-10-22 (resolution maps in §13).
> Binding companions: `PLATFORM.md` (shared blueprint; wins unless overridden explicitly),
> `docs/FOUNDATION_CONTRACT.md` (arrives with the foundation code clone), `docs/DOMAIN_CONTRACT.md`
> (precise build spec — routes, props, tables, topics, tools), `docs/ARCHITECTURE.md` + `docs/adr/*`
> (diagrams and decisions), `design-system/opsbot/MASTER.md` + `pages/*` (brand and screen specs),
> `docs/seed-documents/` (Cleaning starter pack).

---

## 1. Vision

**Every field employee gets the right answer from the company's own approved rules in seconds — in
Telegram — and every incident reaches the right manager.**

OpsBot is an operations assistant for service companies whose staff work away from a desk. The company
connects **its own Telegram bot**, uploads its regulations (PDF, DOCX, scans with OCR), approves them, and
invites employees with a QR code. Employees ask "what do I do if…" questions; the bot answers **only from
approved, effective documents**, cites and links the source, refuses honestly when unsure and passes the
question to a manager. From the same chat an employee can report an incident or propose a task that is
routed to the responsible manager. Managers get reminders, an unanswered-questions queue and simple,
deterministic analytics in a bilingual web admin.

RU one-liner for the landing: «Регламенты компании — в Telegram у каждого сотрудника. Ответ со ссылкой
на документ, инцидент — сразу ответственному менеджеру».

## 2. Positioning

The idea itself states the risk: a generic version loses to Notion AI, Glean, ChatGPT (Team/Enterprise)
and Slack/Teams assistants. We do not compete for office knowledge workers (ADR-0001).

| Need of a cleaning / field-service company | Office AI search (Notion AI, Glean, ChatGPT, Slack AI) | Deskless workforce apps (shift/communication suites) | **OpsBot** |
|---|---|---|---|
| Staff without corporate e-mail or SaaS accounts | Requires accounts, licences, onboarding into the suite | Own mobile app to install | **Telegram-only employees**, QR invite, no app, no e-mail (ADR-0004) |
| Answer only from *approved, current* rules | Indexes everything the user can see; no approval lifecycle | Static handbook pages | **Draft → approved → archived, effective dates, review reminders** (ADR-0005) |
| Admit "I don't know" and route to a manager | Tends to answer anyway | — | **Deterministic confidence gate + escalation round trip** (ADR-0006, ADR-0014) |
| Turn a question into an incident or task for the right manager | — | Forms, separate flows | **One tap from the answer**, routing rules by department × category (ADR-0007) |
| Department/role visibility | Workspace permissions | Groups | **Department × role ACL** enforced in retrieval (ADR-0009) |
| Starts useful on day one | Empty until content exists | Templates | **Cleaning starter pack** (8 regulations, RU+EN) (ADR-0001) |
| Price for 10–200 field staff | Per knowledge-worker seat | Per user | **Seat bands from $29/month** (§7) |

Positioning statement: *For cleaning and facility-service companies with 10–200 field staff who
coordinate in Telegram, OpsBot is the company's own Telegram assistant that answers only from approved
regulations with a source link and hands everything else to the right manager. Unlike office AI search it
needs no accounts or apps for staff and refuses rather than improvises; unlike handbook apps it turns
questions into incidents and tasks.*

## 3. Ideal customer profile, personas, jobs to be done

**ICP:** residential and commercial cleaning companies, facility-service and similar field-service
operators; 10–200 field staff in 1–5 teams/departments; 1–10 office staff (owner, operations managers,
dispatcher); Russian- and/or English-speaking; already coordinate shifts in Telegram chats; regulations
exist as scattered PDFs/DOCX/photos of printed sheets.

| Persona | Role (kind) | Pains | Jobs to be done |
|---|---|---|---|
| **Marina — owner / director** | `owner` (web) | The same questions all day; incidents learned about too late; outdated rules circulating as photos | "Make the rules findable and current, and show me what goes wrong." |
| **Oleg — operations manager** | `manager` (web + Telegram) | Phone rings during visits; no record who was told what; damage reported hours later | "Get incidents and hard questions to me immediately with context, and remind people about their tasks." |
| **Daria — office admin / dispatcher** | `admin` (web) | Onboarding new cleaners, printing instructions, chasing keys | "Onboard a cleaner in two minutes and keep documents up to date." |
| **Aigul — cleaner** | `employee` (telegram_only) | Afraid of doing the wrong thing at a client's home; no one answers the phone; no e-mail account | "Tell me what to do right now, in my language, and let me report a problem in one tap." |
| **Maria — cleaner (English-speaking)** | `employee` (telegram_only, en) | Russian-only instructions | "Answer me in English from the same rules." |

## 4. Problem evidence and assumptions to validate

| Statement | Basis | Status / how we use it |
|---|---|---|
| Field staff ask repetitive operational questions and managers answer them by phone | Idea brief; typical cleaning operations | Assumption → validate in beta (questions/employee/week, % answered without a manager) |
| Incidents (damage, no access, complaints) are reported late or inconsistently | Idea brief | Assumption → measure median time from visit to incident in beta |
| Generic AI tools are not adopted by deskless staff (accounts, language, trust) | Idea brief (Notion AI, Glean, ChatGPT named as risk) | Positioning decision ADR-0001 |
| Outdated/conflicting regulations cause wrong actions | Idea brief (risk list) | Approval lifecycle ADR-0005, conflict note in answers |
| Telegram is the default work messenger for the RU-speaking ICP | Market knowledge; must be confirmed per beta customer | Telegram-first; e-mail fallback for web users |

No third-party statistics are quoted on the landing until a source is verified (open item in §10, R12).

## 5. Scope

Legend: **MVP** = production-grade, demoable end-to-end (M1–M6). **v1** = next 3 months. **v2** = later.

### 5.1 Knowledge
| Capability | MVP | v1 | v2 |
|---|---|---|---|
| Upload PDF/DOCX/TXT/MD/HTML/images; extraction + OCR `rus+eng` as BullMQ jobs in the worker, indexing in FastAPI, progress per page (ADR-0008) | yes | Google Drive / OneDrive import | Confluence/Notion sync |
| Approval lifecycle, effective dates, supersede, review reminders (ADR-0005) | yes | Approval by department managers | Multi-step approval |
| Department × role visibility (ADR-0009) | yes | Per-employee exceptions | |
| Starter pack "Cleaning" RU+EN, imported as drafts (ADR-0001) | yes | Packs: facility maintenance, couriers | Pack marketplace |
| Test a question against a draft before approving | yes | | |
| Source links: signed 30-min viewer with highlighted excerpt (ADR-0010) | yes | "Get file" via `sendDocument` (opt-in) | |

### 5.2 Assistant (Telegram + web preview)
| Capability | MVP | v1 | v2 |
|---|---|---|---|
| Company's own bot: connect / rotate / disconnect, multi-bot worker (ADR-0002/0003) | yes | Bot profile photo/description from the admin | |
| Employees without e-mail: QR/deep-link onboarding, offboarding (ADR-0004) | yes | Bulk import from CSV | HR system sync |
| Answers with citations, deterministic confidence gate, refusal + escalate (ADR-0006) | yes | Per-department thresholds | |
| Proposed actions: incident, task, escalate, open source — human confirms (ADR-0007) | yes | Photo attachments on incidents | Voice messages (speech-to-text) |
| "Who handles this?" from routing rules | yes | | |
| Escalation round trip with force-reply answers, FAQ draft conversion (ADR-0014) | yes | Answer templates | |
| Commands `/history /tasks /incident /ask_manager /queue /privacy /unlink` | yes | | |
| Private chats only; group notice (ADR-0011) | yes | | Group digests (opt-in, no answers) |
| Streaming answers | — (ADR-0017) | web only (SSE) | |

### 5.3 Operations
| Capability | MVP | v1 | v2 |
|---|---|---|---|
| Incidents: categories, severity, status flow, numbering, timeline, comments, tasks from incident | yes | Client-facing incident report PDF | Client portal |
| Tasks: board + table, due dates, assignment, reminders with quiet hours, overdue escalation (ADR-0013) | yes | Recurring tasks, checklists | Shift scheduling integration |
| Routing rules department × category → manager | yes | Working-hours-aware routing | |
| Unanswered questions queue with deterministic grouping | yes | | |
| Analytics: questions/day, answered/refused/escalated %, latency, top cited, top unanswered, never-cited, incidents, tasks (ADR-0016) | yes | Nightly aggregates, CSV export | Benchmarks across tenants (opt-in) |

### 5.4 Platform features used (foundation)
Workspaces & roles (extended), billing fake/Stripe with limits, outbox → worker, internal API,
Telegram linking (multi-bot), KB + RAG + bot preview, AI admin with `mock` default, audit log, legal pack
(F2) + OpsBot employee notice, payments & markets (F3: `BillingGateway` stripe/yookassa/fake), marketing
routes (landing, pricing, security) in RU/EN, light/dark — with OpsBot's own UI on top (§5.6).

### 5.5 Stack (PLATFORM §0.10, strict) and foundation baseline (ADR-0019)
| Brief component | Where | Notes |
|---|---|---|
| Laravel + Vue SaaS | `apps/web` (Laravel 13, Inertia, Vue 3, shadcn-vue) | business rules, billing state, UI |
| Telegram bot with RAG | bot in `apps/worker` (grammY, multi-bot registry — each company connects its own bot token, ADR-0002/0003); answers from `apps/ai` | |
| FastAPI + PostgreSQL + pgvector | `apps/ai` | chunking, embeddings, hybrid search, confidence gate, tools, answers |
| Node.js + BullMQ/Redis | `apps/worker` | outbox relay, notifications, reminders, document extraction + OCR, indexing and re-embedding jobs (ADR-0008) |
| Playwright | `e2e` (+ axe) | |

The foundation is copied once from payflow-guard commit `002cb4e` (which still has `apps/ai`); OpsBot then
owns all three apps and never takes PayFlow's later Node RAG port, `WORKER_ROLES` or per-pair keys.
Precise extension points (routes, permissions, bot modules, queues, AI tool registry):
DOMAIN_CONTRACT §0.3.

### 5.6 Own UI (PLATFORM §0.10, §9)
OpsBot has its own layout language, not a recolor of PayFlow/ScamPause: a messenger-style icon rail + a
persistent "Live desk" conversation column, bot-status top bar, bottom tab bar on phones, a "today" feed
dashboard and a scroll-driven chat-story landing (MASTER §4.1, `pages/*`).

## 6. MVP definition

Without any third-party keys (fake Telegram transport, fake billing, `mock` LLM, `hash`/`local`
embeddings), a new cleaning company can:
1. Register (RU or EN), create the workspace, accept the DPA.
2. Connect its bot (test token offline, real token online) and see it "Active".
3. Import the Cleaning starter pack or upload its own PDF, watch processing, review and approve.
4. Create employees, print QR links; an employee opens the link, acknowledges the notice.
5. The employee asks "I scratched a client's table, what do I do?" and gets a cited answer with a source
   link that opens a highlighted excerpt — and a "Create incident" button.
6. The incident lands with the responsible manager in Telegram; the manager takes it and creates a task
   with a due date; reminders respect quiet hours.
7. A question outside the documents is refused and escalated; the manager answers by replying in Telegram;
   the employee receives the labelled manager answer; the manager converts it to an FAQ draft.
8. The owner sees analytics and the audit trail, sees plan usage (seats, documents, AI questions) on the
   billing page, and upgrades the plan through the fake gateway (Stripe-shaped checkout) with
   the webhook verified by the worker.
With Telegram/Stripe/LLM keys configured, the same flows run against the real services.

## 7. Pricing & limits (canonical values: DOMAIN_CONTRACT §11, ADR-0012)

| | free | starter | **pro** | business |
|---|---|---|---|---|
| Price / month, USD · EUR · RUB (yearly = 10×) | 0 | $29 · €27 · 2 490 ₽ | $79 · €73 · 6 490 ₽ | $199 · €185 · 15 990 ₽ |
| Seats (web members + employees) | 5 | 25 | 75 | 250 |
| Approved + draft documents | 15 | 100 | 500 | 2000 |
| AI questions / month | 300 | 3 000 | 15 000 | 60 000 |
| Max pages per document (OCR) | 30 | 150 | 300 | 500 |

Over limit blocks only creation; existing content stays answerable; past-due has a 7-day grace, then the
bot pauses **AI answers only** (incidents, tasks, reminders and notifications keep working). Payments
follow PLATFORM §11: the market chosen at onboarding sets currency and gateway — Stripe (primary, USD/EUR,
fully implemented in test mode, `stripe-mock` 12121 in tests), YooKassa (RUB readiness), `fake` offline
and in E2E. Pricing pages show the visitor's market currency. EUR/RUB values are placeholders to confirm
before launch. 14-day Pro trial. No SSO claim. Only SaaS subscriptions are billed (no customer-collected
payments in OpsBot).

## 8. Success metrics

| Area | Metric | Target (first 90 days after beta) |
|---|---|---|
| North star | Questions answered from approved documents per active employee per week | ≥ 3 |
| Answer quality | Non-refused **document** answers with ≥ 1 citation (templated tool-data answers name their source instead, ADR-0006) | 100 % (invariant) |
| Answer quality | Refusal rate on sampled in-scope questions | < 15 % |
| Answer quality | Golden set pass rate (CI) | 100 % |
| Escalation | Median time from escalation to manager answer | < 2 h in working hours |
| Incidents | Median time from incident creation to "in progress" | < 30 min |
| Tasks | Tasks completed on time | ≥ 80 % |
| Knowledge hygiene | Approved documents past review date | < 10 % |
| Activation | Company bot connected within 24 h of signup | ≥ 60 % |
| Activation | ≥ 5 employees linked within 7 days | ≥ 50 % |
| Activation | ≥ 20 questions in the first 7 days | ≥ 50 % |
| Reliability | Telegram answer p95 latency (mock/local) / (cloud LLM) | < 3 s / < 10 s |
| Reliability | Reminder sent within 2 min of planned time (outside quiet hours) | ≥ 99 % |
| Business | Trial → paid | ≥ 8 % |
| Business | Logo churn | < 3 % / month |

Instrumentation: `ai_queries`, `unanswered_questions`, `incidents`, `incident_events`, `tasks`,
`task_reminders`, `deliveries`, audit log; KPIs per DOMAIN_CONTRACT §13.

## 9. Milestones

| Milestone | Content | Exit criteria |
|---|---|---|
| **M0 — Foundation copy** | Copy the foundation from payflow-guard commit **`002cb4e`** (apps/web, apps/worker, apps/ai with 83 tracked files; ADR-0019), rename product config, ports (incl. `stripe-mock` 12121 in `docker-compose.yml`), `REDIS_PREFIX`, brand placeholder; keep single `INTERNAL_API_SECRET` + caller-scoped internal routes | Foundation DoD green in the opsbot repo; `FOUNDATION_CONTRACT.md` header records `002cb4e`; **not cloned** check passes: no `apps/worker/src/rag`, no `WORKER_ROLES`, no per-pair internal keys, no PayFlow docs/design-system/domain code/seeders, `apps/ai` present and `AI_URL=http://127.0.0.1:8202` |
| **M1 — Identity & multi-bot** | Roles enum, `kind/status`, nullable e-mail, departments, employees & links, seats; `telegram_bots`, connect/rotate/disconnect, credentials endpoint; worker BotRegistry, migrations 0003/0004, per-bot webhook, job ids, context cache, FakeTelegramDriver, leader lock, buckets; move starter pack | Pest + Vitest for ADR-0002/0003/0004; E2E steps 1, 2, 4 green |
| **M2 — Knowledge lifecycle & retrieval** | Approval lifecycle, supersede, review tick; department_ids ACL in web and rag; metadata PATCH; worker `document.extract` (pdfjs, mammoth, sandboxed tesseract `rus+eng`) + ai `/v1/documents/{id}/index`; signed viewer; starter pack import | Golden set green; ACL/approval truth tables green; E2E step 3 + viewer; extractor fixtures (text PDF, scan, DOCX) green |
| **M3 — Assistant & actions** | `/v1/chat` deltas, tools, gate amendment (intent-only data-tool bypass, `answer_kind`), action validation, disclaimers; `ai_query_actions` + ActionExecutor; Telegram rendering, commands, notice gate, group notice | Injection + idempotency tests; fake-LLM gate test (0 provider calls on golden 11–20); E2E step 5 green |
| **M4 — Operations** | Incidents, tasks, routing rules, reminders + quiet hours, escalation round trip, FAQ conversion, notification topics + e-mail fallback | E2E step 6 + escalation spec green; DST tests green |
| **M5 — Analytics, billing, privacy** | Analytics page, dashboard, limits & notices, subscription states (paused stops AI only), billing page per `pages/billing.md` (usage meters, upgrade dialog, prices per market currency), employee notice & query visibility, employee export/erase, audit catalog | KPI tests exact; limits/grace/paused tests; export/erase tests; E2E step 7 + limits spec |
| **M6 — Brand, landing, hardening** | MASTER tokens and the OpsBot shell (icon rail, Live desk, bottom tabs — MASTER §4.1), pages per `design-system/opsbot/pages/*`, landing/pricing/security, a11y + responsive pass, Docker prod, CI matrix, security review | Full E2E matrix + axe green; screenshots 375/768/1440 both themes; no external requests |
| **M7 — Private beta** | 5 cleaning companies, concierge onboarding with the starter pack | Activation metrics measured; feedback triaged |

## 10. Risk register

| # | Risk | L | I | Mitigation |
|---|---|---|---|---|
| R1 | Perceived as "another ChatGPT for documents" | High | High | Vertical-first positioning, starter pack, Telegram-only employees, incident/task actions (ADR-0001) |
| R2 | Bot answers from outdated or draft rules | Medium | High | Approval lifecycle + effective dates in the retrieval SQL, tests (ADR-0005) |
| R3 | Hallucinated policy or compensation amounts | Medium | High | Confidence gate, citations required, refusal text, compensation disclaimer appended by code (ADR-0006, ADR-0001) |
| R4 | Prompt injection via documents or messages creates spam/escalations | Medium | Medium | Read-only tools, proposals confirmed by a human, idempotent execution, rate limits (ADR-0007) |
| R5 | Bot token leak | Low | Critical | Encrypted at rest, credentials endpoint only, never in outbox/jobs/logs, redaction tests (ADR-0003) |
| R6 | ACL leak via group chats or stale cache | Medium | High | Private chats only, explicit cache invalidation, 60 s TTL (ADR-0009, ADR-0011) |
| R7 | Forwarded source links | Medium | Medium | 30-min TTL, bound to user, re-checked ACL, audited; stated in the employee notice (ADR-0010) |
| R8 | Employees feel surveilled; legal complaints | Medium | High | Employee notice + acknowledgement, scoped visibility, audited views, 180-day retention (ADR-0015) |
| R9 | Night-time pings to shift workers | Medium | Medium | Quiet hours, DST-safe planner (ADR-0013) |
| R10 | OCR of large scans overloads the worker or a malicious file exploits a parser | Medium | Medium | BullMQ `document.extract` concurrency 2, per-page timeout, page limits per plan, sandboxed extractor child without secrets (ADR-0008) |
| R11 | Telegram limits / 409 polling conflicts with many bots | Medium | Medium | Webhooks in production, leader lock in dev, per-bot buckets, 429 handling (ADR-0018) |
| R12 | Unverified market claims on the landing | Medium | Medium | No statistics without a verified source; qualitative copy only |
| R13 | Russian customers cannot pay via Stripe | High | Medium | Market chosen at onboarding → `yookassa` gateway in RUB (PLATFORM §11.3, foundation F3); RU hosting region (`DATA_REGION=ru`) before selling to RU-resident companies (152-ФЗ) |
| R14 | Telegram unavailable or blocked in a customer's region | Low | High | Web assistant preview works for web users; e-mail fallback for notifications; status page note |
| R15 | Single-maintainer complexity (3 services) | Medium | Medium | This contract, shared signing vectors, CI matrix, fake drivers |

## 11. Legal & privacy (PLATFORM §10, §11)

### 11.1 Product-specific legal documents (RU + EN, versioned, `/legal/{doc}`, template banner until `LEGAL_REVIEWED=true`)
| Doc key | Content specific to OpsBot | Owner |
|---|---|---|
| `employee-notice` | what the bot stores per record type and how long (DOMAIN_CONTRACT §15), who sees it (owners/admins, managers of your departments), AI answers can be wrong and cite sources, manager answers are not regulations, signed links expire in 30 min, how to get export/erasure via the admin; acknowledged in the bot | web-core (text), planner (content) |
| `terms` additions | customer's own bot token and Telegram ToS responsibility; starter-pack documents are templates, not legal/safety advice; AI output disclaimer; RU: public offer («публичная оферта») when the market is RU | web-core |
| `privacy` additions | customer = controller of employee data and document content, OpsBot = processor; employee data categories (name, phone, Telegram id/username, questions, incidents, tasks); retention table; Telegram and LLM providers as sub-processors when enabled | web-core |
| `dpa` annex | processing of employee data and uploaded regulations; sub-processor list incl. Telegram (if a bot is connected), Stripe/YooKassa (billing), LLM provider (if external allowed) | web-core |
| `ai` additions | answers only from approved documents, confidence gate and refusals, no training on customer data, `mock`/local mode | ai (facts), web-core (text) |
| Disclaimers in product | compensation line on money answers (ADR-0001/0006); «Ответ менеджера (не из регламента)»; starter pack "adapt before approving"; viewer footer "valid until"; pricing tax note per market | worker, web-ui |

### 11.2 Data-subject rights, retention, PII
- Web users: Settings → Privacy (F2 export/delete). **Telegram-only employees:** the controller exports or
  erases in-product (`/employees/{user}/export`, `/employees/{user}/erase`, ADR-0015); `/privacy` in the
  bot explains this. Workspace export/deletion (F2) covers bots (token wiped first), employees, incidents,
  tasks, questions, documents, RAG chunks and Redis keys.
- Retention per DOMAIN_CONTRACT §15, enforced by the F2 purge job; the employee notice renders it from
  config (no single hard-coded number).
- PII redaction (F2) before external LLM calls: employee messages and extracted document text (phones,
  e-mails, card numbers, IBAN); `client_ref` (client addresses) never leaves the deployment; OCR runs
  locally (tesseract), never via a cloud OCR.

### 11.3 Notes
- **Roles under GDPR / 152-ФЗ:** the customer is controller for employee data and document content; we
  are processor (DPA click-wrap by the owner). Employee notice (PLATFORM §10.1, ADR-0015) is part of the
  product, versioned, acknowledged in the bot.
- **AI transparency:** answers are labelled AI-generated with sources; manager answers are labelled as not
  from the regulations; `mock`/local providers keep data in the deployment; external LLMs only when the
  workspace allows them; PII redaction before external calls.
- **No legal advice:** starter pack documents are templates ("adapt before approving"); compensation
  answers carry the manager-confirmation line.
- **Telegram:** the company's bot is operated with the customer's token; Telegram is listed as a
  sub-processor only when a bot is connected; no files are sent into Telegram in the MVP (signed links).
- **Billing (PLATFORM §11):** SaaS subscriptions only; Stripe primary (USD/EUR), YooKassa for RUB with
  54-ФЗ receipts; no card data on our servers (hosted pages, SAQ A); Terms state auto-renewal,
  cancellation and refunds per market.
- **Russia:** selling to RU-resident companies requires an RU hosting region for personal data of RU
  citizens (open issue, same as the other products).

## 12. Definition of done (feature level)

1. Matches `DOMAIN_CONTRACT.md` exactly (or the contract was changed first, with an ADR if a decision changed).
2. web: Pest for happy path, each role's denial, department scope, workspace isolation, validation messages
   (RU + EN), every state transition (allowed + forbidden), audit entry, outbox payload schema.
3. worker: Vitest for routing, idempotency (same job id twice), per-bot behaviour, templates in both
   locales, handlers via the dev transformer.
4. ai: pytest for tools (schema, permission refusal), filters, actions validation, golden set, locale strings.
5. UI: translation keys only, RU plurals, light + dark designed per MASTER, empty/loading/error states with
   the specified illustrations, keyboard + screen reader, axe clean, 375/768/1024/1440 without horizontal
   scroll, charts with a table alternative, `data-test` hooks from the page spec.
6. Works offline with fake/mock drivers; the relevant E2E journey green in its locale/theme matrix.
7. `php artisan test`, `vue-tsc`, lint, worker tests + build, `uv run pytest`, `ruff` green in CI.
8. No secrets in logs, outbox, jobs, Telegram or AI prompts.

## 13. Review resolution maps

### 13.1 Plan review 1 (2026-10-22)

| Finding | Resolved in |
|---|---|
| Plan docs missing | this file, `DOMAIN_CONTRACT.md`, `ARCHITECTURE.md`, `adr/0001–0018` |
| MASTER.md wrong product / tokens / fonts / no dark theme | `design-system/opsbot/MASTER.md` rewritten by hand |
| Page overrides wrong patterns; missing pages | `design-system/opsbot/pages/*` rewritten as screen specs (17 pages) |
| Multi-bot registry | ADR-0002, DOMAIN_CONTRACT §3.1–§3.2, §7.1, §9 |
| Token custody / connect flow | ADR-0003 |
| Telegram-only employees, seats SQL | ADR-0004 |
| Incidents, tasks, routing, unanswered log | DOMAIN_CONTRACT §3.1, §4, §8 |
| Approved-documents rule | ADR-0005 |
| Confidence gate | ADR-0006 (decision note: foundation formula kept), golden set §10.7 |
| ACL & cache invalidation | ADR-0009 |
| Source links for telegram-only staff | ADR-0010 |
| Prompt injection & Telegram HTML | ADR-0007 |
| Group chats | ADR-0011 |
| Worker scaling | ADR-0018 (decision note: token buckets instead of BullMQ Pro groups) |
| OCR / ingest | ADR-0008 |
| Billing | ADR-0012, DOMAIN_CONTRACT §11 |
| Reminders & timezones | ADR-0013 (decision note: dedupe by `(task_id, kind)` in web) |
| Escalation round trip | ADR-0014 |
| Audit & employee privacy | ADR-0015 |
| Statistics determinism | ADR-0016, DOMAIN_CONTRACT §13 |
| Playwright scope | DOMAIN_CONTRACT §17 |
| Streaming | ADR-0017 |
| RU typography & plurals | MASTER §3, DOMAIN_CONTRACT §19 (decision note: foundation already supports plural objects) |
| Vertical positioning & seed | ADR-0001, `docs/seed-documents/cleaning/` |

### 13.2 Plan review 2 (2026-10-22, adversarial; findings received inline, no review file in the repo)
| Finding (severity) | Resolved in |
|---|---|
| M0 clone source not pinned; PayFlow deletes `apps/ai`, per-pair keys (critical) | ADR-0019 (pin `002cb4e`, not-cloned list, `AI_URL` :8202), §9 M0, DOMAIN_CONTRACT §0.2–§0.3 (decision: single `INTERNAL_API_SECRET` + caller-scoped routes; PayFlow ADR-0010 not adopted) |
| Foundation gate bypassed by any available data tool (critical) | ADR-0006 amendment, DOMAIN_CONTRACT §10.2/§10.4 (`answer_kind`), §8 invariant, pytest with fake LLM |
| Billing USD-only contradicts PLATFORM §11 (major) | ADR-0012 §1/§8, DOMAIN_CONTRACT §0.1/§3.1/§6.2/§11, §7, R13, `pages/pricing.md`, `pages/landing.md` |
| OCR/document processing not in Node + BullMQ (major) | ADR-0008 rewritten (worker `document.extract` → ai `/index`; `rag.ingest_jobs`, advisory lock, watch job removed; `rus.traineddata` via `TESSDATA_PREFIX` / worker image) |
| App shell = PayFlow's sidebar + header (major) | MASTER §4/§4.1 (icon rail, Live desk, bot-status top bar, bottom tabs), `pages/dashboard.md` (today feed), `pages/landing.md` (chat-story) |
| No billing / plan-usage screen spec (major) | `pages/billing.md`, DOMAIN_CONTRACT §5 `BillingProps`, §17 smoke + axe |
| No export/erasure for Telegram-only employees (major) | ADR-0015 "Employee data rights", ADR-0004, DOMAIN_CONTRACT §5/§14/§15, `pages/employees.md` |
| `paused` blocks incident reporting (major) | ADR-0012 §6, DOMAIN_CONTRACT §9.4/§11, Vitest + E2E limits spec |
| Employee notice understates retention (minor) | ADR-0015 notice, DOMAIN_CONTRACT §15 (rendered from config) |

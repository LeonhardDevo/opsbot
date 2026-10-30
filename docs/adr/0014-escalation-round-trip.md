# ADR-0014 — Escalation round trip: manager answers are relayed, never silently become policy

- Status: accepted
- Date: 2026-10-22
- Related: ADR-0005, ADR-0007, ADR-0018, DOMAIN_CONTRACT §3.1 `unanswered_questions`, §4.4, §8, §9.6

## Context

"Отправка сложных вопросов менеджеру" needs a return path: how the manager answers, how the answer
reaches the employee, and whether it becomes knowledge. A free-text manager answer must not silently
become approved policy.

## Decision

1. **Escalate** (button on a refused/low-confidence answer, or the employee's `/ask_manager` command
   on the last question) → web creates `unanswered_questions` (`status = open`, `ai_query_id`,
   `employee_user_id`, `department_id` = employee's primary department, `assignee_user_id` from routing
   category `question` → department manager → workspace owner, `reason`). Idempotent per `ai_query_id`
   (unique). Audit `question.escalated`.
2. Web publishes `opsbot.question.escalated` to the assignee. The worker sends through the **workspace's
   bot** (the manager is a web user who linked Telegram there); if not linked → delivery callback
   `telegram_not_linked` → web e-mails the manager (ADR-0018). Buttons:
   **Answer** (`q_answer:{id}`), **Open in web** (URL `/unanswered?focus={id}`), **Dismiss**
   (`q_dismiss:{id}`).
3. **Answer in Telegram**: `q_answer` is handled locally by the worker's `opsbot` module: it sends a
   `force_reply` prompt ("Reply with the answer to question Q-15") and stores Redis state
   `reply:{bot_key}:{telegram_user_id} = {question_id, prompt_message_id}` (TTL 30 min). The manager's
   next message that replies to `prompt_message_id` is posted to
   `POST /internal/v1/opsbot/questions/{id}/answer {bot_key, telegram_user_id, text}`. Any other message
   is handled normally (no hijacking of free text).
4. **Answer in web**: `/unanswered` page, textarea (plain text, 2000 chars), same service.
5. Web stores `answer_text`, `answered_by`, `answered_at`, `status = answered`, audit `question.answered`,
   and publishes **`opsbot.question.answered`** `{workspace_id, query_id, question_id,
   employee_user_id, locale, question_excerpt, text_html, manager_name}` (text escaped to HTML by web).
   The employee receives it labelled **«Ответ менеджера (не из регламента)» / "Manager's answer (not from
   the regulations)"**, so it is never mistaken for an approved document.
6. **Convert to FAQ** (web, or a button sent back to the manager after answering, permission
   `knowledge.manage`): creates a **draft** Markdown document "FAQ: <question>" (body = question +
   answer + "Source: manager answer, <date>") with the question's department, `status = queued` →
   indexed → must be approved (ADR-0005) before it is answerable. `status = converted_to_doc`,
   `document_id` stored. Audit `question.converted_to_doc`.
7. **Dismiss** requires a reason (`duplicate | out_of_scope | spam | other`), status `dismissed`, the
   employee is not notified.
8. SLA visibility: the unanswered page and dashboard show open questions older than 24 h in coral.

## Tests

Pest: escalate twice → one row; routing fallback chain; answer by a manager outside the department →
forbidden; answered → outbox payload; convert creates a draft never cited before approval (with
pytest). Vitest: force_reply flow, unrelated message not captured, expired state.

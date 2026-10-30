# Unanswered questions — screen spec

Route `/unanswered` · page `unanswered/Index` · ability `questions.answer` (managers: own departments) ·
props contract §5 (`questions`, `groups`, `filters`, `counts`, `departments`, `assignees`); actions
answer / dismiss / convert / reassign · flow ADR-0014.

## Purpose
The manager's inbox of questions the bot could not answer from approved documents: answer them (the
employee gets the answer in Telegram, labelled as not from the regulations), dismiss noise, and turn
recurring questions into FAQ drafts so the knowledge base improves.

## Layout (8 + 4 columns at ≥ 1024)
- **Header**: H1 «Неотвеченные вопросы»; caption «Ответ уйдёт сотруднику в Telegram с пометкой
  „не из регламента“».
- **Status tabs** with counts: Открытые · Отвеченные · В базе знаний · Отклонённые · Все; badge
  «старше 24 ч: N» (coral) on Открытые.
- **Filters**: department, assignee ("Мне" chip), search.
- **Queue list** (main column): one card per question — `Q-15` (mono), question text (full, quoted
  style), employee name + department chip, asked time (relative + local tooltip), reason badge
  (low_confidence «Не уверен», not_found «Не найдено», conflict «Противоречие», user_request «Попросил
  сотрудник», limit_reached «Лимит вопросов»), age (coral > 24 h), "похожих: N" chip when
  `similar_count > 0`. Open card actions: **inline answer composer** (textarea 2000, counter,
  "Ответить" primary), "Отклонить" (menu with reasons: duplicate, out of scope, spam, other), reassign
  select. Answered card: answer text in a `manager` bubble with author and time, action "Создать
  FAQ-черновик" (`knowledge.manage`). Converted: link to the draft document with its approval badge.
- **Groups panel** (side column, ≥ 1024; below: collapsible section above the list): «Частые темы» — up to
  10 groups (representative text, count bar in `--primary-soft`, last asked) — clicking filters the list to
  the group's question ids. Caption explains grouping is by text similarity (deterministic).
- `focus` query param scrolls to and highlights the card (2 px `--ring` outline for 2 s).

## States
Empty open queue: illustration (inbox tray with a check, mint accents) + «Все вопросы разобраны». Loading:
card skeletons. Answer sent: toast «Ответ отправлен сотруднику» and the card moves to Отвеченные.

## a11y
Each card is an `article` labelled by its Q-number; composer label «Ответ на вопрос Q-15»; reasons are text,
not colour.

## data-test
`unanswered-tab` (+ `data-status`), `unanswered-card` (+ `data-number`, `data-status`),
`unanswered-answer-input`, `unanswered-answer-submit`, `unanswered-dismiss`, `unanswered-dismiss-reason`,
`unanswered-convert`, `unanswered-reassign`, `unanswered-group`.

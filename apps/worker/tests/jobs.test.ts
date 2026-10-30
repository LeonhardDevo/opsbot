import { readFileSync } from 'node:fs';
import { gunzipSync } from 'node:zlib';
import { DelayedError, UnrecoverableError } from 'bullmq';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AccountResolver } from '../src/bot/accounts.js';
import { createModuleRegistry } from '../src/bot/moduleRegistry.js';
import { defaultModules } from '../src/bot/modules/index.js';
import { MemoryActionStore, MemoryCache } from '../src/bot/stores.js';
import type { BotModule } from '../src/bot/types.js';
import { AiDocumentError } from '../src/clients/ai.js';
import { UpstreamHttpError, UpstreamNetworkError } from '../src/clients/errors.js';
import { MemoryChatPreferences, MemoryGroupNotices } from '../src/db/telegramChats.js';
import { MemoryWebhookEventStore } from '../src/db/webhookEvents.js';
import { Translator } from '../src/i18n/index.js';
import type { JobDeps } from '../src/jobs/deps.js';
import { encodeExtraction, extractCacheKey } from '../src/jobs/documentExtract.js';
import { emailPayloadSchema } from '../src/jobs/email.js';
import { createHandlers } from '../src/jobs/index.js';
import { OPSBOT_TOPIC_SCHEMAS } from '../src/jobs/opsbotNotify.js';
import { SCHEDULES, registerSchedules } from '../src/jobs/schedules.js';
import type { JobContext } from '../src/jobs/types.js';
import { createSilentLogger } from '../src/logger.js';
import type { Mailer } from '../src/mail/mailer.js';
import { MemoryEnqueuer } from '../src/queues/enqueuer.js';
import { OPSBOT_NOTIFICATION_TOPICS } from '../src/queues/routes.js';
import { TelegramChatBlockedError, TelegramRateDelay, TelegramBotRevokedError, type SendInput, type TelegramSenderApi } from '../src/telegram/sender.js';
import { companyCredentials, fakeAi, type fakeWeb, linkedContext } from './helpers/fakes.js';
import { createTestBot } from './helpers/bot.js';

const OUTBOX_ID = '8f9a4a0e-2a54-4e8e-bb0b-0d3c2f1e9a11';

class DelayCalled extends Error {
  constructor(public readonly ms: number) {
    super(`delay ${ms}`);
  }
}

function ctx(overrides: Partial<JobContext> = {}): JobContext {
  return {
    queue: 'q',
    name: 'n',
    jobId: OUTBOX_ID,
    attempt: 1,
    maxAttempts: 5,
    isFinalAttempt: false,
    requestId: 'req-1',
    logger: createSilentLogger(),
    delay: async (ms: number) => {
      throw new DelayCalled(ms);
    },
    progress: async () => undefined,
    ...overrides,
  };
}

function envelope(topic: string, payload: unknown, workspaceId: number | null = 3) {
  return { outbox_id: OUTBOX_ID, topic, workspace_id: workspaceId, payload };
}

const extraModule: BotModule = {
  name: 'extra',
  notifications: {
    'extra.overdue': (params, { t, escape, locale }) => ({
      html: `<b>${escape(String(params.number))}</b> ${locale === 'ru' ? 'просрочен' : 'is overdue'} — ${t('notify.fallback')}`,
      buttons: [{ text: 'Resolve', callback: `extra.resolve:${String(params.id)}` }],
    }),
  },
};

let web: ReturnType<typeof fakeWeb>;
let ai: ReturnType<typeof fakeAi>;
let mailer: { send: ReturnType<typeof vi.fn<Mailer['send']>>; verify: ReturnType<typeof vi.fn<Mailer['verify']>>; close: ReturnType<typeof vi.fn<Mailer['close']>> };
let sender: { send: ReturnType<typeof vi.fn<TelegramSenderApi['send']>> };
let markers: MemoryCache;
let extracts: MemoryCache;
let events: MemoryWebhookEventStore;
let actions: MemoryActionStore;
let enqueuer: MemoryEnqueuer;
let preferences: MemoryChatPreferences;
let groupNotices: MemoryGroupNotices;
let accountsCache: MemoryCache;
let testBot: ReturnType<typeof createTestBot>;
let lifecycle: { stopPolling: ReturnType<typeof vi.fn<(botKey: string) => Promise<void>>>; publishEvict: ReturnType<typeof vi.fn<(botKey: string) => Promise<void>>> };
let handlers: ReturnType<typeof createHandlers>;

function sent(index = 0): SendInput {
  const call = sender.send.mock.calls[index];
  if (!call) throw new Error(`no send call #${index}`);
  return call[0];
}

beforeEach(() => {
  const anna = linkedContext({ telegram: { bot_key: '7', user_id: 5001, chat_id: 5001, username: 'anna', blocked: false } });
  const maria = linkedContext({
    user: { id: 8, name: 'Maria Lopez', locale: 'en', kind: 'telegram_only' },
    telegram: { bot_key: '7', user_id: 5003, chat_id: 5003, username: 'maria', blocked: false },
  });
  testBot = createTestBot({ contexts: new Map([[5001, anna], [5003, maria]]), credentials: new Map([[7, companyCredentials()]]) });
  web = testBot.web;
  ai = fakeAi();
  mailer = { send: vi.fn<Mailer['send']>(async () => ({ messageId: '<smtp-1@mailpit>' })), verify: vi.fn<Mailer['verify']>(async () => true), close: vi.fn<Mailer['close']>() };
  sender = { send: vi.fn<TelegramSenderApi['send']>(async () => ({ message_id: 321 })) };
  markers = new MemoryCache();
  extracts = new MemoryCache();
  events = new MemoryWebhookEventStore();
  actions = new MemoryActionStore();
  enqueuer = new MemoryEnqueuer();
  preferences = new MemoryChatPreferences();
  groupNotices = new MemoryGroupNotices();
  accountsCache = new MemoryCache();
  lifecycle = { stopPolling: vi.fn(async (_botKey: string) => undefined), publishEvict: vi.fn(async (_botKey: string) => undefined) };
  const translator = new Translator('Acme Desk');
  const deps: JobDeps = {
    web,
    ai,
    mailer,
    sender,
    sentMarkers: markers,
    extracts,
    webhookEvents: events,
    bots: testBot.registry,
    lifecycle,
    modules: createModuleRegistry([...defaultModules(), extraModule], translator),
    telegramChats: preferences,
    translator,
    actions,
    accounts: new AccountResolver(web, accountsCache, 60),
    preferences,
    groupNotices,
    enqueuer,
    settings: {
      productSlug: 'acme',
      mailFrom: '"Acme Desk" <no-reply@acme.test>',
      notificationActionTtlS: 3600,
      maxFileBytes: 10 * 1024 * 1024,
      extractTtlS: 3600,
      extractConcurrency: 2,
      reembedBatchDocuments: 20,
    },
  };
  handlers = createHandlers(deps);
});

describe('email.send', () => {
  const payload = {
    to: 'anna@example.com',
    subject: 'Счёт оплачен',
    html: '<p>Готово</p>',
    text: 'Готово',
    locale: 'ru',
    reference: { type: 'incident', id: 42 },
    workspace_id: 3,
  };
  const run = (data: unknown, context = ctx()) => handlers.emails['email.send']!(data, context);

  it('sends via SMTP with a deterministic Message-ID, then reports the delivery', async () => {
    await run(envelope('email.send', payload));
    expect(mailer.send).toHaveBeenCalledWith(
      expect.objectContaining({
        from: '"Acme Desk" <no-reply@acme.test>',
        to: 'anna@example.com',
        subject: 'Счёт оплачен',
        messageId: `<${OUTBOX_ID}@acme.worker>`,
        headers: expect.objectContaining({ 'X-Reference': 'incident:42', 'Content-Language': 'ru' }) as unknown,
      }),
    );
    expect(web.reportDelivery).toHaveBeenCalledWith(
      { reference: { type: 'incident', id: 42 }, channel: 'email', status: 'sent', provider_message_id: '<smtp-1@mailpit>' },
      'req-1',
    );
  });

  it('never re-sends on retry after the callback failed', async () => {
    web.reportDelivery.mockRejectedValueOnce(new UpstreamNetworkError('web', 'POST', '/internal/v1/deliveries', new Error('down')));
    await expect(run(envelope('email.send', payload))).rejects.toBeInstanceOf(UpstreamNetworkError);
    await run(envelope('email.send', payload), ctx({ attempt: 2 }));
    expect(mailer.send).toHaveBeenCalledTimes(1);
    expect(web.reportDelivery).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'sent', provider_message_id: '<smtp-1@mailpit>' }), 'req-1');
  });

  it('fails permanently on SMTP 5xx and reports the failure', async () => {
    mailer.send.mockRejectedValueOnce(Object.assign(new Error('550 mailbox unavailable'), { responseCode: 550 }));
    await expect(run(envelope('email.send', payload))).rejects.toBeInstanceOf(UnrecoverableError);
    expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed', channel: 'email' }), 'req-1');
  });

  it('retries transient SMTP errors and reports failure only on the last attempt', async () => {
    mailer.send.mockRejectedValue(Object.assign(new Error('connect ECONNREFUSED'), { code: 'ECONNECTION' }));
    await expect(run(envelope('email.send', payload))).rejects.toThrow('ECONNREFUSED');
    expect(web.reportDelivery).not.toHaveBeenCalled();
    await expect(run(envelope('email.send', payload), ctx({ attempt: 5, isFinalAttempt: true }))).rejects.toThrow('ECONNREFUSED');
    expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed' }), 'req-1');
  });

  describe('shared contract samples (contracts/outbox/email-send.v1.samples.json, also asserted by apps/web OutboxTest)', () => {
    const fixture = JSON.parse(readFileSync(new URL('../../../contracts/outbox/email-send.v1.samples.json', import.meta.url), 'utf8')) as {
      topic: string;
      valid: Array<{ name: string; payload: Record<string, unknown> }>;
      invalid: Array<{ name: string; payload: Record<string, unknown> }>;
    };

    it('covers the documented cases', () => {
      expect(fixture.topic).toBe('email.send');
      expect(fixture.valid.map((c) => c.name)).toEqual(['html_only_no_reply_to', 'text_only_with_extras']);
      expect(fixture.invalid.length).toBeGreaterThan(0);
    });

    it.each(fixture.valid.map((c) => [c.name, c.payload] as const))('accepts %s as published by web', async (_name, webPayload) => {
      expect(emailPayloadSchema.safeParse(webPayload).success).toBe(true);
      await run(envelope('email.send', webPayload));
      expect(mailer.send).toHaveBeenCalledTimes(1);
      const sent = mailer.send.mock.calls[0]![0];
      const from = webPayload.from as { address: string; name: string };
      expect(sent.from).toEqual({ address: from.address, name: from.name });
      expect(sent.subject).toBe(webPayload.subject);
      expect(sent.headers).toMatchObject({ 'Content-Language': webPayload.locale });
      expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'sent', channel: 'email' }), 'req-1');
    });

    it.each(fixture.invalid.map((c) => [c.name, c.payload] as const))('rejects %s permanently without sending', async (_name, badPayload) => {
      expect(emailPayloadSchema.safeParse(badPayload).success).toBe(false);
      await expect(run(envelope('email.send', badPayload))).rejects.toBeInstanceOf(UnrecoverableError);
      expect(mailer.send).not.toHaveBeenCalled();
    });

    it('maps nulls, the recipient name, reply-to and attachments to SMTP', async () => {
      const [htmlOnly, extras] = fixture.valid;
      await run(envelope('email.send', htmlOnly!.payload));
      const first = mailer.send.mock.calls[0]![0];
      expect(first.to).toBe('new.user@example.com');
      expect(first.html).toContain('verify your email');
      expect(first).not.toHaveProperty('text');
      expect(first).not.toHaveProperty('replyTo');
      expect(first).not.toHaveProperty('attachments');

      mailer.send.mockClear();
      await markers.del(`sent:email:${OUTBOX_ID}`);
      await run(envelope('email.send', extras!.payload));
      const second = mailer.send.mock.calls[0]![0];
      expect(second.to).toEqual({ address: 'anna@example.com', name: 'Анна Петрова' });
      expect(second.replyTo).toEqual({ address: 'owner@acme.test', name: '' });
      expect(second.text).toBe('Plain only');
      expect(second).not.toHaveProperty('html');
      expect(second.attachments?.[0]).toMatchObject({ filename: 'report.txt', contentType: 'text/plain' });
      expect(second.attachments?.[0]?.content.toString()).toBe('hello');
    });
  });

  it('falls back to MAIL_FROM and accepts a legacy string reply_to', async () => {
    await run(envelope('email.send', { ...payload, reply_to: 'boss@acme.test' }));
    expect(mailer.send).toHaveBeenCalledWith(expect.objectContaining({ from: '"Acme Desk" <no-reply@acme.test>', replyTo: 'boss@acme.test' }));
  });

  it('rejects invalid payloads without retrying', async () => {
    await expect(run(envelope('email.send', { ...payload, to: 'nobody' }))).rejects.toBeInstanceOf(UnrecoverableError);
    await expect(run(envelope('email.send', { ...payload, html: undefined, text: undefined }))).rejects.toBeInstanceOf(UnrecoverableError);
    await expect(run({ nope: true })).rejects.toBeInstanceOf(UnrecoverableError);
    expect(mailer.send).not.toHaveBeenCalled();
  });
});

describe('telegram.notify', () => {
  const run = (data: unknown, context = ctx()) => handlers.telegram['telegram.notify']!(data, context);

  it('sends sanitized HTML with buttons to an explicit chat_id on the given bot', async () => {
    await run(
      envelope('telegram.notify', {
        workspace_id: 3,
        bot_key: '7',
        chat_id: 777,
        text_html: '<p>Incident <strong>A-1</strong> resolved</p>',
        buttons: [{ text: 'Open', url: 'https://app.acme.example/incidents/1' }, { text: 'Resolve', callback: 'extra.resolve:1' }],
        reference: { type: 'incident', id: 1 },
      }),
    );
    const input = sent();
    expect(input).toMatchObject({ botKey: '7', chatId: 777, html: 'Incident <b>A-1</b> resolved', telegramUserId: 777 });
    const rows = input.keyboard!.inline_keyboard;
    expect(rows.every((row) => row.length > 0)).toBe(true);
    expect(rows[0]![0]).toMatchObject({ text: 'Open', url: 'https://app.acme.example/incidents/1' });
    const callback = (rows[1]![0] as { callback_data: string }).callback_data;
    expect(await actions.get(callback.slice(2))).toMatchObject({ bot_key: '7', type: 'extra.resolve', payload: { id: 1, arg: '1' }, chat_id: 777 });
    expect(web.reportDelivery).toHaveBeenCalledWith(
      { reference: { type: 'incident', id: 1 }, channel: 'telegram', status: 'sent', provider_message_id: '321' },
      'req-1',
    );
  });

  it('resolves the recipient through the workspace context and renders templates in their locale', async () => {
    await run(envelope('telegram.notify', { workspace_id: 3, user_id: 7, template: 'extra.overdue', params: { number: 'A-7', id: 7 }, reference: { type: 'incident', id: 7 } }));
    expect(web.telegramContext).toHaveBeenCalledWith({ workspaceId: 3, userId: 7 }, 'req-1');
    expect(sent()).toMatchObject({ botKey: '7', chatId: 5001, html: '<b>A-7</b> просрочен — У вас новое уведомление в Acme Desk.' });
    const callback = (sent().keyboard!.inline_keyboard[0]![0] as { callback_data: string }).callback_data;
    expect(await actions.get(callback.slice(2))).toMatchObject({ telegram_user_id: 5001, user_id: 7, locale: 'ru', bot_key: '7' });
  });

  it('renders the opsbot test message template', async () => {
    await run(envelope('telegram.notify', { workspace_id: 3, user_id: 8, template: 'opsbot.test_message', reference: { type: 'bot', id: 1 } }));
    expect(sent().html).toBe('<b>Test message</b>\nThe company bot is connected and can deliver notifications.');
  });

  it('reports not-linked and blocked recipients without retrying', async () => {
    const result = await run(envelope('telegram.notify', { workspace_id: 3, user_id: 999, text_html: 'x', reference: { type: 't', id: 1 } }));
    expect(result).toEqual({ skipped: 'telegram_not_linked' });
    expect(sender.send).not.toHaveBeenCalled();
    expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed', error: 'telegram_not_linked' }), 'req-1');
    sender.send.mockRejectedValueOnce(new TelegramChatBlockedError());
    const blocked = await run(envelope('telegram.notify', { workspace_id: 3, user_id: 7, text_html: 'x', reference: { type: 't', id: 2 } }));
    expect(blocked).toEqual({ skipped: 'chat_blocked' });
    expect(web.reportDelivery).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'failed', error: 'chat_blocked' }), 'req-1');
  });

  it('delays on rate limits without consuming an attempt and fails permanently on a revoked bot', async () => {
    sender.send.mockRejectedValueOnce(new TelegramRateDelay(1500));
    const data = envelope('telegram.notify', { workspace_id: 3, user_id: 7, text_html: 'x', reference: { type: 't', id: 1 } });
    await expect(run(data)).rejects.toMatchObject({ ms: 1500 });
    expect(web.reportDelivery).not.toHaveBeenCalled();
    sender.send.mockRejectedValueOnce(new TelegramBotRevokedError('7'));
    await expect(run(data)).rejects.toBeInstanceOf(UnrecoverableError);
    expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed' }), 'req-1');
  });

  it('is idempotent across retries', async () => {
    web.reportDelivery.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/internal/v1/deliveries', 502, null));
    const data = envelope('telegram.notify', { workspace_id: 3, bot_key: '7', chat_id: 1, text_html: 'hi', reference: { type: 't', id: 1 } });
    await expect(run(data)).rejects.toBeInstanceOf(UpstreamHttpError);
    await run(data, ctx({ attempt: 2 }));
    expect(sender.send).toHaveBeenCalledTimes(1);
  });

  it('rejects unknown templates, chat ids without a bot and payloads without a recipient or workspace', async () => {
    const bad = [
      { workspace_id: 3, bot_key: '7', chat_id: 1, template: 'nope', reference: { type: 't', id: 1 } },
      { workspace_id: 3, chat_id: 1, text_html: 'x', reference: { type: 't', id: 1 } },
      { workspace_id: 3, text_html: 'x', reference: { type: 't', id: 1 } },
    ];
    for (const payload of bad) await expect(run(envelope('telegram.notify', payload))).rejects.toBeInstanceOf(UnrecoverableError);
    await expect(run(envelope('telegram.notify', { user_id: 7, text_html: 'x', reference: { type: 't', id: 1 } }, null))).rejects.toBeInstanceOf(
      UnrecoverableError,
    );
  });
});

const recipientRu = { user_id: 7, locale: 'ru' };
const recipientEn = { user_id: 8, locale: 'en' };

const SAMPLES: Record<string, Record<string, unknown>> = {
  'opsbot.incident.created': {
    workspace_id: 3,
    incident: {
      id: 12,
      display_number: '№ 12',
      category: 'damage',
      severity: 'high',
      description_excerpt: 'Поцарапан стол <script>',
      client_ref: 'Квартира 5',
      department_name: 'Уборка квартир',
      reporter_name: 'Айгуль Сапарова',
      created_local: '22 окт., 14:30',
    },
    web_url: 'https://app.acme.example/incidents/12',
  },
  'opsbot.incident.status_changed': {
    workspace_id: 3,
    incident: { id: 12, display_number: '№ 12', status: 'in_progress', assignee_name: 'Олег Петров' },
    note_excerpt: 'Едем',
  },
  'opsbot.task.assigned': {
    workspace_id: 3,
    task: { id: 34, display_number: 'T-34', title: 'Вымыть окна', due_local: '23 окт., 10:00', incident_display_number: '№ 12' },
    assigned_by_name: 'Марина',
    web_url: 'https://app.acme.example/tasks/34',
  },
  'opsbot.task.reminder_due': {
    workspace_id: 3,
    reminder_id: 5,
    kind: 'overdue_1',
    task: { id: 34, display_number: 'T-34', title: 'Вымыть окна', due_local: '22 окт., 10:00', overdue_hours: 22 },
  },
  'opsbot.task.escalated': {
    workspace_id: 3,
    task: { id: 34, display_number: 'T-34', title: 'Вымыть окна', due_local: '20 окт., 10:00', overdue_hours: 73 },
    assignee_name: 'Иван',
    web_url: null,
  },
  'opsbot.question.escalated': {
    workspace_id: 3,
    question: {
      id: 15,
      display_number: 'Q-15',
      question_excerpt: 'Какой пароль от Wi-Fi?',
      employee_name: 'Айгуль',
      department_name: 'Уборка квартир',
      reason: 'low_confidence',
      asked_local: '22 окт., 9:00',
    },
    web_url: 'https://app.acme.example/unanswered?focus=15',
  },
  'opsbot.question.answered': {
    workspace_id: 3,
    query_id: 501,
    question_id: 15,
    question_excerpt: 'Какой пароль от Wi-Fi?',
    text_html: 'Спросите у <b>администратора</b>.',
    manager_name: 'Олег Петров',
  },
  'opsbot.document.review_due': {
    workspace_id: 3,
    document: { id: 4, title: 'Ключи', version: '1.0', review_due_local: '1 нояб. 2026' },
    web_url: 'https://app.acme.example/knowledge/4',
  },
  'opsbot.limits.notice': { workspace_id: 3, limit_key: 'ai_queries_monthly', used: 2700, limit: 3000, web_url: null },
  'opsbot.bot.revoked': { workspace_id: 3, bot_username: 'clean_line_bot', web_url: 'https://app.acme.example/bot' },
};

const EXPECTED_RU: Record<string, string[]> = {
  'opsbot.incident.created': ['<b>Новый инцидент № 12</b> — Повреждение', 'Важность: высокая', 'Отдел: Уборка квартир', 'Сообщил(а) Айгуль Сапарова, 22 окт., 14:30', '<blockquote>Поцарапан стол &lt;script&gt;</blockquote>'],
  'opsbot.incident.status_changed': ['<b>Инцидент № 12</b>: в работе', 'Ответственный: Олег Петров', '<blockquote>Едем</blockquote>'],
  'opsbot.task.assigned': ['<b>Новая задача T-34</b>', 'Срок: 23 окт., 10:00', 'По инциденту: № 12', 'Назначил(а) Марина'],
  'opsbot.task.reminder_due': ['<b>Задача T-34 просрочена на 22 часа</b>', 'Срок: 22 окт., 10:00'],
  'opsbot.task.escalated': ['<b>Задача T-34 просрочена на 73 часа</b>', 'Исполнитель: Иван'],
  'opsbot.question.escalated': ['<b>Вопрос Q-15 ждёт вашего ответа</b>', '<blockquote>Какой пароль от Wi-Fi?</blockquote>', 'От: Айгуль (Уборка квартир), 22 окт., 9:00', 'Причина: низкая уверенность по документам'],
  'opsbot.question.answered': ['<b>Ответ менеджера (не из регламента)</b>', '<blockquote>Какой пароль от Wi-Fi?</blockquote>', 'Спросите у <b>администратора</b>.', '— Олег Петров'],
  'opsbot.document.review_due': ['<b>Пора пересмотреть документ</b>', '«Ключи», версия 1.0', 'Дата пересмотра: 1 нояб. 2026'],
  'opsbot.limits.notice': ['<b>Лимит тарифа почти исчерпан</b>', 'вопросы в месяц: 2700 из 3000'],
  'opsbot.bot.revoked': ['<b>Бот компании перестал работать</b>', 'Telegram отклонил токен бота @clean_line_bot', 'настройках Acme Desk'],
};

const EXPECTED_EN: Record<string, string> = {
  'opsbot.incident.created': '<b>New incident № 12</b> — Damage',
  'opsbot.incident.status_changed': '<b>Incident № 12</b>: in progress',
  'opsbot.task.assigned': '<b>New task T-34</b>',
  'opsbot.task.reminder_due': '<b>Task T-34 is overdue by 22 hours</b>',
  'opsbot.task.escalated': '<b>Task T-34 is overdue by 73 hours</b>',
  'opsbot.question.escalated': '<b>Question Q-15 needs your answer</b>',
  'opsbot.question.answered': "<b>Manager's answer (not from the regulations)</b>",
  'opsbot.document.review_due': '<b>Time to review a document</b>',
  'opsbot.limits.notice': 'questions per month: 2700 of 3000',
  'opsbot.bot.revoked': 'Telegram rejected the token of @clean_line_bot',
};

describe('opsbot notification topics', () => {
  it('has a schema, a sample and a template for every topic', () => {
    expect(Object.keys(SAMPLES).sort()).toEqual([...OPSBOT_NOTIFICATION_TOPICS].sort());
    for (const topic of OPSBOT_NOTIFICATION_TOPICS) {
      expect(OPSBOT_TOPIC_SCHEMAS[topic].safeParse({ ...SAMPLES[topic], recipient: recipientRu }).success, topic).toBe(true);
      expect(handlers.telegram[topic], topic).toBeTypeOf('function');
    }
  });

  it.each([...OPSBOT_NOTIFICATION_TOPICS])('renders %s in Russian and English', async (topic) => {
    await handlers.telegram[topic]!(envelope(topic, { ...SAMPLES[topic], recipient: recipientRu }), ctx());
    const ru = sent(0);
    for (const fragment of EXPECTED_RU[topic] ?? []) expect(ru.html, topic).toContain(fragment);
    expect(ru.html).not.toMatch(/\p{Extended_Pictographic}/u);
    expect(web.reportDelivery).toHaveBeenCalledWith(expect.objectContaining({ reference: { type: topic, id: OUTBOX_ID }, status: 'sent' }), 'req-1');
    await markers.del(`sent:telegram:${OUTBOX_ID}`);
    await handlers.telegram[topic]!(envelope(topic, { ...SAMPLES[topic], recipient: recipientEn }), ctx());
    expect(sent(1).html, topic).toContain(EXPECTED_EN[topic]);
    expect(sent(1).chatId).toBe(5003);
  });

  it('attaches the action buttons of the escalation and incident templates', async () => {
    await handlers.telegram['opsbot.question.escalated']!(envelope('opsbot.question.escalated', { ...SAMPLES['opsbot.question.escalated'], recipient: recipientEn }), ctx());
    const keyboard = sent().keyboard!.inline_keyboard.flat();
    expect(keyboard.map((button) => button.text)).toEqual(['Answer', 'Open in web', 'Dismiss']);
    const answer = await actions.get((keyboard[0] as { callback_data: string }).callback_data.slice(2));
    expect(answer).toMatchObject({ type: 'q_answer', payload: { id: 15, display_number: 'Q-15' }, telegram_user_id: 5003, bot_key: '7' });
    const dismiss = await actions.get((keyboard[2] as { callback_data: string }).callback_data.slice(2));
    expect(dismiss).toMatchObject({ type: 'q_dismiss', payload: { id: 15 } });
  });

  it('rejects payloads that break the topic schema', async () => {
    const broken = { ...SAMPLES['opsbot.incident.created'], recipient: recipientRu, incident: { id: 1, category: 'fire' } };
    await expect(handlers.telegram['opsbot.incident.created']!(envelope('opsbot.incident.created', broken), ctx())).rejects.toBeInstanceOf(UnrecoverableError);
    await expect(
      handlers.telegram['opsbot.limits.notice']!(envelope('opsbot.limits.notice', { ...SAMPLES['opsbot.limits.notice'] }), ctx()),
    ).rejects.toBeInstanceOf(UnrecoverableError);
    expect(sender.send).not.toHaveBeenCalled();
  });
});

const uploaded = {
  document_id: 11,
  workspace_id: 3,
  upload_rev: 2,
  title: 'Policy',
  mime: 'application/pdf',
  size_bytes: 1000,
  limits: { max_pages_per_document: 30 },
  metadata: { department_ids: [1], roles: [], tags: ['incident'], version: '1.0', effective_date: '2026-01-01', approval_status: 'draft', language: null },
};

describe('documents', () => {
  it('queues the extraction with a revision-scoped job id', async () => {
    await handlers.documents['document.uploaded']!(envelope('document.uploaded', uploaded), ctx());
    expect(enqueuer.jobs).toEqual([
      expect.objectContaining({ queue: 'documents', name: 'document.extract', jobId: 'extract:11:2', data: expect.objectContaining({ document_id: 11, upload_rev: 2 }) }),
    ]);
    expect(enqueuer.jobs[0]?.options).toMatchObject({ attempts: 3, backoff: { type: 'exponential', delay: 5000 } });
  });

  it('extracts through ai, caches the pages and queues indexing', async () => {
    await handlers.documents['document.extract']!(uploaded, ctx());
    expect(web.downloadDocument).toHaveBeenCalledWith(11, 10 * 1024 * 1024, 'req-1');
    expect(ai.extractDocument).toHaveBeenCalledWith(11, { data: expect.any(Buffer), mime: 'application/pdf', filename: 'doc.md', maxPages: 30 }, 'req-1');
    expect(web.reportDocumentStatus).toHaveBeenCalledWith(11, { status: 'processing', upload_rev: 2, pages_done: 0 }, 'req-1');
    expect(web.reportDocumentStatus).toHaveBeenCalledWith(11, { status: 'processing', upload_rev: 2, pages_done: 1, pages_total: 1, ocr_used: false }, 'req-1');
    expect(await extracts.get(extractCacheKey(11, 2))).not.toBeNull();
    expect(enqueuer.jobs.map((job) => job.jobId)).toEqual(['index:11:2']);
  });

  it('reports permanent extraction errors with their code', async () => {
    ai.extractDocument.mockRejectedValueOnce(new AiDocumentError('too_many_pages', 422, 'the document has 400 pages'));
    await expect(handlers.documents['document.extract']!(uploaded, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
    expect(web.reportDocumentStatus).toHaveBeenLastCalledWith(11, { status: 'failed', upload_rev: 2, error_code: 'too_many_pages', error: 'too_many_pages' }, 'req-1');
    web.downloadDocument.mockRejectedValueOnce(new UpstreamHttpError('web', 'GET', '/internal/v1/documents/11/file', 404, null));
    await expect(handlers.documents['document.extract']!(uploaded, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
    expect(web.reportDocumentStatus).toHaveBeenLastCalledWith(11, expect.objectContaining({ status: 'failed', error_code: 'file_missing' }), 'req-1');
  });

  it('retries transient extraction errors and reports failure only at the end', async () => {
    ai.extractDocument.mockRejectedValue(new UpstreamNetworkError('ai', 'POST', '/v1/documents/11/extract', new Error('down')));
    await expect(handlers.documents['document.extract']!(uploaded, ctx())).rejects.toBeInstanceOf(UpstreamNetworkError);
    expect(web.reportDocumentStatus).not.toHaveBeenCalledWith(11, expect.objectContaining({ status: 'failed' }), 'req-1');
    await expect(handlers.documents['document.extract']!(uploaded, ctx({ isFinalAttempt: true }))).rejects.toBeInstanceOf(UpstreamNetworkError);
    expect(web.reportDocumentStatus).toHaveBeenLastCalledWith(11, expect.objectContaining({ status: 'failed', error_code: 'index_failed' }), 'req-1');
  });

  it('indexes the cached pages with a gzipped body and reports indexed', async () => {
    await extracts.set(
      extractCacheKey(11, 2),
      encodeExtraction({ pages: [{ page: 1, text: 'Hello' }], pages_total: 1, ocr_used: true, language_guess: 'en' }),
      60,
    );
    await handlers.documents['document.index']!(uploaded, ctx());
    const [documentId, body] = ai.indexDocument.mock.calls[0]!;
    expect(documentId).toBe(11);
    expect(JSON.parse(gunzipSync(body).toString('utf8'))).toEqual({
      workspace_id: 3,
      upload_rev: 2,
      title: 'Policy',
      mime: 'application/pdf',
      ocr_used: true,
      archived: false,
      metadata: { ...uploaded.metadata, language: 'en' },
      pages: [{ page: 1, text: 'Hello' }],
      ai_external_allowed: false,
    });
    expect(web.reportDocumentStatus).toHaveBeenCalledWith(
      11,
      { status: 'indexed', upload_rev: 2, pages: 1, pages_done: 1, pages_total: 1, chunks: 3, ocr_used: true },
      'req-1',
    );
    expect(await extracts.get(extractCacheKey(11, 2))).toBeNull();
    expect(ai.extractDocument).not.toHaveBeenCalled();
  });

  it('re-extracts expired pages, ignores stale revisions and reports empty text', async () => {
    ai.indexDocument.mockRejectedValueOnce(new AiDocumentError('stale_revision', 409, 'stale'));
    expect(await handlers.documents['document.index']!(uploaded, ctx())).toEqual({ stale: true });
    expect(ai.extractDocument).toHaveBeenCalledTimes(1);
    expect(web.reportDocumentStatus).not.toHaveBeenCalledWith(11, expect.objectContaining({ status: 'indexed' }), 'req-1');
    ai.indexDocument.mockRejectedValueOnce(new AiDocumentError('empty_text', 422, 'empty'));
    await expect(handlers.documents['document.index']!(uploaded, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
    expect(web.reportDocumentStatus).toHaveBeenLastCalledWith(11, expect.objectContaining({ status: 'failed', error_code: 'empty_text' }), 'req-1');
  });

  it('syncs metadata without re-embedding and skips documents that are not indexed yet', async () => {
    const payload = {
      document_id: 11,
      workspace_id: 3,
      title: 'Policy v2',
      department_ids: [1],
      roles: [],
      tags: ['x'],
      version: '2',
      effective_date: '2026-01-01',
      approval_status: 'approved',
      archived: false,
    };
    expect(await handlers.documents['document.metadata_changed']!(envelope('document.metadata_changed', payload), ctx())).toEqual({ updated_chunks: 4 });
    expect(ai.patchMetadata).toHaveBeenCalledWith(11, { ...payload, document_id: undefined }, 'req-1');
    ai.patchMetadata.mockResolvedValueOnce(null);
    expect(await handlers.documents['document.metadata_changed']!(envelope('document.metadata_changed', payload), ctx())).toEqual({ skipped: 'not_indexed' });
  });

  it('deletes chunks and re-embeds workspaces in batches with a cursor', async () => {
    await handlers.documents['document.deleted']!(envelope('document.deleted', { document_id: 11, workspace_id: 3 }), ctx());
    expect(ai.deleteDocument).toHaveBeenCalledWith(11, 3, 'req-1');
    ai.reembedBatch
      .mockResolvedValueOnce({ chunks: 10, documents: 20, next_cursor: 40 })
      .mockResolvedValueOnce({ chunks: 4, documents: 3, next_cursor: null });
    const progress = vi.fn(async () => undefined);
    expect(await handlers.documents['workspace.reembed']!(envelope('workspace.reembed', { workspace_id: 3 }), ctx({ progress }))).toEqual({ chunks: 14, documents: 23 });
    expect(ai.reembedBatch.mock.calls.map((call) => call.slice(0, 3))).toEqual([
      [3, 0, 20],
      [3, 40, 20],
    ]);
    expect(progress).toHaveBeenCalledTimes(2);
  });
});

describe('telegram-admin', () => {
  it('disconnects a company bot: webhook, chats, eviction, status callback', async () => {
    await preferences.setLocale('7', 1, 1, 'ru');
    await preferences.setLocale('platform', 1, 1, 'en');
    await groupNotices.markOnce('7', -100);
    await handlers['telegram-admin']['telegram.bot.disconnect']!(
      envelope('telegram.bot.disconnect', { bot_key: '7', telegram_bot_id: 7, workspace_id: 3 }),
      ctx(),
    );
    expect(lifecycle.stopPolling).toHaveBeenCalledWith('7');
    expect(testBot.registry.capture.offlineCalls('7').map((call) => call.method)).toEqual(['deleteWebhook']);
    expect(testBot.registry.capture.offlineCalls('7')[0]?.payload).toMatchObject({ drop_pending_updates: true });
    expect(await preferences.getLocale('7', 1)).toBeNull();
    expect(await preferences.getLocale('platform', 1)).toBe('en');
    expect(await groupNotices.markOnce('7', -100)).toBe(true);
    expect(testBot.registry.peek('7')).toBeUndefined();
    expect(lifecycle.publishEvict).toHaveBeenCalledWith('7');
    expect(web.reportBotStatus).toHaveBeenCalledWith(7, { status: 'disconnected' }, 'req-1');
  });

  it('invalidates cached telegram contexts', async () => {
    await accountsCache.set('account:7:5001', '{}', 60);
    await accountsCache.set('account:platform:5001', '{}', 60);
    await handlers['telegram-admin']['telegram.context.invalidate']!(
      envelope('telegram.context.invalidate', { workspace_id: 3, user_id: 7, keys: [{ bot_key: '7', telegram_user_id: 5001 }] }),
      ctx(),
    );
    expect(await accountsCache.get('account:7:5001')).toBeNull();
    expect(await accountsCache.get('account:platform:5001')).toBe('{}');
  });
});

describe('opsbot-schedules', () => {
  it('registers every repeatable tick once with fixed ids', async () => {
    const upserts = new Map<string, unknown>();
    const queue = { upsertJobScheduler: vi.fn(async (id: string, repeat: unknown) => upserts.set(id, repeat)) };
    await registerSchedules(queue);
    await registerSchedules(queue);
    expect([...upserts.keys()].sort()).toEqual(['opsbot.bots.reconcile', 'opsbot.documents.tick', 'opsbot.limits.tick', 'opsbot.reminders.tick']);
    expect(upserts.get('opsbot.reminders.tick')).toEqual({ every: 60_000 });
    expect(upserts.get('opsbot.bots.reconcile')).toEqual({ pattern: '0 3 * * *', tz: 'UTC' });
  });

  it('calls the web tick endpoints', async () => {
    for (const schedule of SCHEDULES) await handlers['opsbot-schedules'][schedule.name]!({}, ctx());
    expect(web.tick.mock.calls.map((call) => call[0])).toEqual(['reminders/dispatch', 'documents/tick', 'limits/tick', 'bots/reconcile']);
  });
});

describe('telegram-updates', () => {
  it('routes updates to the bot of their key and drops unknown bots', async () => {
    const update = { update_id: 5, message: { message_id: 1, date: 1, chat: { id: 1, type: 'private' }, from: { id: 1, is_bot: false, first_name: 'A' }, text: '/help' } };
    await handlers['telegram-updates']['telegram.update']!({ bot_key: '7', update }, ctx());
    expect(testBot.registry.capture.offlineCalls('7').map((call) => call.method)).toEqual(['sendMessage']);
    expect(await handlers['telegram-updates']['telegram.update']!({ bot_key: '99', update }, ctx())).toEqual({ dropped: true });
    await expect(handlers['telegram-updates']['telegram.update']!(update, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
  });
});

describe('delayed errors', () => {
  it('are not swallowed by give-up hooks', () => {
    expect(new DelayedError()).toBeInstanceOf(Error);
  });
});

describe('stripe-events', () => {
  const event = { id: 'evt_1', object: 'event', type: 'customer.subscription.updated', data: { object: {} } };

  it('forwards to web and marks the event processed', async () => {
    await events.record({ provider: 'stripe', eventId: 'evt_1', type: event.type, payload: event });
    await handlers['stripe-events']['stripe.event']!(event, ctx());
    expect(web.forwardStripeEvent).toHaveBeenCalledWith(event, 'req-1');
    expect(events.rows.get('stripe:evt_1')).toMatchObject({ processed: true });
  });

  it('records failures and retries transient errors', async () => {
    await events.record({ provider: 'stripe', eventId: 'evt_1', type: event.type, payload: event });
    web.forwardStripeEvent.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/internal/v1/stripe/events', 503, null));
    await expect(handlers['stripe-events']['stripe.event']!(event, ctx())).rejects.toBeInstanceOf(UpstreamHttpError);
    expect(events.rows.get('stripe:evt_1')).toMatchObject({ processed: false, attempts: 1 });
    expect(events.rows.get('stripe:evt_1')?.lastError).toContain('503');
  });

  it('rejects malformed events permanently', async () => {
    await expect(handlers['stripe-events']['stripe.event']!({ type: 'x' }, ctx())).rejects.toBeInstanceOf(UnrecoverableError);
  });
});

describe('billing queue', () => {
  const notification = { type: 'notification', event: 'payment.succeeded', object: { id: 'pay_1', status: 'succeeded' } };

  it('forwards YooKassa notifications and marks them processed', async () => {
    await events.record({ provider: 'yookassa', eventId: 'payment.succeeded:pay_1', type: 'payment.succeeded', payload: notification });
    await handlers.billing['yookassa.notification']!(notification, ctx());
    expect(web.forwardYooKassaNotification).toHaveBeenCalledWith(notification, 'req-1');
    expect(events.rows.get('yookassa:payment.succeeded:pay_1')).toMatchObject({ processed: true });
  });

  it('records a failed forward so the redelivery can retry it', async () => {
    await events.record({ provider: 'yookassa', eventId: 'payment.succeeded:pay_1', type: 'payment.succeeded', payload: notification });
    web.forwardYooKassaNotification.mockRejectedValueOnce(new UpstreamHttpError('web', 'POST', '/internal/v1/yookassa/notifications', 502, null));
    await expect(handlers.billing['yookassa.notification']!(notification, ctx())).rejects.toBeInstanceOf(UpstreamHttpError);
    expect(events.rows.get('yookassa:payment.succeeded:pay_1')).toMatchObject({ processed: false, attempts: 1 });
  });

  it('runs scheduled renewals through web', async () => {
    await handlers.billing['billing.renewals.due']!({ scheduled: true }, ctx());
    expect(web.runBillingRenewals).toHaveBeenCalledWith('req-1');
  });
});

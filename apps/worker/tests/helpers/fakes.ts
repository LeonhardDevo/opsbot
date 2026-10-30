import { vi } from 'vitest';
import type { AiApi, ChatResponse } from '../../src/clients/ai.js';
import type { AiQueryLogged, BotCredentials, TelegramContext, WebApi } from '../../src/clients/web.js';

export const COMPANY_TOKEN = `7000000001:TEST${'a'.repeat(30)}`;

export function linkedContext(overrides: Partial<TelegramContext> = {}): TelegramContext {
  return {
    user: { id: 7, name: 'Anna Petrova', locale: 'ru', kind: 'telegram_only' },
    workspace: { id: 3, name: 'Clean Line', plan: 'pro', timezone: 'Europe/Moscow', today: '2026-10-22', subscription_state: 'ok' },
    role: 'employee',
    permissions: ['assistant.use', 'incidents.create', 'incidents.view_own', 'tasks.create', 'tasks.view_own'],
    department_ids: [1],
    departments: [{ id: 1, name: 'Residential cleaning' }],
    privileged: false,
    telegram: { bot_key: 'platform', user_id: 5001, chat_id: 5001, username: 'anna', blocked: false },
    bot: { key: 'platform', username: 'acme_bot' },
    notice: null,
    limits: { ai_queries_exhausted: false },
    settings: { min_confidence: 0.4, ai_external_allowed: true },
    ...overrides,
  };
}

export function chatResponse(overrides: Partial<ChatResponse> = {}): ChatResponse {
  return {
    answer_markdown: 'Take three photos of the damage [1].',
    answer_html: '<p>Take three photos of the <strong>damage</strong> [1].</p>',
    citations: [
      { document_id: 11, chunk_id: 101, title: 'Client property damage', page: 2, snippet: 'three photos', version: '1.0', effective_date: '2026-01-01', tags: [] },
      { document_id: 12, chunk_id: 102, title: 'Compensation rules', page: null, snippet: null, version: null, effective_date: null, tags: [] },
    ],
    confidence: 0.82,
    confidence_band: 'high',
    refused: false,
    refusal_reason: null,
    answer_kind: 'document',
    disclaimers: [],
    tools_used: [],
    actions: [
      { type: 'create_incident', label: 'Create incident', payload: { category: 'damage', severity: 'medium', description: 'x' } },
      { type: 'open_source', label: 'Open source', payload: { document_id: 11, chunk_id: 101 } },
    ],
    provider: 'mock',
    model: 'mock-1',
    latency_ms: 42,
    ...overrides,
  };
}

export function loggedQuery(overrides: Partial<AiQueryLogged> = {}): AiQueryLogged {
  return {
    id: 501,
    actions: [
      { id: 'act-incident', type: 'create_incident', label: 'Create incident', url: null, status: 'proposed' },
      { id: 'act-source', type: 'open_source', label: 'Open source', url: 'https://app.acme.example/s/doc/abc', status: 'proposed' },
    ],
    citations: [
      { index: 1, url: 'https://app.acme.example/s/doc/abc' },
      { index: 2, url: 'http://127.0.0.1:8200/s/doc/def' },
    ],
    ...overrides,
  };
}

export function fakeWeb(contexts: Map<number, TelegramContext> = new Map(), credentials: Map<number, BotCredentials> = new Map()) {
  return {
    ping: vi.fn<WebApi['ping']>(async () => true),
    linkTelegram: vi.fn<WebApi['linkTelegram']>(async () => ({
      ok: true,
      userName: 'Anna Petrova',
      workspaceName: 'Clean Line',
      locale: 'ru',
      noticeRequired: false,
    })),
    unlinkTelegram: vi.fn<WebApi['unlinkTelegram']>(async () => undefined),
    acknowledgeNotice: vi.fn<WebApi['acknowledgeNotice']>(async () => undefined),
    telegramContext: vi.fn<WebApi['telegramContext']>(async (lookup) => {
      if ('botKey' in lookup) {
        const context = contexts.get(lookup.telegramUserId) ?? null;
        if (context?.telegram?.bot_key && context.telegram.bot_key !== lookup.botKey) return null;
        return context;
      }
      for (const context of contexts.values()) if (context.user.id === lookup.userId) return context;
      return null;
    }),
    reportDelivery: vi.fn<WebApi['reportDelivery']>(async () => undefined),
    forwardStripeEvent: vi.fn<WebApi['forwardStripeEvent']>(async () => undefined),
    reportDocumentStatus: vi.fn<WebApi['reportDocumentStatus']>(async () => undefined),
    downloadDocument: vi.fn<WebApi['downloadDocument']>(async () => ({
      data: Buffer.from('# Title\n\nText'),
      contentType: 'text/markdown',
      filename: 'doc.md',
    })),
    logAiQuery: vi.fn<WebApi['logAiQuery']>(async () => loggedQuery()),
    telegramAction: vi.fn<WebApi['telegramAction']>(async () => ({ ok: true, message: 'Approved', message_html: null })),
    activeBots: vi.fn<WebApi['activeBots']>(async () => []),
    botCredentials: vi.fn<WebApi['botCredentials']>(async (botId) => credentials.get(botId) ?? null),
    reportBotStatus: vi.fn<WebApi['reportBotStatus']>(async () => undefined),
    reportChatBlocked: vi.fn<WebApi['reportChatBlocked']>(async () => undefined),
    opsbotTasks: vi.fn<WebApi['opsbotTasks']>(async () => []),
    opsbotHistory: vi.fn<WebApi['opsbotHistory']>(async () => []),
    opsbotQueue: vi.fn<WebApi['opsbotQueue']>(async () => []),
    createIncident: vi.fn<WebApi['createIncident']>(async () => ({
      incident: { id: 12, display_number: '№ 12' },
      message_html: 'Инцидент <b>№ 12</b> создан. Ответственный: Олег Петров.',
    })),
    escalateLastQuestion: vi.fn<WebApi['escalateLastQuestion']>(async () => ({ ok: true, message_html: 'Passed to Oleg.' })),
    answerQuestion: vi.fn<WebApi['answerQuestion']>(async () => ({ ok: true, message_html: 'Answer sent to Anna.' })),
    tick: vi.fn<WebApi['tick']>(async () => ({ sent: 0 })),
    privacyPurge: vi.fn<WebApi['privacyPurge']>(async () => ({
      deletions: { users: 1, workspaces: 0, blocked: 0, failed: 0 },
      retention: { ai_queries: 4 },
      webhook_events_days: 30,
      skipped: false,
    })),
    buildExport: vi.fn<WebApi['buildExport']>(async (id) => ({ id, status: 'ready' })),
    forwardYooKassaNotification: vi.fn<WebApi['forwardYooKassaNotification']>(async () => undefined),
    runBillingRenewals: vi.fn<WebApi['runBillingRenewals']>(async () => ({ due: 0 })),
  } satisfies WebApi;
}

export function fakeAi(response: ChatResponse = chatResponse()) {
  return {
    chat: vi.fn<AiApi['chat']>(async () => response),
    extractDocument: vi.fn<AiApi['extractDocument']>(async () => ({
      pages: [{ page: 1, text: '# Title\n\nText' }],
      pages_total: 1,
      ocr_used: false,
      language_guess: 'en',
    })),
    indexDocument: vi.fn<AiApi['indexDocument']>(async () => ({ pages: 1, chunks: 3 })),
    patchMetadata: vi.fn<AiApi['patchMetadata']>(async () => 4),
    deleteDocument: vi.fn<AiApi['deleteDocument']>(async () => undefined),
    reembedBatch: vi.fn<AiApi['reembedBatch']>(async () => ({ chunks: 3, documents: 1, next_cursor: null })),
    purgeWorkspace: vi.fn<AiApi['purgeWorkspace']>(async () => undefined),
    purgeUser: vi.fn<AiApi['purgeUser']>(async () => undefined),
    pruneMemory: vi.fn<AiApi['pruneMemory']>(async () => undefined),
  } satisfies AiApi;
}

export function companyCredentials(overrides: Partial<BotCredentials> = {}): BotCredentials {
  return {
    bot_key: '7',
    tg_bot_id: 7000000001,
    username: 'clean_line_bot',
    token: COMPANY_TOKEN,
    webhook_secret: 'company-webhook-secret-0123456789',
    status: 'active',
    ...overrides,
  };
}

import { z } from 'zod';
import { internalFetch, internalFetchBinary, type BinaryResponse, type InternalTarget } from './internalFetch.js';
import { UpstreamHttpError } from './errors.js';

const localeSchema = z
  .string()
  .nullish()
  .transform((value) => (value === 'ru' || value === 'en' ? value : null));

export const subscriptionStateSchema = z.enum(['ok', 'grace', 'paused']).catch('ok');

export const telegramContextSchema = z.object({
  user: z.object({
    id: z.number(),
    name: z.string(),
    locale: localeSchema,
    kind: z.enum(['web', 'telegram_only']).catch('web').default('web'),
  }),
  workspace: z.object({
    id: z.number(),
    name: z.string(),
    plan: z.string().nullish(),
    timezone: z.string().nullish().transform((value) => value ?? 'UTC'),
    today: z.string().nullish(),
    subscription_state: subscriptionStateSchema.default('ok'),
  }),
  role: z.string().nullish().transform((value) => value ?? 'employee'),
  permissions: z.array(z.string()).default([]),
  department_ids: z.array(z.number()).default([]),
  departments: z.array(z.object({ id: z.number(), name: z.string() })).nullish(),
  privileged: z.boolean().default(false),
  telegram: z
    .object({
      bot_key: z.string().nullish(),
      user_id: z.number().nullish(),
      chat_id: z.number().nullish(),
      username: z.string().nullish(),
      blocked: z.boolean().default(false),
    })
    .nullish(),
  bot: z.object({ key: z.string(), username: z.string().nullish() }).nullish(),
  notice: z.object({ version: z.string(), html: z.string() }).nullish().transform((value) => value ?? null),
  limits: z.object({ ai_queries_exhausted: z.boolean().default(false) }).default({ ai_queries_exhausted: false }),
  settings: z
    .object({ min_confidence: z.number().min(0).max(1).default(0.4), ai_external_allowed: z.boolean().default(false) })
    .default({ min_confidence: 0.4, ai_external_allowed: false }),
});
export type TelegramContext = z.infer<typeof telegramContextSchema>;

const linkResponseSchema = z
  .object({
    user: z.object({ id: z.number().optional(), name: z.string().optional(), locale: localeSchema.optional() }).nullish(),
    workspace: z.object({ id: z.number().optional(), name: z.string().optional() }).nullish(),
    notice_required: z.boolean().default(false),
  })
  .passthrough()
  .nullable();

export interface LinkTelegramInput {
  bot_key: string;
  token: string;
  telegram_user_id: number;
  chat_id: number;
  username: string | null;
  language_code: string | null;
}

export type LinkTelegramResult =
  | { ok: true; userName: string | null; workspaceName: string | null; locale: 'ru' | 'en' | null; noticeRequired: boolean }
  | { ok: false; reason: 'invalid_token' | 'conflict' | 'wrong_bot' };

export interface DeliveryReport {
  reference: { type: string; id: string | number };
  channel: 'email' | 'telegram';
  status: 'sent' | 'failed';
  error?: string;
  provider_message_id?: string;
}

export interface DocumentStatusReport {
  status: 'processing' | 'indexed' | 'failed';
  upload_rev: number;
  pages?: number;
  chunks?: number;
  pages_done?: number;
  pages_total?: number;
  ocr_used?: boolean;
  error?: string;
  error_code?: string;
}

export interface AiQueryAction {
  type: string;
  label: string;
  payload: Record<string, unknown>;
}

export interface AiQueryLog {
  workspace_id: number;
  user_id: number | null;
  channel: 'telegram' | 'web';
  bot_key: string | null;
  conversation_id: string | null;
  question: string;
  answer: string | null;
  answer_kind: 'document' | 'tool_data';
  refused: boolean;
  refusal_reason: string | null;
  confidence: number | null;
  citations: Array<{ document_id: number; chunk_id: number | null; page: number | null; version: string | null; title: string }>;
  tools_used: string[];
  latency_ms: number | null;
  provider: string | null;
  model: string | null;
  role_at_time: string;
  department_ids_at_time: number[];
  disclaimers: string[];
  actions: AiQueryAction[];
}

export const proposedActionSchema = z.object({
  id: z.string().min(1),
  type: z.string().min(1),
  label: z.string().min(1),
  url: z.string().nullish(),
  status: z.string().default('proposed'),
});
export type ProposedAction = z.infer<typeof proposedActionSchema>;

const aiQueryLoggedSchema = z.object({
  id: z.number(),
  actions: z.array(proposedActionSchema).default([]),
  citations: z.array(z.object({ index: z.number(), url: z.string().nullish() })).default([]),
});
export type AiQueryLogged = z.infer<typeof aiQueryLoggedSchema>;

export interface TelegramActionInput {
  bot_key: string;
  type: string;
  payload: Record<string, unknown>;
  telegram_user_id: number;
  chat_id: number;
  workspace_id: number | null;
  user_id: number | null;
  locale: 'ru' | 'en';
}

export const webButtonSchema = z.object({
  text: z.string().min(1).max(64),
  url: z.string().nullish(),
  callback: z.union([z.string().min(1).max(500), z.object({ type: z.string().min(1), payload: z.record(z.string(), z.unknown()).default({}) })]).nullish(),
});
export type WebButton = z.infer<typeof webButtonSchema>;

const actionResponseSchema = z
  .object({
    ok: z.boolean().default(true),
    message: z.string().nullish(),
    message_html: z.string().nullish(),
    alert: z.boolean().optional(),
    buttons: z.array(webButtonSchema).nullish(),
  })
  .passthrough();
export type TelegramActionResult = z.infer<typeof actionResponseSchema>;

const messageResponseSchema = z
  .object({ ok: z.boolean().default(true), message_html: z.string().nullish(), buttons: z.array(webButtonSchema).nullish() })
  .passthrough();
export type MessageResult = z.infer<typeof messageResponseSchema>;

export const botCredentialsSchema = z.object({
  bot_key: z.string().regex(/^(platform|[1-9][0-9]{0,18})$/),
  tg_bot_id: z.number().int().positive(),
  username: z.string().nullish(),
  token: z.string().min(1),
  webhook_secret: z.string().nullish(),
  status: z.string(),
});
export type BotCredentials = z.infer<typeof botCredentialsSchema>;

const activeBotsSchema = z.object({
  bots: z.array(z.object({ bot_key: z.string(), status: z.string(), mode: z.string().nullish() })).default([]),
});

const taskItemSchema = z.object({
  id: z.number(),
  display_number: z.string(),
  title: z.string(),
  status: z.string(),
  due_at: z.string().nullish(),
  due_local: z.string().nullish(),
  overdue: z.boolean().default(false),
});
export type TaskItem = z.infer<typeof taskItemSchema>;

const historyItemSchema = z.object({
  asked_local: z.string(),
  question: z.string(),
  refused: z.boolean().default(false),
  manager_answered: z.boolean().default(false),
});
export type HistoryItem = z.infer<typeof historyItemSchema>;

const queueItemSchema = z.object({
  id: z.number(),
  display_number: z.string(),
  question_excerpt: z.string(),
  employee_name: z.string(),
  age_hours: z.number().default(0),
});
export type QueueItem = z.infer<typeof queueItemSchema>;

const incidentCreatedSchema = z.object({
  incident: z.object({ id: z.number(), display_number: z.string() }),
  message_html: z.string(),
});
export type IncidentCreated = z.infer<typeof incidentCreatedSchema>;

const tickSchema = z.record(z.string(), z.unknown()).nullable();

export type ContextLookup = { botKey: string; telegramUserId: number } | { workspaceId: number; userId: number };

export interface DownloadedDocument {
  data: Buffer;
  contentType: string | null;
  filename: string | null;
}

export const privacyPurgeResponseSchema = z
  .object({
    deletions: z.record(z.string(), z.number()).default({}),
    retention: z.record(z.string(), z.number()).default({}),
    webhook_events_days: z.number().int().positive().default(30),
    ai_memory: z
      .object({
        default_days: z.number().int().positive(),
        workspaces: z.array(z.object({ workspace_id: z.number().int().positive(), days: z.number().int().positive() })).default([]),
      })
      .nullish(),
    skipped: z.boolean().default(false),
  })
  .passthrough();
export type PrivacyPurgeResult = z.infer<typeof privacyPurgeResponseSchema>;

const exportBuildResponseSchema = z.object({ id: z.number(), status: z.string() }).passthrough();
export type ExportBuildResult = z.infer<typeof exportBuildResponseSchema>;

const billingRunResponseSchema = z.record(z.string(), z.union([z.number(), z.boolean()]));
export type BillingRunResult = z.infer<typeof billingRunResponseSchema>;

export interface WebApi {
  ping(requestId?: string): Promise<boolean>;
  linkTelegram(input: LinkTelegramInput, requestId?: string): Promise<LinkTelegramResult>;
  unlinkTelegram(botKey: string, telegramUserId: number, requestId?: string): Promise<void>;
  acknowledgeNotice(botKey: string, telegramUserId: number, version: string, requestId?: string): Promise<void>;
  telegramContext(lookup: ContextLookup, requestId?: string): Promise<TelegramContext | null>;
  reportDelivery(report: DeliveryReport, requestId?: string): Promise<void>;
  forwardStripeEvent(event: unknown, requestId?: string): Promise<void>;
  reportDocumentStatus(documentId: number, report: DocumentStatusReport, requestId?: string): Promise<void>;
  downloadDocument(documentId: number, maxBytes: number, requestId?: string): Promise<DownloadedDocument>;
  logAiQuery(entry: AiQueryLog, requestId?: string): Promise<AiQueryLogged>;
  telegramAction(input: TelegramActionInput, requestId?: string): Promise<TelegramActionResult>;
  activeBots(requestId?: string): Promise<Array<{ bot_key: string; status: string; mode?: string | null }>>;
  botCredentials(botId: number, requestId?: string): Promise<BotCredentials | null>;
  reportBotStatus(
    botId: number,
    report: { status: 'active' | 'revoked' | 'disconnected'; error_code?: string; seen_at?: string },
    requestId?: string,
  ): Promise<void>;
  reportChatBlocked(botKey: string, telegramUserId: number, blocked: boolean, requestId?: string): Promise<void>;
  opsbotTasks(query: { workspace_id: number; user_id: number; status: 'open' | 'overdue'; limit?: number }, requestId?: string): Promise<TaskItem[]>;
  opsbotHistory(botKey: string, telegramUserId: number, requestId?: string): Promise<HistoryItem[]>;
  opsbotQueue(botKey: string, telegramUserId: number, requestId?: string): Promise<QueueItem[] | null>;
  createIncident(
    input: { bot_key: string; telegram_user_id: number; category: string; description: string; client_ref?: string },
    requestId?: string,
  ): Promise<IncidentCreated>;
  escalateLastQuestion(botKey: string, telegramUserId: number, requestId?: string): Promise<MessageResult>;
  answerQuestion(questionId: number, input: { bot_key: string; telegram_user_id: number; text: string }, requestId?: string): Promise<MessageResult>;
  tick(name: 'reminders/dispatch' | 'documents/tick' | 'limits/tick' | 'bots/reconcile', requestId?: string): Promise<Record<string, unknown> | null>;
  privacyPurge(requestId?: string): Promise<PrivacyPurgeResult>;
  buildExport(exportId: number, requestId?: string): Promise<ExportBuildResult>;
  forwardYooKassaNotification(notification: unknown, requestId?: string): Promise<void>;
  runBillingRenewals(requestId?: string): Promise<BillingRunResult>;
}

export class WebClient implements WebApi {
  constructor(private readonly target: InternalTarget) {}

  async ping(requestId?: string): Promise<boolean> {
    await internalFetch(this.target, { method: 'GET', path: '/internal/v1/ping', requestId });
    return true;
  }

  async linkTelegram(input: LinkTelegramInput, requestId?: string): Promise<LinkTelegramResult> {
    try {
      const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/telegram/link', body: input, requestId });
      const parsed = linkResponseSchema.safeParse(response.data);
      const data = parsed.success ? parsed.data : null;
      return {
        ok: true,
        userName: data?.user?.name ?? null,
        workspaceName: data?.workspace?.name ?? null,
        locale: data?.user?.locale ?? null,
        noticeRequired: data?.notice_required ?? false,
      };
    } catch (error) {
      if (error instanceof UpstreamHttpError) {
        const code = (error.body as { error?: unknown } | null)?.error;
        if (error.status === 422 && code === 'wrong_bot') return { ok: false, reason: 'wrong_bot' };
        if ([400, 404, 410, 422].includes(error.status)) return { ok: false, reason: 'invalid_token' };
        if (error.status === 409) return { ok: false, reason: 'conflict' };
      }
      throw error;
    }
  }

  async unlinkTelegram(botKey: string, telegramUserId: number, requestId?: string): Promise<void> {
    await internalFetch(this.target, {
      method: 'POST',
      path: '/internal/v1/telegram/unlink',
      body: { bot_key: botKey, telegram_user_id: telegramUserId },
      requestId,
    });
  }

  async acknowledgeNotice(botKey: string, telegramUserId: number, version: string, requestId?: string): Promise<void> {
    await internalFetch(this.target, {
      method: 'POST',
      path: '/internal/v1/telegram/notice/ack',
      body: { bot_key: botKey, telegram_user_id: telegramUserId, version },
      requestId,
    });
  }

  async telegramContext(lookup: ContextLookup, requestId?: string): Promise<TelegramContext | null> {
    const query =
      'botKey' in lookup
        ? { bot_key: lookup.botKey, telegram_user_id: lookup.telegramUserId }
        : { workspace_id: lookup.workspaceId, user_id: lookup.userId };
    try {
      const response = await internalFetch(this.target, { method: 'GET', path: '/internal/v1/telegram/context', query, requestId });
      return telegramContextSchema.parse(response.data);
    } catch (error) {
      if (error instanceof UpstreamHttpError && error.status === 404) return null;
      throw error;
    }
  }

  async reportDelivery(report: DeliveryReport, requestId?: string): Promise<void> {
    await internalFetch(this.target, { method: 'POST', path: '/internal/v1/deliveries', body: report, requestId });
  }

  async forwardStripeEvent(event: unknown, requestId?: string): Promise<void> {
    await internalFetch(this.target, { method: 'POST', path: '/internal/v1/stripe/events', body: event, requestId });
  }

  async reportDocumentStatus(documentId: number, report: DocumentStatusReport, requestId?: string): Promise<void> {
    await internalFetch(this.target, {
      method: 'POST',
      path: `/internal/v1/documents/${documentId}/status`,
      body: report,
      requestId,
    });
  }

  async downloadDocument(documentId: number, maxBytes: number, requestId?: string): Promise<DownloadedDocument> {
    const response: BinaryResponse = await internalFetchBinary(
      this.target,
      { method: 'GET', path: `/internal/v1/documents/${documentId}/file`, requestId, timeoutMs: Math.max(this.target.timeoutMs, 120_000) },
      maxBytes,
    );
    return { data: response.data, contentType: response.contentType, filename: response.filename };
  }

  async logAiQuery(entry: AiQueryLog, requestId?: string): Promise<AiQueryLogged> {
    const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/ai-queries', body: entry, requestId });
    return aiQueryLoggedSchema.parse(response.data);
  }

  async telegramAction(input: TelegramActionInput, requestId?: string): Promise<TelegramActionResult> {
    const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/telegram/actions', body: input, requestId });
    return actionResponseSchema.parse(response.data ?? {});
  }

  async activeBots(requestId?: string): Promise<Array<{ bot_key: string; status: string; mode?: string | null }>> {
    const response = await internalFetch(this.target, { method: 'GET', path: '/internal/v1/telegram/bots/active', requestId });
    return activeBotsSchema.parse(response.data ?? {}).bots;
  }

  async botCredentials(botId: number, requestId?: string): Promise<BotCredentials | null> {
    try {
      const response = await internalFetch(this.target, {
        method: 'GET',
        path: `/internal/v1/telegram/bots/${botId}/credentials`,
        requestId,
      });
      return botCredentialsSchema.parse(response.data);
    } catch (error) {
      if (error instanceof UpstreamHttpError && (error.status === 404 || error.status === 410)) return null;
      throw error;
    }
  }

  async reportBotStatus(
    botId: number,
    report: { status: 'active' | 'revoked' | 'disconnected'; error_code?: string; seen_at?: string },
    requestId?: string,
  ): Promise<void> {
    await internalFetch(this.target, { method: 'POST', path: `/internal/v1/telegram/bots/${botId}/status`, body: report, requestId });
  }

  async reportChatBlocked(botKey: string, telegramUserId: number, blocked: boolean, requestId?: string): Promise<void> {
    await internalFetch(this.target, {
      method: 'POST',
      path: '/internal/v1/telegram/chats/blocked',
      body: { bot_key: botKey, telegram_user_id: telegramUserId, blocked },
      requestId,
    });
  }

  async opsbotTasks(
    query: { workspace_id: number; user_id: number; status: 'open' | 'overdue'; limit?: number },
    requestId?: string,
  ): Promise<TaskItem[]> {
    const response = await internalFetch(this.target, {
      method: 'GET',
      path: '/internal/v1/opsbot/tasks',
      query: { ...query, limit: query.limit ?? 10 },
      requestId,
    });
    return z.object({ tasks: z.array(taskItemSchema).default([]) }).parse(response.data ?? {}).tasks;
  }

  async opsbotHistory(botKey: string, telegramUserId: number, requestId?: string): Promise<HistoryItem[]> {
    const response = await internalFetch(this.target, {
      method: 'GET',
      path: '/internal/v1/opsbot/history',
      query: { bot_key: botKey, telegram_user_id: telegramUserId, limit: 10 },
      requestId,
    });
    return z.object({ items: z.array(historyItemSchema).default([]) }).parse(response.data ?? {}).items;
  }

  async opsbotQueue(botKey: string, telegramUserId: number, requestId?: string): Promise<QueueItem[] | null> {
    try {
      const response = await internalFetch(this.target, {
        method: 'GET',
        path: '/internal/v1/opsbot/queue',
        query: { bot_key: botKey, telegram_user_id: telegramUserId },
        requestId,
      });
      return z.object({ items: z.array(queueItemSchema).default([]) }).parse(response.data ?? {}).items;
    } catch (error) {
      if (error instanceof UpstreamHttpError && error.status === 403) return null;
      throw error;
    }
  }

  async createIncident(
    input: { bot_key: string; telegram_user_id: number; category: string; description: string; client_ref?: string },
    requestId?: string,
  ): Promise<IncidentCreated> {
    const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/opsbot/incidents', body: input, requestId });
    return incidentCreatedSchema.parse(response.data);
  }

  async escalateLastQuestion(botKey: string, telegramUserId: number, requestId?: string): Promise<MessageResult> {
    const response = await internalFetch(this.target, {
      method: 'POST',
      path: '/internal/v1/opsbot/questions/escalate-last',
      body: { bot_key: botKey, telegram_user_id: telegramUserId },
      requestId,
    });
    return messageResponseSchema.parse(response.data ?? {});
  }

  async answerQuestion(
    questionId: number,
    input: { bot_key: string; telegram_user_id: number; text: string },
    requestId?: string,
  ): Promise<MessageResult> {
    const response = await internalFetch(this.target, {
      method: 'POST',
      path: `/internal/v1/opsbot/questions/${questionId}/answer`,
      body: input,
      requestId,
    });
    return messageResponseSchema.parse(response.data ?? {});
  }

  async tick(
    name: 'reminders/dispatch' | 'documents/tick' | 'limits/tick' | 'bots/reconcile',
    requestId?: string,
  ): Promise<Record<string, unknown> | null> {
    const response = await internalFetch(this.target, { method: 'POST', path: `/internal/v1/opsbot/${name}`, body: {}, requestId });
    return tickSchema.parse(response.data ?? null);
  }

  async privacyPurge(requestId?: string): Promise<PrivacyPurgeResult> {
    const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/privacy/purge', body: {}, requestId });
    return privacyPurgeResponseSchema.parse(response.data ?? {});
  }

  async buildExport(exportId: number, requestId?: string): Promise<ExportBuildResult> {
    const response = await internalFetch(this.target, {
      method: 'POST',
      path: `/internal/v1/privacy/exports/${encodeURIComponent(String(exportId))}/build`,
      body: {},
      requestId,
    });
    return exportBuildResponseSchema.parse(response.data);
  }

  async forwardYooKassaNotification(notification: unknown, requestId?: string): Promise<void> {
    await internalFetch(this.target, { method: 'POST', path: '/internal/v1/yookassa/notifications', body: notification, requestId });
  }

  async runBillingRenewals(requestId?: string): Promise<BillingRunResult> {
    const response = await internalFetch(this.target, { method: 'POST', path: '/internal/v1/billing/renewals', body: {}, requestId });
    return billingRunResponseSchema.parse(response.data ?? {});
  }
}

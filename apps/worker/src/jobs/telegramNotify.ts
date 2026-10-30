import { z } from 'zod';
import { buildKeyboard } from '../bot/helpers.js';
import { escapeHtml, toTelegramHtml } from '../bot/format.js';
import type { NotifyButton } from '../bot/types.js';
import type { TelegramContext } from '../clients/web.js';
import { DEFAULT_LOCALE, type Locale } from '../i18n/index.js';
import {
  TelegramBotRevokedError,
  TelegramBotUnavailableError,
  TelegramChatBlockedError,
  TelegramRateDelay,
} from '../telegram/sender.js';
import { SENT_MARKER_TTL_S, type JobDeps } from './deps.js';
import { errorMessage, parseOutboxJob, permanent, referenceSchema, withGiveUp, type JobContext, type JobHandler } from './types.js';

const botKeySchema = z.string().regex(/^(platform|[1-9][0-9]{0,18})$/);

const buttonSchema = z
  .object({
    text: z.string().trim().min(1).max(64),
    url: z.string().url().optional(),
    callback: z
      .union([z.string().trim().min(1).max(500), z.object({ type: z.string().min(1), payload: z.record(z.string(), z.unknown()).default({}) })])
      .optional(),
  })
  .refine((button) => Boolean(button.url ?? button.callback), { message: 'button needs url or callback' });

export const telegramNotifyPayloadSchema = z
  .object({
    workspace_id: z.number().int().positive().optional(),
    bot_key: botKeySchema.optional(),
    user_id: z.number().int().positive().optional(),
    chat_id: z.union([z.number().int(), z.string().regex(/^-?\d+$/)]).optional(),
    text_html: z.string().min(1).optional(),
    template: z.string().min(1).optional(),
    params: z.record(z.string(), z.unknown()).optional(),
    locale: z.enum(['ru', 'en']).optional(),
    buttons: z.array(buttonSchema).max(10).optional(),
    reference: referenceSchema,
  })
  .refine((payload) => payload.user_id !== undefined || payload.chat_id !== undefined, { message: 'user_id or chat_id is required' })
  .refine((payload) => payload.chat_id === undefined || payload.bot_key !== undefined, { message: 'chat_id requires bot_key' })
  .refine((payload) => Boolean(payload.text_html ?? payload.template), { message: 'text_html or template is required' });
export type TelegramNotifyPayload = z.infer<typeof telegramNotifyPayloadSchema>;

export interface NotificationRequest {
  outboxId: string;
  workspaceId: number;
  userId: number | null;
  chatId: number | null;
  botKey: string | null;
  locale: Locale | null;
  reference: { type: string; id: string | number };
  render: (locale: Locale) => { html: string; buttons: NotifyButton[] };
}

interface Recipient {
  botKey: string;
  chatId: number;
  telegramUserId: number | null;
  locale: Locale;
  context: TelegramContext | null;
}

type Resolution = { recipient: Recipient } | { failure: 'telegram_not_linked' | 'chat_blocked' };

async function resolveRecipient(deps: JobDeps, request: NotificationRequest, ctx: JobContext): Promise<Resolution> {
  if (request.chatId !== null && request.botKey !== null) {
    return {
      recipient: {
        botKey: request.botKey,
        chatId: request.chatId,
        telegramUserId: request.chatId > 0 ? request.chatId : null,
        locale: request.locale ?? DEFAULT_LOCALE,
        context: null,
      },
    };
  }
  if (request.userId === null) return { failure: 'telegram_not_linked' };
  const context = await deps.web.telegramContext({ workspaceId: request.workspaceId, userId: request.userId }, ctx.requestId);
  if (!context?.telegram) return { failure: 'telegram_not_linked' };
  if (context.telegram.blocked) return { failure: 'chat_blocked' };
  const chatId = context.telegram.chat_id ?? context.telegram.user_id ?? null;
  if (chatId === null) return { failure: 'telegram_not_linked' };
  return {
    recipient: {
      botKey: request.botKey ?? context.telegram.bot_key ?? context.bot?.key ?? 'platform',
      chatId,
      telegramUserId: context.telegram.user_id ?? null,
      locale: request.locale ?? context.user.locale ?? DEFAULT_LOCALE,
      context,
    },
  };
}

export async function deliverNotification(deps: JobDeps, request: NotificationRequest, ctx: JobContext): Promise<Record<string, unknown>> {
  const markerKey = `sent:telegram:${request.outboxId}`;
  const report = async (status: 'sent' | 'failed', extra: { error?: string; provider_message_id?: string }): Promise<void> => {
    await deps.web.reportDelivery({ reference: request.reference, channel: 'telegram', status, ...extra }, ctx.requestId);
  };

  const outcome = await withGiveUp(
    ctx,
    async (): Promise<{ messageId: string } | { failure: string }> => {
      const already = await deps.sentMarkers.get(markerKey);
      if (already !== null) return { messageId: already };
      const resolution = await resolveRecipient(deps, request, ctx);
      if ('failure' in resolution) return { failure: resolution.failure };
      const { recipient } = resolution;
      const rendered = request.render(recipient.locale);
      const html = toTelegramHtml(rendered.html) || deps.translator.t(recipient.locale, 'notify.fallback');
      const keyboard = await buildKeyboard(
        rendered.buttons,
        {
          botKey: recipient.botKey,
          telegramUserId: recipient.telegramUserId,
          chatId: recipient.chatId,
          workspaceId: request.workspaceId,
          userId: recipient.context?.user.id ?? request.userId,
          locale: recipient.locale,
        },
        deps.actions,
        deps.settings.notificationActionTtlS,
      );
      try {
        const message = await deps.sender.send({
          botKey: recipient.botKey,
          chatId: recipient.chatId,
          html,
          ...(keyboard ? { keyboard } : {}),
          telegramUserId: recipient.telegramUserId,
          requestId: ctx.requestId,
        });
        const messageId = String(message.message_id);
        await deps.sentMarkers.set(markerKey, messageId, SENT_MARKER_TTL_S);
        ctx.logger.info({ botKey: recipient.botKey, messageId, reference: request.reference }, 'telegram notification sent');
        return { messageId };
      } catch (error) {
        if (error instanceof TelegramRateDelay) return ctx.delay(error.waitMs);
        if (error instanceof TelegramChatBlockedError) return { failure: 'chat_blocked' };
        if (error instanceof TelegramBotUnavailableError) return { failure: 'telegram_not_linked' };
        if (error instanceof TelegramBotRevokedError) throw permanent(error.message);
        throw error;
      }
    },
    async (error) => report('failed', { error: errorMessage(error) }),
  );

  if ('failure' in outcome) {
    ctx.logger.info({ userId: request.userId, reference: request.reference, reason: outcome.failure }, 'telegram notification not delivered');
    await report('failed', { error: outcome.failure });
    return { skipped: outcome.failure };
  }
  await report('sent', { provider_message_id: outcome.messageId });
  return { messageId: outcome.messageId };
}

function renderFoundation(deps: JobDeps, payload: TelegramNotifyPayload, locale: Locale): { html: string; buttons: NotifyButton[] } {
  const extra: NotifyButton[] = (payload.buttons ?? []).map((button) => ({
    text: button.text,
    url: button.url ?? null,
    callback: button.callback ?? null,
  }));
  if (payload.text_html) return { html: payload.text_html, buttons: extra };
  const name = payload.template ?? '';
  const template = deps.modules.notification(name);
  if (!template) throw permanent(`Unknown notification template "${name}"`);
  const rendered = template(payload.params ?? {}, {
    locale,
    t: deps.translator.forLocale(locale),
    plural: (key, count, params) => deps.translator.plural(locale, key, count, params),
    escape: escapeHtml,
  });
  return { html: rendered.html, buttons: [...(rendered.buttons ?? []), ...extra] };
}

export function createTelegramNotifyHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const job = parseOutboxJob(data, telegramNotifyPayloadSchema);
    const { payload } = job;
    const workspaceId = payload.workspace_id ?? job.workspace_id;
    if (workspaceId === null || workspaceId === undefined) throw permanent('telegram.notify requires workspace_id');
    if (payload.template && !deps.modules.notification(payload.template)) {
      throw permanent(`Unknown notification template "${payload.template}"`);
    }
    return deliverNotification(
      deps,
      {
        outboxId: job.outbox_id,
        workspaceId,
        userId: payload.user_id ?? null,
        chatId: payload.chat_id === undefined ? null : Number(payload.chat_id),
        botKey: payload.bot_key ?? null,
        locale: payload.locale ?? null,
        reference: payload.reference,
        render: (locale) => renderFoundation(deps, payload, locale),
      },
      ctx,
    );
  };
}

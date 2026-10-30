import { GrammyError, InlineKeyboard } from 'grammy';
import { PLATFORM_BOT_KEY } from './botRegistry.js';
import { buttonLabel, escapeHtml, isPublicHttpUrl, splitTelegramHtml, stripHtml, toTelegramHtml } from './format.js';
import { ACTION_CALLBACK_PREFIX, type ActionStore, type StoredAction } from './stores.js';
import type { BotContext, ButtonCallback, NotifyButton } from './types.js';
import type { Locale } from '../i18n/index.js';

export const INTEGRATIONS_PATH = '/settings/integrations';

export function settingsUrl(ctx: BotContext): string {
  return `${ctx.services.settings.webPublicUrl}${INTEGRATIONS_PATH}`;
}

export function isPrivateChat(ctx: BotContext): boolean {
  return ctx.chat?.type === 'private';
}

export function withSettingsLink(ctx: BotContext, html: string): { html: string; keyboard?: InlineKeyboard } {
  const url = settingsUrl(ctx);
  if (isPublicHttpUrl(url)) {
    return { html, keyboard: new InlineKeyboard().url(ctx.t('start.open_settings'), url) };
  }
  return { html: `${html}\n\n${ctx.t('unlinked.settings_url', { url: escapeHtml(url) })}` };
}

export async function replyHtml(ctx: BotContext, html: string, keyboard?: InlineKeyboard): Promise<{ message_id: number }> {
  return ctx.reply(html, {
    parse_mode: 'HTML',
    link_preview_options: { is_disabled: true },
    ...(keyboard ? { reply_markup: keyboard } : {}),
  });
}

export async function sendLongHtml(ctx: BotContext, html: string, keyboard?: InlineKeyboard): Promise<void> {
  const parts = splitTelegramHtml(toTelegramHtml(html));
  for (const [index, part] of parts.entries()) {
    const markup = index === parts.length - 1 ? keyboard : undefined;
    try {
      await replyHtml(ctx, part, markup);
    } catch (error) {
      if (!(error instanceof GrammyError && error.error_code === 400 && /parse entities/i.test(error.description))) throw error;
      ctx.log.warn({ description: error.description }, 'telegram rejected HTML; sending plain text');
      await ctx.reply(stripHtml(part), markup ? { reply_markup: markup } : {});
    }
  }
}

export async function replyUnlinked(ctx: BotContext): Promise<void> {
  if (ctx.botKey !== PLATFORM_BOT_KEY) {
    await replyHtml(ctx, ctx.t('unlinked.company'));
    return;
  }
  const { html, keyboard } = withSettingsLink(ctx, ctx.t('unlinked.message'));
  await replyHtml(ctx, html, keyboard);
}

export function parseCallback(callback: ButtonCallback): { type: string; payload: Record<string, unknown> } {
  if (typeof callback !== 'string') return { type: callback.type, payload: { ...callback.payload } };
  const separator = callback.indexOf(':');
  if (separator === -1) return { type: callback, payload: { value: callback, arg: null } };
  const type = callback.slice(0, separator);
  const arg = callback.slice(separator + 1);
  const trimmed = arg.trim();
  if (trimmed.startsWith('{')) {
    try {
      const parsed = JSON.parse(trimmed) as unknown;
      if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
        return { type, payload: parsed as Record<string, unknown> };
      }
    } catch {
      return { type, payload: { value: callback, arg } };
    }
  }
  const payload: Record<string, unknown> = { value: callback, arg };
  if (/^\d{1,18}$/.test(trimmed)) payload.id = Number(trimmed);
  return { type, payload };
}

export interface ActionOwner {
  botKey: string;
  telegramUserId: number | null;
  chatId: number | null;
  workspaceId: number | null;
  userId: number | null;
  locale: Locale;
}

export async function storeAction(
  store: ActionStore,
  owner: ActionOwner,
  type: string,
  payload: Record<string, unknown>,
  ttlS: number,
): Promise<string> {
  const action: StoredAction = {
    bot_key: owner.botKey,
    type,
    payload,
    telegram_user_id: owner.telegramUserId,
    chat_id: owner.chatId,
    workspace_id: owner.workspaceId,
    user_id: owner.userId,
    locale: owner.locale,
    created_at: Date.now(),
  };
  const id = await store.put(action, ttlS);
  return `${ACTION_CALLBACK_PREFIX}${id}`;
}

export async function buildKeyboard(
  buttons: readonly NotifyButton[],
  owner: ActionOwner,
  store: ActionStore,
  ttlS: number,
  options: { perRow?: number; max?: number } = {},
): Promise<InlineKeyboard | undefined> {
  const perRow = options.perRow ?? 1;
  const max = options.max ?? 10;
  const keyboard = new InlineKeyboard();
  let count = 0;
  for (const button of buttons) {
    if (count >= max) break;
    const url = button.url && isPublicHttpUrl(button.url) ? button.url : null;
    if (!url && !button.callback) continue;
    if (count > 0 && count % perRow === 0) keyboard.row();
    const label = buttonLabel(button.text);
    if (url) {
      keyboard.url(label, url);
    } else {
      const { type, payload } = parseCallback(button.callback!);
      keyboard.text(label, await storeAction(store, owner, type, payload, ttlS));
    }
    count++;
  }
  return count > 0 ? keyboard : undefined;
}

export function ownerFromContext(ctx: BotContext, workspaceId: number | null, userId: number | null): ActionOwner {
  return {
    botKey: ctx.botKey,
    telegramUserId: ctx.from?.id ?? null,
    chatId: ctx.chat?.id ?? ctx.from?.id ?? null,
    workspaceId,
    userId,
    locale: ctx.locale,
  };
}

export async function contextButtons(
  ctx: BotContext,
  buttons: readonly NotifyButton[],
  workspaceId: number | null,
  userId: number | null,
  options: { perRow?: number; max?: number } = {},
): Promise<InlineKeyboard | undefined> {
  return buildKeyboard(buttons, ownerFromContext(ctx, workspaceId, userId), ctx.services.actions, ctx.services.settings.actionTtlS, options);
}

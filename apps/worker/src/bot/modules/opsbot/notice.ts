import type { MiddlewareFn } from 'grammy';
import { errorFields } from '../../../logger.js';
import { contextButtons, isPrivateChat, sendLongHtml } from '../../helpers.js';
import { ACTION_CALLBACK_PREFIX, type StoredAction } from '../../stores.js';
import type { BotContext } from '../../types.js';

export const NOTICE_ALLOWED_COMMANDS = new Set(['start', 'help', 'lang', 'privacy', 'unlink']);
export const NOTICE_ALLOWED_ACTIONS = new Set(['notice_ack', 'unlink_confirm']);

export async function sendNotice(ctx: BotContext, notice: { version: string; html: string }): Promise<void> {
  const account = await ctx.account().catch(() => null);
  const keyboard = await contextButtons(
    ctx,
    [{ text: ctx.t('opsbot.notice.button'), callback: { type: 'notice_ack', payload: { version: notice.version } } }],
    account?.workspace.id ?? null,
    account?.user.id ?? null,
  );
  await sendLongHtml(ctx, notice.html, keyboard);
}

function commandName(text: string | undefined): string | null {
  const match = /^\/([A-Za-z0-9_]+)(?:@\w+)?/.exec(text ?? '');
  return match?.[1]?.toLowerCase() ?? null;
}

async function callbackActionType(ctx: BotContext): Promise<string | null> {
  const data = ctx.callbackQuery?.data ?? '';
  if (data.startsWith('lang:')) return 'lang';
  if (!data.startsWith(ACTION_CALLBACK_PREFIX)) return null;
  const action: StoredAction | null = await ctx.services.actions.get(data.slice(ACTION_CALLBACK_PREFIX.length)).catch(() => null);
  return action?.type ?? null;
}

export function noticeGate(): MiddlewareFn<BotContext> {
  return async (ctx, next) => {
    if (!isPrivateChat(ctx) || !ctx.from) return next();
    if (ctx.message) {
      const command = commandName(ctx.message.text);
      if (command !== null && NOTICE_ALLOWED_COMMANDS.has(command)) return next();
    } else if (ctx.callbackQuery) {
      const type = await callbackActionType(ctx);
      if (type === 'lang' || (type !== null && NOTICE_ALLOWED_ACTIONS.has(type))) return next();
    } else {
      return next();
    }
    let account;
    try {
      account = await ctx.account();
    } catch (error) {
      ctx.log.warn(errorFields(error), 'telegram context lookup failed');
      return next();
    }
    if (!account?.notice) return next();
    if (ctx.callbackQuery) await ctx.answerCallbackQuery({ text: ctx.t('opsbot.notice.button') });
    await sendNotice(ctx, account.notice);
  };
}

export async function acknowledgeNotice(ctx: BotContext, action: StoredAction): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  const version = typeof action.payload.version === 'string' ? action.payload.version : '';
  try {
    await ctx.services.web.acknowledgeNotice(ctx.botKey, from.id, version, ctx.requestId);
  } catch (error) {
    ctx.log.warn(errorFields(error), 'notice acknowledgement failed');
    await sendLongHtml(ctx, ctx.t('common.unavailable'));
    return;
  }
  await ctx.services.accounts.invalidate(ctx.botKey, from.id);
  await sendLongHtml(ctx, ctx.t('opsbot.notice.acknowledged'));
}

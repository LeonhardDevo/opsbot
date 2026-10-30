import type { NextFunction } from 'grammy';
import { isNotFound, UpstreamHttpError } from '../../../clients/errors.js';
import type { TelegramContext } from '../../../clients/web.js';
import { errorFields } from '../../../logger.js';
import { escapeHtml } from '../../format.js';
import { contextButtons, replyHtml, replyUnlinked, sendLongHtml } from '../../helpers.js';
import type { StoredAction } from '../../stores.js';
import type { BotContext } from '../../types.js';
import { clearFlow, INCIDENT_CATEGORIES, readFlow, writeFlow, type IncidentCategory } from './state.js';

export const DESCRIPTION_LIMIT = 1000;

function canReport(account: TelegramContext): boolean {
  return account.permissions.includes('incidents.create');
}

async function linkedAccount(ctx: BotContext): Promise<TelegramContext | null> {
  let account;
  try {
    account = await ctx.account();
  } catch (error) {
    ctx.log.warn(errorFields(error), 'telegram context lookup failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return null;
  }
  if (!account) {
    await replyUnlinked(ctx);
    return null;
  }
  if (!canReport(account)) {
    await replyHtml(ctx, ctx.t('opsbot.incident.no_permission'));
    return null;
  }
  return account;
}

export async function startIncidentFlow(ctx: BotContext): Promise<void> {
  const account = await linkedAccount(ctx);
  if (!account) return;
  await writeFlow(ctx, { step: 'category' });
  const buttons = INCIDENT_CATEGORIES.map((category) => ({
    text: ctx.t(`opsbot.incident.categories.${category}`),
    callback: { type: 'flow_incident_cat', payload: { category } },
  }));
  const keyboard = await contextButtons(
    ctx,
    [...buttons, { text: ctx.t('common.cancel'), callback: { type: 'flow_incident_cancel', payload: {} } }],
    account.workspace.id,
    account.user.id,
    { perRow: 2, max: 8 },
  );
  await replyHtml(ctx, ctx.t('opsbot.incident.choose_category'), keyboard);
}

function isCategory(value: unknown): value is IncidentCategory {
  return typeof value === 'string' && (INCIDENT_CATEGORIES as readonly string[]).includes(value);
}

export async function chooseCategory(ctx: BotContext, action: StoredAction): Promise<void> {
  const flow = await readFlow(ctx);
  const category = action.payload.category;
  if (!flow || !isCategory(category)) {
    await replyHtml(ctx, ctx.t('opsbot.incident.expired'));
    return;
  }
  const prompt = await ctx.reply(ctx.t('opsbot.incident.describe'), {
    reply_markup: { force_reply: true, input_field_placeholder: ctx.t('opsbot.incident.placeholder') },
  });
  await writeFlow(ctx, { step: 'description', category, prompt_message_id: prompt.message_id });
}

export async function captureDescription(ctx: BotContext, next: NextFunction): Promise<void> {
  const text = ctx.message?.text;
  if (text === undefined || text.startsWith('/')) return next();
  const flow = await readFlow(ctx);
  if (!flow || flow.step !== 'description' || !flow.category) return next();
  const description = text.trim();
  if (description.length > DESCRIPTION_LIMIT) {
    await replyHtml(ctx, ctx.t('opsbot.incident.too_long', { limit: DESCRIPTION_LIMIT }));
    return;
  }
  if (description === '') return;
  await writeFlow(ctx, { step: 'confirm', category: flow.category, description });
  const account = await ctx.account().catch(() => null);
  const keyboard = await contextButtons(
    ctx,
    [
      { text: ctx.t('opsbot.incident.confirm_button'), callback: { type: 'flow_incident_confirm', payload: {} } },
      { text: ctx.t('common.cancel'), callback: { type: 'flow_incident_cancel', payload: {} } },
    ],
    account?.workspace.id ?? null,
    account?.user.id ?? null,
    { perRow: 2 },
  );
  await replyHtml(
    ctx,
    ctx.t('opsbot.incident.confirm', {
      category: ctx.t(`opsbot.incident.categories.${flow.category}`),
      description: escapeHtml(description),
    }),
    keyboard,
  );
}

export async function confirmIncident(ctx: BotContext): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  const flow = await readFlow(ctx);
  if (!flow || flow.step !== 'confirm' || !flow.category || !flow.description) {
    await replyHtml(ctx, ctx.t('opsbot.incident.expired'));
    return;
  }
  await clearFlow(ctx);
  try {
    const created = await ctx.services.web.createIncident(
      { bot_key: ctx.botKey, telegram_user_id: from.id, category: flow.category, description: flow.description },
      ctx.requestId,
    );
    ctx.log.info({ incidentId: created.incident.id }, 'incident created from the bot');
    await sendLongHtml(ctx, created.message_html);
  } catch (error) {
    ctx.log.warn(errorFields(error), 'incident creation failed');
    const forbidden = error instanceof UpstreamHttpError && error.status === 403;
    const message = (error instanceof UpstreamHttpError ? (error.body as { message_html?: unknown } | null)?.message_html : null) ?? null;
    if (typeof message === 'string') await sendLongHtml(ctx, message);
    else await replyHtml(ctx, ctx.t(forbidden ? 'opsbot.incident.no_permission' : isNotFound(error) ? 'actions.unavailable' : 'actions.failed'));
  }
}

export async function cancelIncident(ctx: BotContext): Promise<void> {
  await clearFlow(ctx);
  await replyHtml(ctx, ctx.t('opsbot.incident.cancelled'));
}

export async function cancelFlowOnCommand(ctx: BotContext, next: NextFunction): Promise<void> {
  const text = ctx.message?.text;
  if (text?.startsWith('/') && !text.startsWith('/incident')) await clearFlow(ctx);
  await next();
}

import { UpstreamHttpError } from '../../../clients/errors.js';
import type { TelegramContext } from '../../../clients/web.js';
import { errorFields } from '../../../logger.js';
import { buttonLabel, escapeHtml } from '../../format.js';
import { contextButtons, isPrivateChat, replyHtml, replyUnlinked, sendLongHtml } from '../../helpers.js';
import type { BotContext, NotifyButton } from '../../types.js';

const QUESTION_EXCERPT = 160;
const MANAGER_ROLES = new Set(['owner', 'admin', 'manager']);

export function isManager(account: TelegramContext | null): boolean {
  return account !== null && (MANAGER_ROLES.has(account.role) || account.permissions.includes('questions.answer'));
}

function excerpt(value: string, limit = QUESTION_EXCERPT): string {
  const clean = value.replace(/\s+/g, ' ').trim();
  return clean.length <= limit ? clean : `${clean.slice(0, limit - 1)}…`;
}

export async function requireAccount(ctx: BotContext): Promise<TelegramContext | null> {
  if (!isPrivateChat(ctx)) return null;
  try {
    const account = await ctx.account();
    if (!account) await replyUnlinked(ctx);
    return account;
  } catch (error) {
    ctx.log.warn(errorFields(error), 'telegram context lookup failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return null;
  }
}

async function guard<T>(ctx: BotContext, what: string, fn: () => Promise<T>): Promise<T | undefined> {
  try {
    return await fn();
  } catch (error) {
    ctx.log.warn({ what, ...errorFields(error) }, 'opsbot command failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return undefined;
  }
}

export async function handleHistory(ctx: BotContext): Promise<void> {
  const account = await requireAccount(ctx);
  if (!account || !ctx.from) return;
  const from = ctx.from;
  const items = await guard(ctx, 'history', () => ctx.services.web.opsbotHistory(ctx.botKey, from.id, ctx.requestId));
  if (items === undefined) return;
  if (items.length === 0) {
    await replyHtml(ctx, ctx.t('opsbot.history.empty'));
    return;
  }
  const lines = [ctx.t('opsbot.history.title'), ''];
  for (const item of items) {
    const status = item.manager_answered ? 'manager' : item.refused ? 'refused' : 'answered';
    lines.push(
      ctx.t('opsbot.history.line', {
        asked: escapeHtml(item.asked_local),
        question: escapeHtml(excerpt(item.question)),
        status: ctx.t(`opsbot.history.${status}`),
      }),
    );
  }
  await sendLongHtml(ctx, lines.join('\n'));
}

export async function handleTasks(ctx: BotContext): Promise<void> {
  const account = await requireAccount(ctx);
  if (!account) return;
  const tasks = await guard(ctx, 'tasks', () =>
    ctx.services.web.opsbotTasks({ workspace_id: account.workspace.id, user_id: account.user.id, status: 'open', limit: 10 }, ctx.requestId),
  );
  if (tasks === undefined) return;
  if (tasks.length === 0) {
    await replyHtml(ctx, ctx.t('opsbot.tasks.empty'));
    return;
  }
  const lines = [ctx.services.translator.plural(ctx.locale, 'opsbot.tasks.title', tasks.length), ''];
  const buttons: NotifyButton[] = [];
  for (const task of tasks) {
    const details: string[] = [];
    if (task.due_local) details.push(ctx.t('opsbot.tasks.due', { due: escapeHtml(task.due_local) }));
    if (task.overdue) details.push(ctx.t('opsbot.tasks.overdue'));
    else if (task.status === 'in_progress') details.push(ctx.t('opsbot.tasks.in_progress'));
    const line = ctx.t('opsbot.tasks.line', { number: escapeHtml(task.display_number), title: escapeHtml(task.title) });
    lines.push(details.length > 0 ? `${line} — ${details.join(', ')}` : line);
    const number = buttonLabel(task.display_number, 16);
    if (task.status === 'todo') {
      buttons.push({ text: ctx.t('opsbot.tasks.start', { number }), callback: { type: 'task_start', payload: { id: task.id } } });
    }
    buttons.push({ text: ctx.t('opsbot.tasks.done', { number }), callback: { type: 'task_done', payload: { id: task.id } } });
  }
  const keyboard = await contextButtons(ctx, buttons, account.workspace.id, account.user.id, { perRow: 2, max: 20 });
  await sendLongHtml(ctx, lines.join('\n'), keyboard);
}

export async function handleAskManager(ctx: BotContext): Promise<void> {
  const account = await requireAccount(ctx);
  if (!account || !ctx.from) return;
  if (account.workspace.subscription_state === 'paused') {
    await replyHtml(ctx, ctx.t('opsbot.subscription.paused'));
    return;
  }
  const from = ctx.from;
  try {
    const result = await ctx.services.web.escalateLastQuestion(ctx.botKey, from.id, ctx.requestId);
    await sendLongHtml(ctx, result.message_html ?? ctx.t('opsbot.ask_manager.sent'));
  } catch (error) {
    const body = error instanceof UpstreamHttpError ? (error.body as { message_html?: unknown } | null) : null;
    if (typeof body?.message_html === 'string') {
      await sendLongHtml(ctx, body.message_html);
      return;
    }
    ctx.log.warn(errorFields(error), 'escalate last question failed');
    await replyHtml(ctx, ctx.t('actions.failed'));
  }
}

export async function handleQueue(ctx: BotContext): Promise<void> {
  const account = await requireAccount(ctx);
  if (!account || !ctx.from) return;
  if (!isManager(account)) {
    await replyHtml(ctx, ctx.t('opsbot.queue.forbidden'));
    return;
  }
  const from = ctx.from;
  const items = await guard(ctx, 'queue', () => ctx.services.web.opsbotQueue(ctx.botKey, from.id, ctx.requestId));
  if (items === undefined) return;
  if (items === null) {
    await replyHtml(ctx, ctx.t('opsbot.queue.forbidden'));
    return;
  }
  if (items.length === 0) {
    await replyHtml(ctx, ctx.t('opsbot.queue.empty'));
    return;
  }
  const lines = [ctx.services.translator.plural(ctx.locale, 'opsbot.queue.title', items.length), ''];
  const buttons: NotifyButton[] = [];
  for (const item of items) {
    lines.push(
      ctx.t('opsbot.queue.line', {
        number: escapeHtml(item.display_number),
        employee: escapeHtml(item.employee_name),
        age: ctx.services.translator.plural(ctx.locale, 'opsbot.hours_ago', Math.max(0, Math.floor(item.age_hours))),
        question: escapeHtml(excerpt(item.question_excerpt, 300)),
      }),
    );
    buttons.push({
      text: ctx.t('opsbot.queue.answer', { number: buttonLabel(item.display_number, 16) }),
      callback: { type: 'q_answer', payload: { id: item.id, display_number: item.display_number } },
    });
  }
  const keyboard = await contextButtons(ctx, buttons, account.workspace.id, account.user.id, { perRow: 2, max: 20 });
  await sendLongHtml(ctx, lines.join('\n'), keyboard);
}

export async function handlePrivacy(ctx: BotContext): Promise<void> {
  if (!isPrivateChat(ctx)) return;
  await sendLongHtml(ctx, ctx.t('opsbot.privacy.text'));
}

export async function handleUnlink(ctx: BotContext): Promise<void> {
  if (!isPrivateChat(ctx)) return;
  const account = await ctx.account().catch(() => null);
  if (!account) {
    await replyHtml(ctx, ctx.t('opsbot.unlink.not_linked'));
    return;
  }
  const keyboard = await contextButtons(
    ctx,
    [{ text: ctx.t('opsbot.unlink.button'), callback: { type: 'unlink_confirm', payload: {} } }],
    account.workspace.id,
    account.user.id,
  );
  await replyHtml(ctx, ctx.t('opsbot.unlink.confirm', { workspace: escapeHtml(account.workspace.name) }), keyboard);
}

export async function confirmUnlink(ctx: BotContext): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  try {
    await ctx.services.web.unlinkTelegram(ctx.botKey, from.id, ctx.requestId);
  } catch (error) {
    ctx.log.warn(errorFields(error), 'unlink failed');
    await replyHtml(ctx, ctx.t('actions.failed'));
    return;
  }
  await ctx.services.accounts.invalidate(ctx.botKey, from.id);
  await replyHtml(ctx, ctx.t('opsbot.unlink.done'));
}

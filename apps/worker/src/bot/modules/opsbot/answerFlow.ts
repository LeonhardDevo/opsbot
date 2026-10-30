import type { NextFunction } from 'grammy';
import { UpstreamHttpError } from '../../../clients/errors.js';
import { errorFields } from '../../../logger.js';
import { escapeHtml } from '../../format.js';
import { contextButtons, replyHtml, sendLongHtml } from '../../helpers.js';
import type { StoredAction } from '../../stores.js';
import type { BotContext } from '../../types.js';
import { clearReply, readReply, writeReply } from './state.js';

export const ANSWER_LIMIT = 2000;

function questionId(action: StoredAction): number | null {
  const raw = action.payload.id ?? action.payload.question_id;
  const id = typeof raw === 'string' ? Number(raw) : raw;
  return typeof id === 'number' && Number.isSafeInteger(id) && id > 0 ? id : null;
}

export async function promptAnswer(ctx: BotContext, action: StoredAction): Promise<void> {
  const id = questionId(action);
  if (id === null) {
    await replyHtml(ctx, ctx.t('actions.expired'));
    return;
  }
  const display = typeof action.payload.display_number === 'string' ? action.payload.display_number : `#${id}`;
  const prompt = await ctx.reply(ctx.t('opsbot.answer_flow.prompt', { number: escapeHtml(display) }), {
    parse_mode: 'HTML',
    reply_markup: { force_reply: true, input_field_placeholder: ctx.t('opsbot.answer_flow.placeholder') },
  });
  await writeReply(ctx, { question_id: id, prompt_message_id: prompt.message_id, display_number: display });
}

export async function captureAnswer(ctx: BotContext, next: NextFunction): Promise<void> {
  const message = ctx.message;
  const from = ctx.from;
  if (!message?.text || !from || !message.reply_to_message || message.text.startsWith('/')) return next();
  const state = await readReply(ctx);
  if (!state || state.prompt_message_id !== message.reply_to_message.message_id) return next();
  const text = message.text.trim();
  if (text.length > ANSWER_LIMIT) {
    await replyHtml(ctx, ctx.t('opsbot.answer_flow.too_long', { limit: ANSWER_LIMIT }));
    return;
  }
  if (text === '') return;
  try {
    const result = await ctx.services.web.answerQuestion(
      state.question_id,
      { bot_key: ctx.botKey, telegram_user_id: from.id, text },
      ctx.requestId,
    );
    await clearReply(ctx);
    const account = await ctx.account().catch(() => null);
    const keyboard = result.buttons?.length
      ? await contextButtons(
          ctx,
          result.buttons.map((button) => ({ text: button.text, url: button.url ?? null, callback: button.callback ?? null })),
          account?.workspace.id ?? null,
          account?.user.id ?? null,
        )
      : undefined;
    await sendLongHtml(ctx, result.message_html ?? ctx.t('opsbot.answer_flow.sent'), keyboard);
  } catch (error) {
    ctx.log.warn(errorFields(error), 'manager answer failed');
    const body = error instanceof UpstreamHttpError ? (error.body as { message_html?: unknown } | null) : null;
    if (typeof body?.message_html === 'string') {
      await clearReply(ctx);
      await sendLongHtml(ctx, body.message_html);
      return;
    }
    await replyHtml(ctx, ctx.t('actions.failed'));
  }
}

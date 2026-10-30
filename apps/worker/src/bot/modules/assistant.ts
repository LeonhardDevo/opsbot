import type { Composer, InlineKeyboard } from 'grammy';
import type { ChatResponse } from '../../clients/ai.js';
import { isNotFound } from '../../clients/errors.js';
import type { AiQueryAction, AiQueryLog, AiQueryLogged, TelegramContext } from '../../clients/web.js';
import { errorFields } from '../../logger.js';
import { actionButtons, renderAnswerHtml } from '../answer.js';
import { escapeHtml } from '../format.js';
import { contextButtons, isPrivateChat, replyHtml, replyUnlinked, sendLongHtml } from '../helpers.js';
import type { StoredAction } from '../stores.js';
import type { BotContext, BotModule } from '../types.js';

const MAX_QUESTION_LENGTH = 4000;
const TYPING_INTERVAL_MS = 4500;
const CALLBACK_TEXT_LIMIT = 190;
const LOG_ATTEMPTS = 2;

function startTyping(ctx: BotContext): () => void {
  const send = (): void => {
    ctx.replyWithChatAction('typing').catch(() => undefined);
  };
  send();
  const timer = setInterval(send, TYPING_INTERVAL_MS);
  timer.unref();
  return () => clearInterval(timer);
}

export function conversationId(botKey: string, chatId: number): string {
  return `tg-${botKey}-${chatId}`;
}

async function logQuery(ctx: BotContext, entry: AiQueryLog): Promise<AiQueryLogged | null> {
  for (let attempt = 1; attempt <= LOG_ATTEMPTS; attempt++) {
    try {
      return await ctx.services.web.logAiQuery(entry, ctx.requestId);
    } catch (error) {
      ctx.log.warn({ attempt, ...errorFields(error) }, 'ai query log failed');
    }
  }
  return null;
}

function baseLog(ctx: BotContext, account: TelegramContext, question: string, chatId: number): Omit<AiQueryLog, 'answer' | 'refused'> {
  return {
    workspace_id: account.workspace.id,
    user_id: account.user.id,
    channel: 'telegram',
    bot_key: ctx.botKey,
    conversation_id: conversationId(ctx.botKey, chatId),
    question,
    answer_kind: 'document',
    refusal_reason: null,
    confidence: null,
    citations: [],
    tools_used: [],
    latency_ms: null,
    provider: null,
    model: null,
    role_at_time: account.role,
    department_ids_at_time: account.department_ids,
    disclaimers: [],
    actions: [],
  };
}

async function replyPaused(ctx: BotContext, account: TelegramContext): Promise<void> {
  const keyboard = await contextButtons(
    ctx,
    [{ text: ctx.t('opsbot.incident.report_button'), callback: { type: 'flow_incident_start', payload: {} } }],
    account.workspace.id,
    account.user.id,
  );
  await replyHtml(ctx, ctx.t('opsbot.subscription.paused'), keyboard);
}

async function replyLimitReached(ctx: BotContext, account: TelegramContext, question: string, chatId: number): Promise<void> {
  const escalate: AiQueryAction = { type: 'escalate', label: ctx.t('assistant.pass_to_manager'), payload: { reason: 'limit_reached' } };
  const logged = await logQuery(ctx, {
    ...baseLog(ctx, account, question, chatId),
    answer: null,
    refused: true,
    refusal_reason: 'limit_reached',
    actions: [escalate],
  });
  const keyboard = logged ? await keyboardFor(ctx, account, logged) : undefined;
  await replyHtml(ctx, ctx.t('opsbot.limits.ai_exhausted'), keyboard);
}

async function keyboardFor(ctx: BotContext, account: TelegramContext, logged: AiQueryLogged): Promise<InlineKeyboard | undefined> {
  return contextButtons(ctx, actionButtons(logged.actions), account.workspace.id, account.user.id);
}

export async function answerQuestion(ctx: BotContext, rawQuestion: string): Promise<void> {
  const from = ctx.from;
  const chat = ctx.chat;
  if (!from || !chat) return;
  const question = rawQuestion.trim().slice(0, MAX_QUESTION_LENGTH);
  if (question === '') return;

  let account;
  try {
    account = await ctx.account();
  } catch (error) {
    ctx.log.warn(errorFields(error), 'telegram context lookup failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return;
  }
  if (!account) {
    await replyUnlinked(ctx);
    return;
  }
  if (account.workspace.subscription_state === 'paused') {
    await replyPaused(ctx, account);
    return;
  }
  if (account.limits.ai_queries_exhausted) {
    await replyLimitReached(ctx, account, question, chat.id);
    return;
  }

  const { services } = ctx;
  const stopTyping = startTyping(ctx);
  const startedAt = Date.now();
  let response: ChatResponse;
  try {
    response = await services.ai.chat(
      {
        workspace_id: account.workspace.id,
        user: {
          id: account.user.id,
          role: account.role,
          locale: ctx.locale,
          permissions: account.permissions,
          department_ids: account.department_ids,
          privileged: account.privileged,
        },
        channel: 'telegram',
        conversation_id: conversationId(ctx.botKey, chat.id),
        message: question,
        context: {
          today: account.workspace.today ?? null,
          timezone: account.workspace.timezone,
          min_confidence: account.settings.min_confidence,
          ai_external_allowed: account.settings.ai_external_allowed,
        },
      },
      ctx.requestId,
    );
  } catch (error) {
    ctx.log.error(errorFields(error), 'ai chat failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return;
  } finally {
    stopTyping();
  }

  const logged = await logQuery(ctx, {
    ...baseLog(ctx, account, question, chat.id),
    answer: response.answer_markdown,
    answer_kind: response.answer_kind,
    refused: response.refused,
    refusal_reason: response.refusal_reason ?? null,
    confidence: response.confidence,
    citations: response.citations.map((citation) => ({
      document_id: citation.document_id,
      chunk_id: citation.chunk_id ?? null,
      page: citation.page ?? null,
      version: citation.version ?? null,
      title: citation.title,
    })),
    tools_used: response.tools_used,
    latency_ms: response.latency_ms ?? Date.now() - startedAt,
    provider: response.provider ?? null,
    model: response.model ?? null,
    disclaimers: response.disclaimers,
    actions: response.actions,
  });

  const html = renderAnswerHtml(response, logged, ctx.locale, services.translator, {
    webPublicUrl: services.settings.webPublicUrl,
    minConfidence: account.settings.min_confidence,
  });
  const keyboard = logged ? await keyboardFor(ctx, account, logged) : undefined;
  await sendLongHtml(ctx, html, keyboard);
  ctx.log.info(
    {
      refused: response.refused,
      confidence: response.confidence,
      citations: response.citations.length,
      logged: logged !== null,
      latencyMs: Date.now() - startedAt,
    },
    'question answered',
  );
}

async function handleActionCallback(ctx: BotContext, id: string): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  const { services } = ctx;
  const action = await services.actions.get(id);
  if (!action || (action.bot_key && action.bot_key !== ctx.botKey)) {
    await ctx.answerCallbackQuery({ text: ctx.t('actions.expired'), show_alert: true });
    return;
  }
  const ownerMismatch =
    action.telegram_user_id !== null
      ? action.telegram_user_id !== from.id
      : action.chat_id !== null && action.chat_id !== (ctx.chat?.id ?? from.id);
  if (ownerMismatch) {
    await ctx.answerCallbackQuery({ text: ctx.t('actions.forbidden'), show_alert: true });
    return;
  }
  ctx.log.info({ actionType: action.type }, 'bot action pressed');
  const handler = services.modules.action(action.type);
  if (handler) {
    await ctx.answerCallbackQuery();
    await handler(ctx, action);
    return;
  }
  await forwardActionToWeb(ctx, action);
}

async function forwardActionToWeb(ctx: BotContext, action: StoredAction): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  try {
    const result = await ctx.services.web.telegramAction(
      {
        bot_key: ctx.botKey,
        type: action.type,
        payload: action.payload,
        telegram_user_id: from.id,
        chat_id: ctx.chat?.id ?? action.chat_id ?? from.id,
        workspace_id: action.workspace_id,
        user_id: action.user_id,
        locale: ctx.locale,
      },
      ctx.requestId,
    );
    const toast = result.message ?? (result.ok ? ctx.t('actions.done') : ctx.t('actions.failed'));
    await ctx.answerCallbackQuery({ text: toast.slice(0, CALLBACK_TEXT_LIMIT), show_alert: result.alert ?? !result.ok });
    const keyboard = result.buttons?.length
      ? await contextButtons(
          ctx,
          result.buttons.map((button) => ({ text: button.text, url: button.url ?? null, callback: button.callback ?? null })),
          action.workspace_id,
          action.user_id,
        )
      : undefined;
    if (result.message_html) await sendLongHtml(ctx, result.message_html, keyboard);
  } catch (error) {
    const unavailable = isNotFound(error);
    ctx.log.warn({ actionType: action.type, ...errorFields(error) }, 'bot action forward failed');
    await ctx.answerCallbackQuery({ text: ctx.t(unavailable ? 'actions.unavailable' : 'actions.failed'), show_alert: true });
  }
}

export const assistantModule: BotModule = {
  name: 'assistant',
  actions: {
    ask: async (ctx, action) => {
      const question = typeof action.payload.question === 'string' ? action.payload.question : '';
      if (question !== '') {
        await replyHtml(ctx, `<i>${escapeHtml(question)}</i>`);
        await answerQuestion(ctx, question);
      }
    },
  },
  register(composer: Composer<BotContext>) {
    composer.callbackQuery(/^a:([A-Za-z0-9]{6,32})$/, async (ctx) => {
      await handleActionCallback(ctx, ctx.match[1] ?? '');
    });
    composer.on('callback_query:data', async (ctx) => {
      await ctx.answerCallbackQuery({ text: ctx.t('actions.expired') });
    });
    composer.on('message:text', async (ctx) => {
      if (!isPrivateChat(ctx)) return;
      const text = ctx.message.text;
      if (text.startsWith('/')) {
        await replyHtml(ctx, ctx.t('assistant.unknown_command'));
        return;
      }
      await answerQuestion(ctx, text);
    });
    composer.on('message', async (ctx) => {
      if (isPrivateChat(ctx)) await replyHtml(ctx, ctx.t('assistant.text_only'));
    });
  },
};

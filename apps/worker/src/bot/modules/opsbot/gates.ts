import { InlineKeyboard, type MiddlewareFn } from 'grammy';
import { errorFields } from '../../../logger.js';
import type { BotContext } from '../../types.js';

const GROUP_TYPES = new Set(['group', 'supergroup']);

function addressedToBot(ctx: BotContext): boolean {
  const username = ctx.me.username.toLowerCase();
  if (ctx.myChatMember) {
    const status = ctx.myChatMember.new_chat_member.status;
    return status === 'member' || status === 'administrator';
  }
  const message = ctx.message;
  if (!message) return false;
  if (message.reply_to_message?.from?.id === ctx.me.id) return true;
  const text = (message.text ?? message.caption ?? '').toLowerCase();
  if (text.includes(`@${username}`)) return true;
  return /^\/[a-z0-9_]+@/i.test(text) && text.split(/\s/)[0]?.endsWith(`@${username}`) === true;
}

async function sendGroupNotice(ctx: BotContext): Promise<void> {
  const chatId = ctx.chat?.id;
  if (chatId === undefined) return;
  const first = await ctx.services.groupNotices.markOnce(ctx.botKey, chatId);
  if (!first) return;
  const keyboard = new InlineKeyboard().url(ctx.t('opsbot.group.button'), `https://t.me/${ctx.me.username}`);
  await ctx.api.sendMessage(chatId, ctx.t('opsbot.group.private_only'), { reply_markup: keyboard });
}

export function privateChatsOnly(): MiddlewareFn<BotContext> {
  return async (ctx, next) => {
    const chat = ctx.chat;
    if (!chat) return next();
    if (chat.type === 'private') {
      if (ctx.myChatMember && ctx.from) {
        const blocked = ctx.myChatMember.new_chat_member.status === 'kicked';
        await ctx.services.web.reportChatBlocked(ctx.botKey, ctx.from.id, blocked, ctx.requestId).catch((error: unknown) => {
          ctx.log.warn(errorFields(error), 'chat blocked report failed');
        });
        return;
      }
      return next();
    }
    if (ctx.callbackQuery) {
      await ctx.answerCallbackQuery({ text: ctx.t('actions.expired') });
      return;
    }
    if (GROUP_TYPES.has(chat.type) && addressedToBot(ctx)) {
      await sendGroupNotice(ctx);
    }
  };
}

import { type Composer, InlineKeyboard } from 'grammy';
import type { TelegramContext } from '../../clients/web.js';
import { isLocale, type Locale } from '../../i18n/index.js';
import { errorFields } from '../../logger.js';
import { PLATFORM_BOT_KEY } from '../botRegistry.js';
import { escapeHtml } from '../format.js';
import { isPrivateChat, replyHtml, replyUnlinked, withSettingsLink } from '../helpers.js';
import type { BotContext, BotModule } from '../types.js';
import { sendNotice } from './opsbot/notice.js';

const LINK_TOKEN_RE = /^(?:emp_)?[A-Za-z0-9_-]{8,64}$/;

async function loadAccount(ctx: BotContext): Promise<TelegramContext | null | undefined> {
  try {
    return await ctx.account();
  } catch (error) {
    ctx.log.warn(errorFields(error), 'telegram context lookup failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return undefined;
  }
}

async function handleStart(ctx: BotContext): Promise<void> {
  if (!isPrivateChat(ctx)) return;
  const token = typeof ctx.match === 'string' ? ctx.match.trim() : '';
  if (token !== '') {
    await linkAccount(ctx, token);
    return;
  }
  const account = await loadAccount(ctx);
  if (account === undefined) return;
  if (!account) {
    if (ctx.botKey === PLATFORM_BOT_KEY) {
      const { html, keyboard } = withSettingsLink(ctx, ctx.t('start.welcome_unlinked'));
      await replyHtml(ctx, html, keyboard);
    } else {
      await replyHtml(ctx, ctx.t('start.welcome_company'));
    }
    return;
  }
  await replyHtml(
    ctx,
    ctx.t('start.welcome_linked', { name: escapeHtml(account.user.name), workspace: escapeHtml(account.workspace.name) }),
  );
  if (account.notice) await sendNotice(ctx, account.notice);
}

function invalidLinkKey(ctx: BotContext, token: string): string {
  return token.startsWith('emp_') || ctx.botKey !== PLATFORM_BOT_KEY ? 'start.invalid_company_link' : 'start.invalid_token';
}

async function replyInvalidLink(ctx: BotContext, token: string): Promise<void> {
  const key = invalidLinkKey(ctx, token);
  if (key === 'start.invalid_token') {
    const { html, keyboard } = withSettingsLink(ctx, ctx.t(key));
    await replyHtml(ctx, html, keyboard);
    return;
  }
  await replyHtml(ctx, ctx.t(key));
}

async function linkAccount(ctx: BotContext, token: string): Promise<void> {
  const from = ctx.from;
  const chat = ctx.chat;
  if (!from || !chat) return;
  if (!LINK_TOKEN_RE.test(token)) {
    await replyInvalidLink(ctx, token);
    return;
  }
  let result;
  try {
    result = await ctx.services.web.linkTelegram(
      {
        bot_key: ctx.botKey,
        token,
        telegram_user_id: from.id,
        chat_id: chat.id,
        username: from.username ?? null,
        language_code: from.language_code ?? null,
      },
      ctx.requestId,
    );
  } catch (error) {
    ctx.log.error(errorFields(error), 'telegram link request failed');
    await replyHtml(ctx, ctx.t('common.unavailable'));
    return;
  }
  if (!result.ok) {
    ctx.log.info({ reason: result.reason }, 'telegram link rejected');
    if (result.reason === 'invalid_token') await replyInvalidLink(ctx, token);
    else await replyHtml(ctx, ctx.t(result.reason === 'conflict' ? 'start.conflict' : 'start.wrong_bot'));
    return;
  }
  await ctx.services.accounts.invalidate(ctx.botKey, from.id);
  const preferred = await ctx.services.preferences.getLocale(ctx.botKey, from.id).catch(() => null);
  if (!preferred && result.locale) ctx.setLocale(result.locale);
  ctx.log.info({ chatId: chat.id }, 'telegram account linked');
  const name = escapeHtml(result.userName ?? from.first_name);
  await replyHtml(
    ctx,
    result.workspaceName
      ? ctx.t('start.linked_workspace', { name, workspace: escapeHtml(result.workspaceName) })
      : ctx.t('start.linked', { name }),
  );
  if (result.noticeRequired) {
    const account = await ctx.services.accounts.get(ctx.botKey, from.id, ctx.requestId).catch(() => null);
    if (account?.notice) await sendNotice(ctx, account.notice);
  }
}

async function handleHelp(ctx: BotContext): Promise<void> {
  if (!isPrivateChat(ctx)) return;
  const account = await ctx.account().catch(() => null);
  const lines = [ctx.t('help.title'), '', ctx.t('help.intro'), '', ctx.t('help.commands')];
  for (const command of ctx.services.modules.commands) {
    if (command.visible && !command.visible(account)) continue;
    lines.push(`/${command.command} — ${ctx.t(command.descriptionKey)}`);
  }
  await replyHtml(ctx, lines.join('\n'));
}

function languageKeyboard(ctx: BotContext): InlineKeyboard {
  return new InlineKeyboard().text(ctx.t('lang.button_ru'), 'lang:ru').text(ctx.t('lang.button_en'), 'lang:en');
}

async function handleLangCommand(ctx: BotContext): Promise<void> {
  if (!isPrivateChat(ctx)) return;
  await ctx.reply(ctx.t('lang.choose'), { reply_markup: languageKeyboard(ctx) });
}

async function handleLangChoice(ctx: BotContext, locale: Locale): Promise<void> {
  const from = ctx.from;
  if (!from) return;
  await ctx.services.preferences.setLocale(ctx.botKey, from.id, ctx.chat?.id ?? from.id, locale, from.username ?? null);
  ctx.setLocale(locale);
  await ctx.answerCallbackQuery({ text: ctx.t('lang.changed') });
  if (ctx.callbackQuery?.message) {
    await ctx.editMessageText(ctx.t('lang.changed'));
  } else {
    await ctx.reply(ctx.t('lang.changed'));
  }
}

export function describeDepartments(ctx: BotContext, account: TelegramContext): string {
  if (account.departments && account.departments.length > 0) {
    return account.departments.map((department) => escapeHtml(department.name)).join(', ');
  }
  if (account.department_ids.length > 0) {
    return ctx.services.translator.plural(ctx.locale, 'whoami.departments_count', account.department_ids.length);
  }
  return ctx.t('whoami.no_departments');
}

async function handleWhoami(ctx: BotContext): Promise<void> {
  if (!isPrivateChat(ctx)) return;
  const account = await loadAccount(ctx);
  if (account === undefined) return;
  if (!account) {
    await replyUnlinked(ctx);
    return;
  }
  await replyHtml(
    ctx,
    ctx.t('whoami.linked', {
      name: escapeHtml(account.user.name),
      workspace: escapeHtml(account.workspace.name),
      role: ctx.t(`opsbot.roles.${account.role}`),
      departments: describeDepartments(ctx, account),
      locale: ctx.locale.toUpperCase(),
    }),
  );
}

export const coreModule: BotModule = {
  name: 'core',
  commands: [
    { command: 'start', descriptionKey: 'commands.start' },
    { command: 'help', descriptionKey: 'commands.help' },
    { command: 'lang', descriptionKey: 'commands.lang' },
    { command: 'whoami', descriptionKey: 'commands.whoami', visible: (account) => account !== null },
  ],
  register(composer: Composer<BotContext>) {
    composer.command('start', handleStart);
    composer.command('help', handleHelp);
    composer.command('lang', handleLangCommand);
    composer.command('whoami', handleWhoami);
    composer.callbackQuery(/^lang:(ru|en)$/, async (ctx) => {
      const locale = ctx.match[1];
      if (isLocale(locale)) await handleLangChoice(ctx, locale);
    });
  },
};

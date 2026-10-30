import type { FastifyInstance } from 'fastify';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { defaultModules } from '../src/bot/modules/index.js';
import type { BotModule } from '../src/bot/types.js';
import { UpstreamNetworkError } from '../src/clients/errors.js';
import { chatResponse, companyCredentials, linkedContext, loggedQuery } from './helpers/fakes.js';
import { buttons, callbackOf, createTestBot, devServer, sendDev, sentMessages, type TestBot, type TestBotOptions } from './helpers/bot.js';

const ANNA = { id: 5001, username: 'anna', first_name: 'Anna', language_code: 'ru' };
const BOB = { id: 6002, username: 'bob', first_name: 'Bob', language_code: 'en' };

let bot: TestBot;
let server: FastifyInstance;

async function setup(options: TestBotOptions = {}): Promise<void> {
  bot = createTestBot(options);
  server = await devServer(bot.registry);
}

beforeEach(async () => {
  await setup();
});

afterEach(async () => {
  await server.close();
});

describe('dev route', () => {
  it('rejects malformed input and unknown bots', async () => {
    const malformed = await server.inject({ method: 'POST', url: '/dev/telegram/update', payload: { text: 'hi' } });
    expect(malformed.statusCode).toBe(422);
    const unknown = await server.inject({ method: 'POST', url: '/dev/telegram/update', payload: { bot_key: '99', from: BOB, text: '/help' } });
    expect(unknown.statusCode).toBe(404);
    const invalid = await server.inject({ method: 'POST', url: '/dev/telegram/update', payload: { bot_key: 'x', from: BOB, text: '/help' } });
    expect(invalid.statusCode).toBe(422);
  });

  it('accepts a full Telegram Update, wrapped or bare', async () => {
    const update = {
      update_id: 77,
      message: {
        message_id: 1,
        date: 1,
        chat: { id: BOB.id, type: 'private' },
        from: { ...BOB, is_bot: false },
        text: '/help',
        entities: [{ type: 'bot_command', offset: 0, length: 5 }],
      },
    };
    for (const payload of [update, { bot_key: 'platform', update }]) {
      const response = await server.inject({ method: 'POST', url: '/dev/telegram/update', payload });
      expect(response.statusCode).toBe(200);
      const body = response.json<{ update_id: number; bot_key: string; outbound: Array<{ method: string; bot_key: string }> }>();
      expect(body).toMatchObject({ update_id: 77, bot_key: 'platform' });
      expect(body.outbound.map((call) => call.method)).toEqual(['sendMessage']);
      expect(body.outbound[0]?.bot_key).toBe('platform');
    }
  });

  it('routes company bot updates through the registry with the same handlers', async () => {
    bot.credentials.set(7, companyCredentials());
    const outbound = await sendDev(server, { bot_key: '7', from: BOB, text: '/start' });
    expect(outbound[0]?.bot_key).toBe('7');
    expect(sentMessages(outbound)[0]?.text).toContain('ask your manager for a personal invitation link');
    expect(bot.web.botCredentials).toHaveBeenCalledWith(7, undefined);
  });
});

describe('/start linking', () => {
  it('links a web user with a deep-link token on the platform bot', async () => {
    const outbound = await sendDev(server, { from: ANNA, text: '/start tok_abcdef123456' });
    expect(bot.web.linkTelegram).toHaveBeenCalledWith(
      { bot_key: 'platform', token: 'tok_abcdef123456', telegram_user_id: ANNA.id, chat_id: ANNA.id, username: 'anna', language_code: 'ru' },
      expect.any(String),
    );
    const [message] = sentMessages(outbound);
    expect(message?.text).toContain('Готово! Telegram привязан: <b>Anna Petrova</b>');
    expect(message?.text).toContain('Clean Line');
    expect(outbound[0]).toMatchObject({ payload: { parse_mode: 'HTML', chat_id: ANNA.id } });
  });

  it('links an employee on a company bot and shows the employee notice', async () => {
    bot.credentials.set(7, companyCredentials());
    bot.web.linkTelegram.mockResolvedValueOnce({ ok: true, userName: 'Anna Petrova', workspaceName: 'Clean Line', locale: 'ru', noticeRequired: true });
    bot.contexts.set(ANNA.id, linkedContext({ telegram: { bot_key: '7', user_id: ANNA.id, chat_id: ANNA.id, username: 'anna', blocked: false }, notice: { version: '1.0', html: '<b>Уведомление</b> о данных' } }));
    const outbound = await sendDev(server, { bot_key: '7', from: ANNA, text: '/start emp_abcdef123456' });
    expect(bot.web.linkTelegram).toHaveBeenCalledWith(expect.objectContaining({ bot_key: '7', token: 'emp_abcdef123456' }), expect.any(String));
    const [linked, notice] = sentMessages(outbound);
    expect(linked?.text).toContain('Готово!');
    expect(notice?.text).toBe('<b>Уведомление</b> о данных');
    expect(buttons(notice).map((button) => button.text)).toEqual(['Понятно']);
  });

  it('explains wrong-bot, conflict and expired links', async () => {
    bot.web.linkTelegram.mockResolvedValueOnce({ ok: false, reason: 'wrong_bot' });
    expect(sentMessages(await sendDev(server, { from: BOB, text: '/start emp_expired0001' }))[0]?.text).toContain("another company's bot");
    bot.web.linkTelegram.mockResolvedValueOnce({ ok: false, reason: 'conflict' });
    expect(sentMessages(await sendDev(server, { from: BOB, text: '/start tok_conflict01' }))[0]?.text).toContain('already linked');
    bot.web.linkTelegram.mockResolvedValueOnce({ ok: false, reason: 'invalid_token' });
    const expired = sentMessages(await sendDev(server, { from: BOB, text: '/start emp_expired0002' }))[0]?.text;
    expect(expired).toContain('invalid, expired or already used');
    bot.web.linkTelegram.mockResolvedValueOnce({ ok: false, reason: 'invalid_token' });
    const platform = sentMessages(await sendDev(server, { from: BOB, text: '/start tok_expired_0001' }))[0]?.text;
    expect(platform).toContain('Settings: http://127.0.0.1:8200/settings/integrations');
  });

  it('rejects malformed tokens without calling web and survives web outages', async () => {
    const outbound = await sendDev(server, { from: BOB, text: '/start bad!' });
    expect(bot.web.linkTelegram).not.toHaveBeenCalled();
    expect(sentMessages(outbound)[0]?.text).toContain('invalid or has expired');
    bot.web.linkTelegram.mockRejectedValueOnce(new UpstreamNetworkError('web', 'POST', '/internal/v1/telegram/link', new Error('ECONNREFUSED')));
    expect(sentMessages(await sendDev(server, { from: BOB, text: '/start tok_abcdef123456' }))[0]?.text).toContain('temporarily unavailable');
  });

  it('greets unlinked users with a public settings button and linked users by name', async () => {
    await server.close();
    await setup({ webPublicUrl: 'https://app.acme.example' });
    const [welcome] = sentMessages(await sendDev(server, { from: BOB, text: '/start' }));
    expect(welcome?.text).toContain('link your Telegram account');
    expect(buttons(welcome)[0]).toMatchObject({ url: 'https://app.acme.example/settings/integrations' });
    bot.contexts.set(ANNA.id, linkedContext());
    expect(sentMessages(await sendDev(server, { from: ANNA, text: '/start' }))[0]?.text).toContain('С возвращением, <b>Anna Petrova</b>');
  });
});

describe('answers', () => {
  beforeEach(() => {
    bot.contexts.set(ANNA.id, linkedContext());
  });

  it('sends the domain context to ai and the conversation id per bot and chat', async () => {
    await sendDev(server, { from: ANNA, text: 'Я поцарапала стол' });
    expect(bot.ai.chat).toHaveBeenCalledWith(
      {
        workspace_id: 3,
        user: {
          id: 7,
          role: 'employee',
          locale: 'ru',
          permissions: ['assistant.use', 'incidents.create', 'incidents.view_own', 'tasks.create', 'tasks.view_own'],
          department_ids: [1],
          privileged: false,
        },
        channel: 'telegram',
        conversation_id: `tg-platform-${ANNA.id}`,
        message: 'Я поцарапала стол',
        context: { today: '2026-10-22', timezone: 'Europe/Moscow', min_confidence: 0.4, ai_external_allowed: true },
      },
      expect.any(String),
    );
  });

  it('logs the query, renders signed source links and buttons from the persisted actions', async () => {
    const outbound = await sendDev(server, { from: ANNA, text: 'Я поцарапала стол' });
    expect(bot.web.logAiQuery).toHaveBeenCalledWith(
      expect.objectContaining({
        workspace_id: 3,
        user_id: 7,
        bot_key: 'platform',
        conversation_id: `tg-platform-${ANNA.id}`,
        role_at_time: 'employee',
        department_ids_at_time: [1],
        refused: false,
        answer_kind: 'document',
        citations: [
          { document_id: 11, chunk_id: 101, page: 2, version: '1.0', title: 'Client property damage' },
          { document_id: 12, chunk_id: 102, page: null, version: null, title: 'Compensation rules' },
        ],
        actions: chatResponse().actions,
      }),
      expect.any(String),
    );
    expect(outbound[0]).toMatchObject({ method: 'sendChatAction', payload: { action: 'typing', chat_id: ANNA.id } });
    const reply = sentMessages(outbound)[0]!;
    expect(reply.text).toContain('Take three photos of the <b>damage</b> [1].');
    expect(reply.text).toContain('<b>2 источника:</b>');
    expect(reply.text).toContain('[1] <a href="https://app.acme.example/s/doc/abc">Client property damage</a>, с. 2');
    expect(reply.text).toContain('[2] <a href="http://127.0.0.1:8200/s/doc/def">Compensation rules</a>');
    expect(reply.text).not.toContain('проверьте источник');
    const keyboard = buttons(reply);
    expect(keyboard).toHaveLength(2);
    expect(keyboard[0]).toMatchObject({ text: 'Create incident' });
    expect(keyboard[1]).toMatchObject({ text: 'Open source', url: 'https://app.acme.example/s/doc/abc' });
    const stored = await bot.actions.get(keyboard[0]!.callback_data!.slice(2));
    expect(stored).toMatchObject({
      bot_key: 'platform',
      type: 'opsbot.action',
      payload: { action_id: 'act-incident', step: 'preview' },
      telegram_user_id: ANNA.id,
    });
  });

  it('adds the medium-confidence line', async () => {
    bot.ai.chat.mockResolvedValueOnce(chatResponse({ confidence: 0.55, confidence_band: 'medium' }));
    const reply = sentMessages(await sendDev(server, { from: ANNA, text: 'question' }))[0]!;
    expect(reply.text).toContain('<i>Средняя уверенность — проверьте источник.</i>');
  });

  it('renders refusals without sources and with the escalation button', async () => {
    bot.ai.chat.mockResolvedValueOnce(
      chatResponse({
        refused: true,
        refusal_reason: 'low_confidence',
        citations: [],
        answer_html: '<p>Я не нашёл ответа в утверждённых документах компании. Могу передать вопрос менеджеру.</p>',
        actions: [{ type: 'escalate', label: 'Передать менеджеру', payload: { reason: 'low_confidence' } }],
      }),
    );
    bot.web.logAiQuery.mockResolvedValueOnce(
      loggedQuery({ actions: [{ id: 'act-esc', type: 'escalate', label: 'Передать менеджеру', url: null, status: 'proposed' }], citations: [] }),
    );
    const reply = sentMessages(await sendDev(server, { from: ANNA, text: 'Какой пароль от Wi-Fi?' }))[0]!;
    expect(reply.text).toBe('Я не нашёл ответа в утверждённых документах компании. Могу передать вопрос менеджеру.');
    const [escalate] = buttons(reply);
    expect(escalate?.text).toBe('Передать менеджеру');
    expect(await bot.actions.get(escalate!.callback_data!.slice(2))).toMatchObject({ payload: { action_id: 'act-esc', step: 'confirm' } });
  });

  it('sends the answer without buttons when logging fails twice', async () => {
    bot.web.logAiQuery.mockRejectedValue(new UpstreamNetworkError('web', 'POST', '/internal/v1/ai-queries', new Error('down')));
    const reply = sentMessages(await sendDev(server, { from: ANNA, text: 'question' }))[0]!;
    expect(bot.web.logAiQuery).toHaveBeenCalledTimes(2);
    expect(reply.text).toContain('[1] Client property damage, с. 2');
    expect(reply.reply_markup).toBeUndefined();
  });

  it('forwards pressed actions to web with the bot key and stores returned buttons', async () => {
    const id = await bot.actions.put(
      {
        bot_key: 'platform',
        type: 'opsbot.action',
        payload: { action_id: 'act-incident', step: 'preview' },
        telegram_user_id: ANNA.id,
        chat_id: ANNA.id,
        workspace_id: 3,
        user_id: 7,
        locale: 'ru',
        created_at: Date.now(),
      },
      60,
    );
    bot.web.telegramAction.mockResolvedValueOnce({
      ok: true,
      message: 'Проверьте',
      message_html: '<b>Инцидент</b>: Повреждение',
      buttons: [
        { text: 'Подтвердить', callback: { type: 'opsbot.action', payload: { action_id: 'act-incident', step: 'confirm' } } },
        { text: 'Отмена', callback: 'opsbot.action:{"action_id":"act-incident","step":"cancel"}' },
      ],
    });
    const outbound = await sendDev(server, { from: ANNA, callback_data: `a:${id}` });
    expect(bot.web.telegramAction).toHaveBeenCalledWith(
      {
        bot_key: 'platform',
        type: 'opsbot.action',
        payload: { action_id: 'act-incident', step: 'preview' },
        telegram_user_id: ANNA.id,
        chat_id: ANNA.id,
        workspace_id: 3,
        user_id: 7,
        locale: 'ru',
      },
      expect.any(String),
    );
    expect(outbound.find((call) => call.method === 'answerCallbackQuery')?.payload).toMatchObject({ text: 'Проверьте' });
    const card = sentMessages(outbound)[0]!;
    expect(card.text).toBe('<b>Инцидент</b>: Повреждение');
    const cancel = await bot.actions.get(callbackOf(card, 'Отмена').slice(2));
    expect(cancel).toMatchObject({ type: 'opsbot.action', payload: { action_id: 'act-incident', step: 'cancel' } });
  });

  it('runs local follow-up questions', async () => {
    const id = await bot.actions.put(
      { type: 'ask', payload: { question: 'And for windows?' }, telegram_user_id: ANNA.id, chat_id: ANNA.id, workspace_id: 3, user_id: 7, locale: 'ru', created_at: Date.now() },
      60,
    );
    const outbound = await sendDev(server, { from: ANNA, callback_data: `a:${id}` });
    expect(bot.ai.chat).toHaveBeenCalledWith(expect.objectContaining({ message: 'And for windows?' }), expect.any(String));
    expect(sentMessages(outbound)[0]?.text).toBe('<i>And for windows?</i>');
  });

  it('refuses foreign, expired and other-bot buttons', async () => {
    const base = { payload: {}, telegram_user_id: ANNA.id, chat_id: ANNA.id, workspace_id: 3, user_id: 7, locale: 'ru' as const, created_at: Date.now() };
    const id = await bot.actions.put({ ...base, type: 'escalate' }, 60);
    const forbidden = await sendDev(server, { from: BOB, callback_data: `a:${id}` });
    expect(forbidden[0]).toMatchObject({ method: 'answerCallbackQuery', payload: { text: 'This button belongs to another user.', show_alert: true } });
    const expired = await sendDev(server, { from: ANNA, callback_data: 'a:doesnotexist1' });
    expect(expired[0]?.payload).toMatchObject({ text: 'Срок действия кнопки истёк. Задайте вопрос ещё раз.' });
    const otherBot = await bot.actions.put({ ...base, bot_key: '7', type: 'escalate' }, 60);
    const wrongBot = await sendDev(server, { from: ANNA, callback_data: `a:${otherBot}` });
    expect(wrongBot[0]?.payload).toMatchObject({ text: 'Срок действия кнопки истёк. Задайте вопрос ещё раз.' });
    expect(bot.web.telegramAction).not.toHaveBeenCalled();
  });

  it('answers with a localized error when ai is down and asks unlinked users to link', async () => {
    bot.ai.chat.mockRejectedValueOnce(new UpstreamNetworkError('ai', 'POST', '/v1/chat', new Error('timeout')));
    expect(sentMessages(await sendDev(server, { from: ANNA, text: 'question' }))[0]?.text).toContain('Acme Desk временно недоступен');
    expect(sentMessages(await sendDev(server, { from: BOB, text: 'hello' }))[0]?.text).toContain('Your Telegram account is not linked to Acme Desk yet');
  });
});

describe('/lang, /help, /whoami', () => {
  it('offers an inline language keyboard and persists the choice per bot', async () => {
    const [message] = sentMessages(await sendDev(server, { from: BOB, text: '/lang' }));
    expect(message?.text).toBe('Choose your language:');
    expect(message?.reply_markup?.inline_keyboard?.[0]).toEqual([
      { text: 'Русский', callback_data: 'lang:ru' },
      { text: 'English', callback_data: 'lang:en' },
    ]);
    const outbound = await sendDev(server, { from: BOB, callback_data: 'lang:ru' });
    expect(bot.preferences.locales.get(`platform:${BOB.id}`)).toBe('ru');
    expect(outbound.map((call) => call.method)).toEqual(['answerCallbackQuery', 'editMessageText']);
    expect(sentMessages(await sendDev(server, { from: BOB, text: '/help' }))[0]?.text).toContain('Команды:');
  });

  it('lists role-aware commands in /help', async () => {
    const guest = sentMessages(await sendDev(server, { from: BOB, text: '/help' }))[0]?.text ?? '';
    expect(guest).toContain('/start');
    expect(guest).toContain('/privacy');
    expect(guest).not.toContain('/queue');
    expect(guest).not.toContain('/tasks');
    bot.contexts.set(ANNA.id, linkedContext());
    const employee = sentMessages(await sendDev(server, { from: ANNA, text: '/help' }))[0]?.text ?? '';
    expect(employee).toContain('/incident');
    expect(employee).toContain('/tasks');
    expect(employee).not.toContain('/queue');
    const oleg = { id: 7003, first_name: 'Oleg', language_code: 'en' };
    bot.contexts.set(oleg.id, linkedContext({ role: 'manager', user: { id: 9, name: 'Oleg', locale: 'en', kind: 'web' } }));
    const manager = sentMessages(await sendDev(server, { from: oleg, text: '/help' }))[0]?.text ?? '';
    expect(manager).toContain('/queue');
  });

  it('shows name, company, role and departments in /whoami', async () => {
    bot.contexts.set(BOB.id, linkedContext({ user: { id: 9, name: 'Bob <Admin>', locale: 'en', kind: 'web' }, role: 'admin' }));
    const text = sentMessages(await sendDev(server, { from: BOB, text: '/whoami' }))[0]?.text ?? '';
    expect(text).toContain('<b>Bob &lt;Admin&gt;</b>');
    expect(text).toContain('Company: <b>Clean Line</b>');
    expect(text).toContain('Role: administrator');
    expect(text).toContain('Departments: Residential cleaning');
  });

  it('answers unknown commands and non-text messages', async () => {
    expect(sentMessages(await sendDev(server, { from: BOB, text: '/nope' }))[0]?.text).toContain('I do not know this command');
  });
});

describe('rate limiting', () => {
  it('warns once and then drops messages over the per-chat limit', async () => {
    await server.close();
    await setup({ rateLimit: 2 });
    await sendDev(server, { from: BOB, text: '/help' });
    await sendDev(server, { from: BOB, text: '/help' });
    expect(sentMessages(await sendDev(server, { from: BOB, text: '/help' }))[0]?.text).toContain('too fast');
    expect(await sendDev(server, { from: BOB, text: '/help' })).toEqual([]);
  });
});

describe('module registry', () => {
  it('lets domain modules add commands, strings and action handlers', async () => {
    const extra: BotModule = {
      name: 'extra',
      commands: [{ command: 'extra', descriptionKey: 'extra.command' }],
      messages: { en: { extra: { command: 'Extra things', list: 'You have {count} things' } }, ru: { extra: { command: 'Дополнительно', list: 'Вещей: {count}' } } },
      register(composer) {
        composer.command('extra', async (ctx) => {
          await ctx.reply(ctx.t('extra.list', { count: 3 }));
        });
      },
      actions: {
        'extra.resolve': async (ctx, action) => {
          await ctx.reply(`resolved ${String(action.payload.arg)}`);
        },
      },
    };
    const modules = defaultModules();
    await server.close();
    await setup({ modules: [...modules.slice(0, -1), extra, modules.at(-1)!] });
    expect(sentMessages(await sendDev(server, { from: BOB, text: '/extra' }))[0]?.text).toBe('You have 3 things');
    expect(sentMessages(await sendDev(server, { from: BOB, text: '/help' }))[0]?.text).toContain('/extra — Extra things');
    const id = await bot.actions.put(
      { type: 'extra.resolve', payload: { value: 'extra.resolve:42', arg: '42' }, telegram_user_id: null, chat_id: BOB.id, workspace_id: 1, user_id: 1, locale: 'en', created_at: Date.now() },
      60,
    );
    expect(sentMessages(await sendDev(server, { from: BOB, callback_data: `a:${id}` }))[0]?.text).toBe('resolved 42');
    expect(bot.web.telegramAction).not.toHaveBeenCalled();
  });
});

import type { FastifyInstance } from 'fastify';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { UpstreamHttpError } from '../src/clients/errors.js';
import { companyCredentials, linkedContext, loggedQuery } from './helpers/fakes.js';
import { buttons, callbackOf, createTestBot, devServer, sendDev, sentMessages, type TestBot } from './helpers/bot.js';

const ANNA = { id: 900000001, username: 'anna', first_name: 'Anna', language_code: 'ru' };
const MARIA = { id: 900000003, username: 'maria', first_name: 'Maria', language_code: 'en' };
const OLEG = { id: 900000010, username: 'oleg', first_name: 'Oleg', language_code: 'ru' };
const GROUP = -1001234;

let bot: TestBot;
let server: FastifyInstance;

function employee(overrides: Parameters<typeof linkedContext>[0] = {}) {
  return linkedContext({
    telegram: { bot_key: '7', user_id: ANNA.id, chat_id: ANNA.id, username: 'anna', blocked: false },
    bot: { key: '7', username: 'clean_line_bot' },
    ...overrides,
  });
}

async function press(from: typeof ANNA, callback: string) {
  return sendDev(server, { bot_key: '7', from, callback_data: callback });
}

async function say(from: typeof ANNA, text: string, extra: Record<string, unknown> = {}) {
  return sendDev(server, { bot_key: '7', from, text, ...extra });
}

beforeEach(async () => {
  bot = createTestBot();
  bot.credentials.set(7, companyCredentials());
  server = await devServer(bot.registry);
});

afterEach(async () => {
  await server.close();
});

describe('private chats only (ADR-0011)', () => {
  it('sends one notice when added to a group and ignores the group afterwards', async () => {
    const added = await sendDev(server, { bot_key: '7', from: ANNA, chat_id: GROUP, chat_member_status: 'member' });
    const [notice] = sentMessages(added);
    expect(notice?.chat_id).toBe(GROUP);
    expect(notice?.text).toContain('Я отвечаю только в личных сообщениях');
    expect(buttons(notice)[0]).toMatchObject({ url: 'https://t.me/clean_line_bot' });
    expect(await sendDev(server, { bot_key: '7', from: ANNA, chat_id: GROUP, text: '@clean_line_bot hello' })).toEqual([]);
    expect(await sendDev(server, { bot_key: '7', from: ANNA, chat_id: GROUP, text: '/help@clean_line_bot' })).toEqual([]);
  });

  it('answers the first addressed group message once and stays silent for unaddressed chatter', async () => {
    expect(await sendDev(server, { bot_key: '7', from: MARIA, chat_id: GROUP, text: 'hello everyone' })).toEqual([]);
    const first = await sendDev(server, { bot_key: '7', from: MARIA, chat_id: GROUP, text: '/help@clean_line_bot' });
    expect(sentMessages(first)[0]?.text).toContain('I answer only in private chats');
    expect(await sendDev(server, { bot_key: '7', from: MARIA, chat_id: GROUP, text: '/help@clean_line_bot' })).toEqual([]);
    expect(bot.ai.chat).not.toHaveBeenCalled();
  });

  it('answers group callbacks only with the expired toast', async () => {
    const outbound = await sendDev(server, { bot_key: '7', from: MARIA, chat_id: GROUP, callback_data: 'a:abcdefghijkl' });
    expect(outbound).toEqual([{ bot_key: '7', method: 'answerCallbackQuery', payload: expect.objectContaining({ text: 'This button has expired. Ask your question again.' }) }]);
  });

  it('reports a user blocking and unblocking the bot', async () => {
    await sendDev(server, { bot_key: '7', from: ANNA, chat_member_status: 'kicked' });
    expect(bot.web.reportChatBlocked).toHaveBeenCalledWith('7', ANNA.id, true, expect.any(String));
    await sendDev(server, { bot_key: '7', from: ANNA, chat_member_status: 'member' });
    expect(bot.web.reportChatBlocked).toHaveBeenLastCalledWith('7', ANNA.id, false, expect.any(String));
  });
});

describe('employee notice gate', () => {
  beforeEach(() => {
    bot.contexts.set(ANNA.id, employee({ notice: { version: '2026-10', html: '<b>Что мы храним</b>' } }));
  });

  it('answers free text with the notice instead of calling ai', async () => {
    const outbound = await say(ANNA, 'Что делать с ключами?');
    expect(bot.ai.chat).not.toHaveBeenCalled();
    const [notice] = sentMessages(outbound);
    expect(notice?.text).toBe('<b>Что мы храним</b>');
    expect(buttons(notice)[0]?.text).toBe('Понятно');
  });

  it('lets /privacy, /help, /lang and /unlink through but gates other commands', async () => {
    expect(sentMessages(await say(ANNA, '/privacy'))[0]?.text).toContain('Ваши данные в Acme Desk');
    expect(sentMessages(await say(ANNA, '/help'))[0]?.text).toContain('Команды:');
    expect(sentMessages(await say(ANNA, '/tasks'))[0]?.text).toBe('<b>Что мы храним</b>');
    expect(bot.web.opsbotTasks).not.toHaveBeenCalled();
  });

  it('acknowledges the notice and invalidates the cached context', async () => {
    const [notice] = sentMessages(await say(ANNA, 'вопрос'));
    bot.contexts.set(ANNA.id, employee());
    const outbound = await press(ANNA, callbackOf(notice, 'Понятно'));
    expect(bot.web.acknowledgeNotice).toHaveBeenCalledWith('7', ANNA.id, '2026-10', expect.any(String));
    expect(sentMessages(outbound)[0]?.text).toBe('Спасибо. Теперь можно задать вопрос.');
    await say(ANNA, 'Что делать с ключами?');
    expect(bot.ai.chat).toHaveBeenCalledTimes(1);
  });
});

describe('subscription and limits', () => {
  it('in paused state refuses free text but offers the incident flow', async () => {
    bot.contexts.set(ANNA.id, employee({ workspace: { ...employee().workspace, subscription_state: 'paused' } }));
    const [reply] = sentMessages(await say(ANNA, 'Что делать?'));
    expect(bot.ai.chat).not.toHaveBeenCalled();
    expect(bot.web.logAiQuery).not.toHaveBeenCalled();
    expect(reply?.text).toContain('приостановлены');
    const flow = await press(ANNA, callbackOf(reply, 'Сообщить об инциденте'));
    expect(sentMessages(flow)[0]?.text).toContain('Новый инцидент');
    expect(sentMessages(await say(ANNA, '/ask_manager'))[0]?.text).toContain('приостановлены');
    expect(bot.web.escalateLastQuestion).not.toHaveBeenCalled();
  });

  it('with exhausted ai limits logs the question as limit_reached and offers escalation', async () => {
    bot.contexts.set(ANNA.id, employee({ limits: { ai_queries_exhausted: true } }));
    bot.web.logAiQuery.mockResolvedValueOnce(
      loggedQuery({ actions: [{ id: 'act-esc', type: 'escalate', label: 'Передать менеджеру', url: null, status: 'proposed' }], citations: [] }),
    );
    const [reply] = sentMessages(await say(ANNA, 'Сколько ждать клиента?'));
    expect(bot.ai.chat).not.toHaveBeenCalled();
    expect(bot.web.logAiQuery).toHaveBeenCalledWith(
      expect.objectContaining({
        refused: true,
        refusal_reason: 'limit_reached',
        question: 'Сколько ждать клиента?',
        actions: [{ type: 'escalate', label: 'Передать менеджеру', payload: { reason: 'limit_reached' } }],
      }),
      expect.any(String),
    );
    expect(reply?.text).toContain('исчерпала лимит');
    expect(buttons(reply)[0]?.text).toBe('Передать менеджеру');
  });
});

describe('incident flow', () => {
  beforeEach(() => {
    bot.contexts.set(ANNA.id, employee());
  });

  it('walks category → description → confirmation → web', async () => {
    const [categories] = sentMessages(await say(ANNA, '/incident'));
    const keyboard = categories?.reply_markup?.inline_keyboard ?? [];
    expect(keyboard.slice(0, 3).map((row) => row.length)).toEqual([2, 2, 2]);
    expect(buttons(categories).map((button) => button.text)).toEqual([
      'Повреждение',
      'Жалоба',
      'Нет доступа',
      'Травма',
      'Пропажа',
      'Другое',
      'Отмена',
    ]);
    const [prompt] = sentMessages(await press(ANNA, callbackOf(categories, 'Повреждение')));
    expect(prompt?.text).toContain('Опишите, что произошло');
    expect(prompt?.reply_markup?.force_reply).toBe(true);
    const [card] = sentMessages(await say(ANNA, 'Клиент Иванова, кухня: поцарапала стол <b>'));
    expect(card?.text).toContain('Категория: Повреждение');
    expect(card?.text).toContain('<blockquote>Клиент Иванова, кухня: поцарапала стол &lt;b&gt;</blockquote>');
    const done = await press(ANNA, callbackOf(card, 'Подтвердить'));
    expect(bot.web.createIncident).toHaveBeenCalledWith(
      { bot_key: '7', telegram_user_id: ANNA.id, category: 'damage', description: 'Клиент Иванова, кухня: поцарапала стол <b>' },
      expect.any(String),
    );
    expect(sentMessages(done)[0]?.text).toBe('Инцидент <b>№ 12</b> создан. Ответственный: Олег Петров.');
    const again = await press(ANNA, callbackOf(card, 'Подтвердить'));
    expect(sentMessages(again)[0]?.text).toContain('устарел');
    expect(bot.web.createIncident).toHaveBeenCalledTimes(1);
  });

  it('rejects overly long descriptions and is cancelled by any command', async () => {
    const [categories] = sentMessages(await say(ANNA, '/incident'));
    await press(ANNA, callbackOf(categories, 'Жалоба'));
    expect(sentMessages(await say(ANNA, 'x'.repeat(1001)))[0]?.text).toContain('1000');
    await say(ANNA, '/help');
    await say(ANNA, 'обычный вопрос');
    expect(bot.ai.chat).toHaveBeenCalledTimes(1);
  });

  it('cancels on request and refuses employees without the permission', async () => {
    const [categories] = sentMessages(await say(ANNA, '/incident'));
    expect(sentMessages(await press(ANNA, callbackOf(categories, 'Отмена')))[0]?.text).toBe('Инцидент не создан.');
    bot.contexts.set(MARIA.id, employee({ permissions: ['assistant.use'], user: { id: 8, name: 'Maria', locale: 'en', kind: 'telegram_only' }, telegram: { bot_key: '7', user_id: MARIA.id, chat_id: MARIA.id, username: 'maria', blocked: false } }));
    expect(sentMessages(await say(MARIA, '/incident'))[0]?.text).toContain('You cannot report incidents');
  });
});

describe('manager answer flow (ADR-0014)', () => {
  beforeEach(() => {
    bot.contexts.set(OLEG.id, employee({ role: 'manager', user: { id: 2, name: 'Олег Петров', locale: 'ru', kind: 'web' }, telegram: { bot_key: '7', user_id: OLEG.id, chat_id: OLEG.id, username: 'oleg', blocked: false } }));
  });

  it('lists the queue with answer buttons and captures the force-reply answer', async () => {
    bot.web.opsbotQueue.mockResolvedValueOnce([
      { id: 15, display_number: 'Q-15', question_excerpt: 'Какой пароль от <Wi-Fi>?', employee_name: 'Айгуль', age_hours: 26 },
    ]);
    const [queue] = sentMessages(await say(OLEG, '/queue'));
    expect(queue?.text).toContain('<b>1 вопрос ждёт вашего ответа</b>');
    expect(queue?.text).toContain('<b>Q-15</b> от Айгуль, 26 часов назад');
    expect(queue?.text).toContain('<blockquote>Какой пароль от &lt;Wi-Fi&gt;?</blockquote>');
    const [prompt] = sentMessages(await press(OLEG, callbackOf(queue, 'Ответить Q-15')));
    expect(prompt?.text).toBe('Ответьте на это сообщение ответом на вопрос Q-15.');
    expect(prompt?.reply_markup?.force_reply).toBe(true);

    const unrelated = await say(OLEG, 'Сколько ждать клиента?');
    expect(bot.web.answerQuestion).not.toHaveBeenCalled();
    expect(bot.ai.chat).toHaveBeenCalledTimes(1);
    expect(sentMessages(unrelated).length).toBeGreaterThan(0);

    const promptId = (await bot.state.get(`reply:7:${OLEG.id}`)) ?? '';
    const promptMessageId = JSON.parse(promptId).prompt_message_id as number;
    bot.web.answerQuestion.mockResolvedValueOnce({
      ok: true,
      message_html: 'Ответ отправлен сотруднику.',
      buttons: [{ text: 'Сделать FAQ', callback: 'q_convert:15' }],
    });
    const answered = await say(OLEG, 'Пароль у администратора объекта.', { reply_to_message_id: promptMessageId });
    expect(bot.web.answerQuestion).toHaveBeenCalledWith(15, { bot_key: '7', telegram_user_id: OLEG.id, text: 'Пароль у администратора объекта.' }, expect.any(String));
    const [confirmation] = sentMessages(answered);
    expect(confirmation?.text).toBe('Ответ отправлен сотруднику.');
    expect(await bot.actions.get(callbackOf(confirmation, 'Сделать FAQ').slice(2))).toMatchObject({ type: 'q_convert', payload: { id: 15 } });
    expect(await bot.state.get(`reply:7:${OLEG.id}`)).toBeNull();
  });

  it('ignores replies to other messages and expired prompts', async () => {
    const [prompt] = sentMessages(
      await press(OLEG, await (async () => {
        const id = await bot.actions.put({ bot_key: '7', type: 'q_answer', payload: { id: 15, display_number: 'Q-15' }, telegram_user_id: OLEG.id, chat_id: OLEG.id, workspace_id: 3, user_id: 2, locale: 'ru', created_at: Date.now() }, 60);
        return `a:${id}`;
      })()),
    );
    expect(prompt?.text).toContain('Q-15');
    await say(OLEG, 'не ответ', { reply_to_message_id: 1 });
    expect(bot.web.answerQuestion).not.toHaveBeenCalled();
    await bot.state.del(`reply:7:${OLEG.id}`);
    await say(OLEG, 'поздно', { reply_to_message_id: 1000 });
    expect(bot.web.answerQuestion).not.toHaveBeenCalled();
  });

  it('forbids /queue for employees', async () => {
    bot.contexts.set(ANNA.id, employee());
    expect(sentMessages(await say(ANNA, '/queue'))[0]?.text).toBe('Эта команда доступна только менеджерам.');
    expect(bot.web.opsbotQueue).not.toHaveBeenCalled();
  });
});

describe('employee commands', () => {
  beforeEach(() => {
    bot.contexts.set(ANNA.id, employee());
  });

  it('/tasks lists open tasks with start and done buttons', async () => {
    bot.web.opsbotTasks.mockResolvedValueOnce([
      { id: 34, display_number: 'T-34', title: 'Вымыть окна', status: 'todo', due_local: '22 окт., 14:30', overdue: true },
      { id: 35, display_number: 'T-35', title: 'Сдать ключи', status: 'in_progress', due_local: null, overdue: false },
    ]);
    const [list] = sentMessages(await say(ANNA, '/tasks'));
    expect(bot.web.opsbotTasks).toHaveBeenCalledWith({ workspace_id: 3, user_id: 7, status: 'open', limit: 10 }, expect.any(String));
    expect(list?.text).toContain('<b>У вас 2 открытые задачи</b>');
    expect(list?.text).toContain('• <b>T-34</b> Вымыть окна — срок 22 окт., 14:30, просрочена');
    expect(list?.text).toContain('• <b>T-35</b> Сдать ключи — в работе');
    expect(buttons(list).map((button) => button.text)).toEqual(['Начать T-34', 'Готово T-34', 'Готово T-35']);
    bot.web.telegramAction.mockResolvedValueOnce({ ok: true, message: 'Задача T-35 выполнена', message_html: null });
    const done = await press(ANNA, callbackOf(list, 'Готово T-35'));
    expect(bot.web.telegramAction).toHaveBeenCalledWith(expect.objectContaining({ bot_key: '7', type: 'task_done', payload: { id: 35 } }), expect.any(String));
    expect(done.find((call) => call.method === 'answerCallbackQuery')?.payload).toMatchObject({ text: 'Задача T-35 выполнена' });
  });

  it('/history shows the last questions with their outcome', async () => {
    bot.web.opsbotHistory.mockResolvedValueOnce([
      { asked_local: '22 окт., 14:30', question: 'Сколько ждать?', refused: false, manager_answered: false },
      { asked_local: '21 окт., 10:00', question: 'Пароль Wi-Fi?', refused: true, manager_answered: true },
    ]);
    const text = sentMessages(await say(ANNA, '/history'))[0]?.text ?? '';
    expect(text).toContain('• 22 окт., 14:30 — Сколько ждать?\n  <i>ответ из документов</i>');
    expect(text).toContain('<i>ответил менеджер</i>');
  });

  it('/ask_manager escalates the last question through web', async () => {
    const reply = sentMessages(await say(ANNA, '/ask_manager'))[0];
    expect(bot.web.escalateLastQuestion).toHaveBeenCalledWith('7', ANNA.id, expect.any(String));
    expect(reply?.text).toBe('Passed to Oleg.');
    bot.web.escalateLastQuestion.mockRejectedValueOnce(
      new UpstreamHttpError('web', 'POST', '/internal/v1/opsbot/questions/escalate-last', 404, { message_html: 'Нет вопросов для передачи.' }),
    );
    expect(sentMessages(await say(ANNA, '/ask_manager'))[0]?.text).toBe('Нет вопросов для передачи.');
  });

  it('/unlink asks for confirmation and unlinks through web', async () => {
    const [confirm] = sentMessages(await say(ANNA, '/unlink'));
    expect(confirm?.text).toContain('<b>Clean Line</b>');
    const done = await press(ANNA, callbackOf(confirm, 'Отвязать'));
    expect(bot.web.unlinkTelegram).toHaveBeenCalledWith('7', ANNA.id, expect.any(String));
    expect(sentMessages(done)[0]?.text).toContain('Telegram отвязан');
  });

  it('/privacy explains who sees the data and how to request deletion', async () => {
    const text = sentMessages(await say(MARIA, '/privacy'))[0]?.text ?? '';
    expect(text).toContain('Your manager and the company administrators can see your questions');
    expect(text).toContain('export or erase it');
  });
});

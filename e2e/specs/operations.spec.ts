import type { Page } from '@playwright/test';
import { expect, test, uniqueId, type AppLocale } from '../fixtures.ts';
import { DevTelegram, findButton, newTelegramPerson, textOf, type CapturedCall, type TelegramPerson } from '../support/bot.ts';
import { appRequest } from '../support/http.ts';
import { inertiaPage, inertiaProps } from '../support/inertia.ts';

const COPY: Record<AppLocale, { understand: RegExp; unknown: string; pass: RegExp; confirm: RegExp; answer: RegExp; header: RegExp; reply: string; privately: RegExp }> = {
  en: {
    understand: /I understand/i,
    unknown: 'How many vacation days do I get per year?',
    pass: /manager/i,
    confirm: /confirm/i,
    answer: /^Answer/,
    header: /Manager's answer \(not from the regulations\)/,
    reply: 'You get 28 days, ask HR for the schedule.',
    privately: /privately/i,
  },
  ru: {
    understand: /Понятно/i,
    unknown: 'Сколько дней отпуска мне положено в году?',
    pass: /менеджер/i,
    confirm: /Подтвер/i,
    answer: /^Ответить/,
    header: /Ответ менеджера \(не из регламента\)/,
    reply: 'Вам положено 28 дней, график уточните у кадровика.',
    privately: /личн/i,
  },
};

interface SharedProps {
  workspace: { bot: { key: string; username: string | null; status: string } | null } | null;
}

async function connectBot(page: Page, id: string): Promise<string> {
  const token = `${700_000_000 + Math.floor(Math.random() * 99_999_999)}:TEST${id.replace(/[^A-Za-z0-9]/g, '').padEnd(30, 'b').slice(0, 30)}`;
  await page.goto('/bot');
  await page.getByTestId('bot-token-input').fill(token);
  await page.getByTestId('bot-connect').click();
  await expect(async () => {
    const shared = (await inertiaPage<SharedProps>(page)).props;
    expect(shared.workspace?.bot?.status).toBe('active');
  }).toPass({ timeout: 30_000 });
  return (await inertiaPage<SharedProps>(page)).props.workspace!.bot!.key;
}

async function linkEmployee(page: Page, telegram: DevTelegram, person: TelegramPerson, name: string, locale: AppLocale): Promise<number> {
  const created = await appRequest(page, '/employees', { method: 'POST', data: { name, department_ids: [], primary_department_id: null, locale } });
  expect(created.status(), 'POST /employees').toBeLessThan(400);
  const props = await inertiaProps<{ employees: { data: Array<{ id: number; name: string }> } }>(page, `/employees?q=${encodeURIComponent(name)}`);
  const employee = props.employees.data.find((row) => row.name === name)!;
  const link = (await (await appRequest(page, `/employees/${employee.id}/link`, { method: 'POST' })).json()) as { url: string };
  const start = new URL(link.url).searchParams.get('start');
  const welcome = await telegram.say(person, `/start ${start}`);
  await telegram.press(person, findButton(welcome, COPY[locale].understand));
  return employee.id;
}

async function linkOwner(page: Page, telegram: DevTelegram, owner: TelegramPerson, locale: AppLocale): Promise<void> {
  await page.goto('/settings/integrations');
  const response = page.waitForResponse((r) => r.request().method() === 'POST' && /\/settings\/integrations\/telegram\/link$/.test(new URL(r.url()).pathname));
  await page.getByTestId('telegram-link-button').click();
  const { url } = (await (await response).json()) as { url: string };
  const start = new URL(url).searchParams.get('start');
  const welcome = await telegram.say(owner, `/start ${start}`);
  const notice = findButton(welcome, COPY[locale].understand);
  if (notice) await telegram.press(owner, notice);
}

async function waitForCalls(read: () => Promise<CapturedCall[]>, predicate: (calls: CapturedCall[]) => boolean, timeout = 60_000): Promise<CapturedCall[]> {
  let last: CapturedCall[] = [];
  await expect
    .poll(
      async () => {
        last = await read();
        return predicate(last);
      },
      { timeout, intervals: [1_000, 2_000] },
    )
    .toBe(true);
  return last;
}

test.describe('operations in Telegram', () => {
  test('a refused question reaches the manager in Telegram and the force-reply answer is delivered', { tag: '@flow' }, async ({
    page,
    app,
    owner: _owner,
    request,
  }, testInfo) => {
    test.setTimeout(240_000);
    const copy = COPY[app.locale];
    const id = uniqueId(testInfo);
    const botKey = await connectBot(page, id);
    const telegram = new DevTelegram(request, app.config.workerURL, botKey);
    const employee = newTelegramPerson(app.locale, 'Employee');
    const manager = newTelegramPerson(app.locale, 'Owner');
    await linkEmployee(page, telegram, employee, `E2E Employee ${id.slice(-5)}`, app.locale);
    await linkOwner(page, telegram, manager, app.locale);

    const refusal = await telegram.say(employee, copy.unknown);
    let passed = await telegram.press(employee, findButton(refusal, copy.pass));
    const confirm = findButton(passed, copy.confirm);
    if (confirm) passed = await telegram.press(employee, confirm);

    const escalated = await waitForCalls(
      () => telegram.outboxFor(manager),
      (calls) => calls.some((call) => findButton([call], copy.answer)),
    );
    const prompt = await telegram.press(manager, findButton(escalated, copy.answer));
    const forceReply = prompt.find((call) => (call.payload.reply_markup as { force_reply?: boolean } | undefined)?.force_reply);
    expect(forceReply?.message_id, `no force-reply prompt in ${JSON.stringify(prompt)}`).toEqual(expect.any(Number));

    const sent = await telegram.say(manager, copy.reply, { reply_to_message_id: forceReply!.message_id });
    expect(textOf(sent).length).toBeGreaterThan(0);

    const delivered = await waitForCalls(
      () => telegram.outboxFor(employee),
      (calls) => copy.header.test(textOf(calls)),
    );
    expect(textOf(delivered)).toContain(copy.reply.slice(0, 20));

    const props = await inertiaProps<{ questions: { data: Array<{ question: string; status: string }> } }>(page, '/unanswered?status=all');
    expect(props.questions.data.find((row) => row.question === copy.unknown)?.status).toBe('answered');
  });

  test('group chats get a private-chat notice and no answer', { tag: '@flow' }, async ({ page, app, owner: _owner, request }, testInfo) => {
    const id = uniqueId(testInfo);
    const botKey = await connectBot(page, id);
    const telegram = new DevTelegram(request, app.config.workerURL, botKey);
    const employee = newTelegramPerson(app.locale, 'Employee');
    await linkEmployee(page, telegram, employee, `E2E Group ${id.slice(-5)}`, app.locale);

    const username = (await inertiaPage<SharedProps>(page)).props.workspace!.bot!.username!;
    const group = -1_000_000_000 - Math.floor(Math.random() * 1_000_000);
    const ignored = await telegram.say(employee, COPY[app.locale].unknown, { chat_id: group });
    expect(ignored.filter((call) => call.method === 'sendMessage'), 'the bot must stay silent unless addressed').toEqual([]);
    const reply = await telegram.say(employee, `@${username} ${COPY[app.locale].unknown}`, { chat_id: group });
    const button = findButton(reply, COPY[app.locale].privately);
    expect(button?.url, `group reply: ${JSON.stringify(reply)}`).toMatch(/^https:\/\/t\.me\//);
    expect(textOf(reply)).not.toContain('/s/doc/');
  });

  test('offboarding cuts the bot off, erase removes the person and disconnecting the bot stops the webhook', { tag: '@flow' }, async ({
    page,
    app,
    owner,
    request,
  }, testInfo) => {
    test.setTimeout(240_000);
    const id = uniqueId(testInfo);
    const botKey = await connectBot(page, id);
    const telegram = new DevTelegram(request, app.config.workerURL, botKey);
    const person = newTelegramPerson(app.locale, 'Leaver');
    const name = `E2E Leaver ${id.slice(-5)}`;
    const employeeId = await linkEmployee(page, telegram, person, name, app.locale);

    await page.goto('/employees');
    const row = page.locator(`[data-test="employees-row"][data-user-id="${employeeId}"]`);
    await row.getByRole('button', { name: new RegExp(name) }).click();
    await page.getByTestId('employee-offboard').click();
    await page.getByTestId('employee-offboard-confirm').click();
    await expect(row).toHaveAttribute('data-status', 'offboarded');

    const after = await telegram.say(person, '/whoami');
    expect(textOf(after)).not.toContain(owner.workspace);

    await row.getByRole('button', { name: new RegExp(name) }).click();
    await page.getByTestId('employee-erase').click();
    await page.getByTestId('employee-erase-password').fill(owner.password);
    await page.locator('#confirm-text').fill(name);
    await page.getByTestId('employee-erase-confirm').click();
    await expect(page.getByText(name)).toHaveCount(0, { timeout: 20_000 });

    await page.goto('/bot');
    const botUsername = (await inertiaPage<SharedProps>(page)).props.workspace!.bot!.username!;
    await page.getByTestId('bot-disconnect').click();
    await page.locator('#confirm-text').fill(`@${botUsername}`);
    await page.getByTestId('bot-disconnect-confirm').click();
    await expect(async () => {
      const props = await inertiaProps<{ bot: { status: string } | null }>(page, '/bot');
      expect(props.bot === null || props.bot.status === 'disconnected').toBe(true);
    }).toPass({ timeout: 60_000, intervals: [1_000, 2_000] });

    const gone = await request.post(`${app.config.workerURL}/dev/telegram/update`, {
      data: { bot_key: botKey, from: { id: person.id, first_name: 'Leaver' }, text: '/start' },
    });
    expect(gone.status()).toBe(404);
  });
});

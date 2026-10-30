import { join } from 'node:path';
import { expect, test, uniqueId, type AppLocale } from '../fixtures.ts';
import { DevTelegram, findButton, linksOf, newTelegramPerson, textOf, type CapturedCall } from '../support/bot.ts';
import { E2E_DIR } from '../support/config.ts';
import { appRequest } from '../support/http.ts';
import { inertiaPage, inertiaProps } from '../support/inertia.ts';

const REGULATION = join(E2E_DIR, 'data', 'regulation.pdf');

const COPY: Record<AppLocale, { country: string; currency: string; damage: string; unknown: string; understand: RegExp; createIncident: RegExp; confirm: RegExp; passToManager: RegExp; managerHeader: RegExp; answer: string }> = {
  en: {
    country: 'US',
    currency: 'USD',
    damage: "What should I do if I scratched a client's table?",
    unknown: 'What is the Wi-Fi password for the guest network?',
    understand: /I understand/i,
    createIncident: /incident/i,
    confirm: /confirm/i,
    passToManager: /manager/i,
    managerHeader: /Manager's answer/,
    answer: 'The guest network password is printed on the router in the hall.',
  },
  ru: {
    country: 'RU',
    currency: 'RUB',
    damage: 'Что делать, если я поцарапала стол у клиента?',
    unknown: 'Какой пароль от гостевого Wi-Fi?',
    understand: /Понятно|Я понимаю|Ознакомлен/i,
    createIncident: /инцидент/i,
    confirm: /Подтвер/i,
    passToManager: /менеджер/i,
    managerHeader: /Ответ менеджера/,
    answer: 'Пароль от гостевой сети написан на роутере в прихожей.',
  },
};

interface SharedProps {
  workspace: { id: number; timezone: string; country?: string; currency?: string; bot: { key: string; username: string | null; status: string } | null } | null;
}

interface IncidentRow {
  id: number;
  number: number;
  status: string;
}

interface QuestionRow {
  id: number;
  question: string;
  status: string;
}

function localInput(date: Date, timeZone: string): string {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
      .formatToParts(date)
      .map((part) => [part.type, part.value]),
  );
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

async function waitForCalls(read: () => Promise<CapturedCall[]>, predicate: (calls: CapturedCall[]) => boolean, timeout: number): Promise<CapturedCall[]> {
  let last: CapturedCall[] = [];
  await expect
    .poll(
      async () => {
        last = await read();
        return predicate(last);
      },
      { timeout, intervals: [1_000, 2_000, 3_000] },
    )
    .toBe(true);
  return last;
}

test.describe('OpsBot journey', () => {
  test('company signup → bot → knowledge → employee in Telegram → incident, task, reminder, unanswered → paid plan', { tag: '@journey' }, async ({
    page,
    app,
    request,
    openSession,
  }, testInfo) => {
    test.setTimeout(600_000);
    const copy = COPY[app.locale];
    const id = uniqueId(testInfo);

    await test.step('register the company and create the workspace', async () => {
      await page.goto('/');
      await app.setLocale(app.locale);
      await page.goto('/register');
      await expect(page.getByTestId('register-terms')).not.toBeChecked();
      if (app.locale === 'ru') await expect(page.getByTestId('register-consent')).not.toBeChecked();
      else await expect(page.getByTestId('register-consent')).toHaveCount(0);
      await app.axe('journey-register');
      const account = await app.register('journey');
      account.workspace = `E2E Clean ${id}`;
      await page.goto('/onboarding/workspace');
      await page.locator('input[name="name"]').fill(account.workspace);
      await expect(page.getByTestId('onboarding-accept-dpa')).not.toBeChecked();
      await page.getByTestId('onboarding-accept-dpa').check();
      for (let step = 0; step < 3 && !(await page.getByTestId('onboarding-country').isVisible()); step++) {
        await page.getByTestId('onboarding-next').click();
      }
      await expect(page.getByTestId('onboarding-market-caption')).toContainText(copy.currency);
      while (!(await page.getByTestId('onboarding-submit').isVisible())) await page.getByTestId('onboarding-next').click();
      await page.getByTestId('onboarding-submit').click();
      await expect(page).toHaveURL(/\/onboarding\/setup/, { timeout: 20_000 });
      const shared = (await inertiaPage<SharedProps>(page)).props;
      expect(shared.workspace?.country).toBe(copy.country);
      expect(shared.workspace?.currency).toBe(copy.currency);
      await app.shot('journey-setup');
    });

    const token = `${700_000_000 + Math.floor(Math.random() * 99_999_999)}:TEST${'x'.repeat(10)}${id.replace(/[^A-Za-z0-9]/g, '').padEnd(24, 'a').slice(0, 24)}`;
    let botKey = '';

    await test.step('connect the company bot through the fake transport', async () => {
      await page.getByTestId('onboarding-bot-token').fill(token);
      await page.getByTestId('onboarding-bot-connect').click();
      await expect(page.getByTestId('onboarding-bot-status')).toHaveAttribute('data-state', 'active', { timeout: 30_000 });
      await page.goto('/bot');
      await expect(page.getByTestId('bot-status')).toBeVisible();
      const shared = (await inertiaPage<SharedProps>(page)).props;
      expect(shared.workspace?.bot?.status).toBe('active');
      botKey = shared.workspace!.bot!.key;
      expect(botKey).toMatch(/^[1-9][0-9]*$/);
      expect(await page.content(), 'the bot token must never reach the browser').not.toContain(token);
      await app.shot('journey-bot');
    });

    const title = `${app.locale === 'ru' ? 'Регламент повреждений' : 'Damage regulation'} ${id}`;
    let documentId = 0;

    await test.step('upload the regulation, wait for indexing and approve it', async () => {
      await page.goto('/knowledge');
      await page.getByTestId('knowledge-upload').click();
      const dialog = page.getByRole('dialog');
      await dialog.getByTestId('knowledge-upload-file').setInputFiles(REGULATION);
      await dialog.locator('#upload-title').fill(title);
      await dialog.getByTestId('knowledge-upload-submit').click();
      await expect(dialog).toBeHidden({ timeout: 30_000 });
      const row = page.getByTestId('knowledge-row').filter({ hasText: title });
      await expect(row).toBeVisible();
      await expect(async () => {
        await page.reload();
        await expect(row).toHaveAttribute('data-status', 'indexed', { timeout: 2_000 });
      }).toPass({ timeout: 180_000, intervals: [1_000, 2_000, 3_000] });
      documentId = Number(await row.getAttribute('data-document-id'));
      await page.goto(`/knowledge/${documentId}`);
      await page.getByTestId('knowledge-approve').click();
      await page.getByTestId('knowledge-approve-confirm').click();
      await expect(async () => {
        await page.goto('/knowledge');
        await expect(page.getByTestId('knowledge-row').filter({ hasText: title })).toHaveAttribute('data-approval', 'approved', { timeout: 2_000 });
      }).toPass({ timeout: 30_000 });
      await app.shot('journey-knowledge');
    });

    const employeeName = app.locale === 'ru' ? `Айгуль Тестова ${id.slice(-4)}` : `Maria Test ${id.slice(-4)}`;
    const employee = newTelegramPerson(app.locale, app.locale === 'ru' ? 'Айгуль' : 'Maria');
    const telegram = new DevTelegram(request, app.config.workerURL, botKey);
    let employeeId = 0;

    await test.step('create the employee, issue a link and redeem it in Telegram', async () => {
      await page.goto('/employees');
      await page.getByTestId('employees-add').click();
      await page.getByTestId('employee-form-name').fill(employeeName);
      await page.getByTestId('employee-form-submit').click();
      const dialog = page.getByTestId('employee-link-dialog');
      await expect(dialog).toBeVisible({ timeout: 20_000 });
      const url = await dialog.getByTestId('employee-link-url').inputValue();
      const start = new URL(url).searchParams.get('start');
      expect(start, `deep link without start payload: ${url}`).toMatch(/^emp_/);
      await app.shot('journey-employee-link');
      await page.keyboard.press('Escape');

      const welcome = await telegram.say(employee, `/start ${start}`);
      const understand = findButton(welcome, copy.understand);
      expect(understand, `notice button missing in: ${textOf(welcome)}`).toBeTruthy();
      const acknowledged = await telegram.press(employee, understand);
      expect(textOf(acknowledged).length).toBeGreaterThan(0);

      await page.reload();
      const row = page.getByTestId('employees-row').filter({ hasText: employeeName });
      await expect(row).toHaveAttribute('data-telegram-state', 'linked');
      employeeId = Number(await row.getAttribute('data-user-id'));
    });

    let incidentButtonCalls: CapturedCall[] = [];

    await test.step('the employee asks a damage question and gets a cited answer', async () => {
      const reply = await telegram.say(employee, copy.damage);
      const text = textOf(reply);
      const link = linksOf(reply).find((href) => href.includes('/s/doc/'));
      expect(link, `answer without a signed source link:\n${text}`).toBeTruthy();
      expect(text).toContain(title);
      const create = findButton(reply, copy.createIncident);
      expect(create, `answer without a "Create incident" button:\n${JSON.stringify(reply, null, 2)}`).toBeTruthy();
      incidentButtonCalls = reply;

      const viewer = await openSession();
      await viewer.page.goto(link!);
      await expect(viewer.page.getByTestId('viewer-title')).toContainText(title);
      await expect(viewer.page.getByTestId('viewer-excerpt')).toBeVisible();
      await expect(viewer.page.getByTestId('viewer-mark').first()).toBeVisible();
      await viewer.app.shot('journey-viewer');
      await viewer.app.axe('journey-viewer');
    });

    let incident: IncidentRow | undefined;

    await test.step('the employee creates an incident from the answer', async () => {
      let calls = await telegram.press(employee, findButton(incidentButtonCalls, copy.createIncident));
      const confirm = findButton(calls, copy.confirm);
      if (confirm) calls = await telegram.press(employee, confirm);
      expect(textOf(calls)).toMatch(/№\s?\d+|#\d+/);

      await expect(async () => {
        const props = await inertiaProps<{ incidents: { data: IncidentRow[] } }>(page, '/incidents?view=table');
        incident = props.incidents.data[0];
        expect(incident).toBeTruthy();
      }).toPass({ timeout: 20_000 });
      await page.goto('/incidents');
      await expect(page.getByTestId('incident-card').first()).toBeVisible();
      await app.shot('journey-incidents');
    });

    const taskTitle = `${app.locale === 'ru' ? 'Позвонить клиенту' : 'Call the client'} ${id.slice(-5)}`;

    await test.step('the manager creates a task from the incident and the assignee is notified', async () => {
      const shared = (await inertiaPage<SharedProps>(page)).props;
      const timezone = shared.workspace?.timezone ?? 'UTC';
      const quiet = await appRequest(page, '/settings/assistant', {
        method: 'PATCH',
        data: { min_confidence: 0.4, quiet_hours_start: '00:00', quiet_hours_end: '00:00', reminders_enabled: true },
      });
      expect(quiet.status(), 'PATCH /settings/assistant').toBeLessThan(400);

      await page.goto(`/incidents/${incident!.id}`);
      await page.getByTestId('incident-create-task').click();
      await page.getByTestId('task-form-title').fill(taskTitle);
      const due = new Date(Date.now() + 2 * 60_000);
      await page.getByTestId('task-form-due').fill(localInput(due, timezone));
      await page.getByTestId('task-form-assignee').click();
      await page.getByRole('option', { name: employeeName }).click();
      await page.getByTestId('task-form-submit').click();
      await expect(page.getByText(taskTitle).first()).toBeVisible({ timeout: 20_000 });
      await app.shot('journey-incident-task');

      const assigned = await waitForCalls(
        () => telegram.outboxFor(employee),
        (calls) => textOf(calls).includes(taskTitle),
        60_000,
      );
      expect(textOf(assigned)).toMatch(/T-\d+/);
    });

    await test.step('the task reminder reaches the assignee', async () => {
      const reminder = await waitForCalls(
        () => telegram.outboxFor(employee),
        (calls) => calls.filter((call) => String(call.payload.text ?? '').includes('T-')).length >= 2,
        240_000,
      );
      expect(textOf(reminder)).toMatch(app.locale === 'ru' ? /Наступил срок задачи T-\d+/ : /Task T-\d+ is due now/);
      await page.goto(`/tasks`);
      await expect(page.getByTestId('task-card').filter({ hasText: taskTitle })).toBeVisible();
    });

    await test.step('an unanswered question is logged, answered by the manager and delivered', async () => {
      const refusal = await telegram.say(employee, copy.unknown);
      expect(linksOf(refusal).some((href) => href.includes('/s/doc/')), 'a refusal must not carry sources').toBe(false);
      let calls = await telegram.press(employee, findButton(refusal, copy.passToManager));
      const confirm = findButton(calls, copy.confirm);
      if (confirm) calls = await telegram.press(employee, confirm);

      let question: QuestionRow | undefined;
      await expect(async () => {
        const props = await inertiaProps<{ questions: { data: QuestionRow[] } }>(page, '/unanswered');
        question = props.questions.data.find((row) => row.question.includes(copy.unknown.slice(0, 20)));
        expect(question).toBeTruthy();
      }).toPass({ timeout: 20_000 });
      const card = page.getByTestId('unanswered-card').filter({ hasText: copy.unknown.slice(0, 20) });
      await expect(card).toHaveAttribute('data-status', 'open');
      await app.shot('journey-unanswered');
      await card.getByTestId('unanswered-answer-input').fill(copy.answer);
      await card.getByTestId('unanswered-answer-submit').click();
      await expect(async () => {
        const props = await inertiaProps<{ questions: { data: QuestionRow[] } }>(page, '/unanswered?status=all');
        expect(props.questions.data.find((row) => row.id === question!.id)?.status).toBe('answered');
      }).toPass({ timeout: 20_000 });

      const delivered = await waitForCalls(
        () => telegram.outboxFor(employee),
        (all) => copy.managerHeader.test(textOf(all)),
        60_000,
      );
      expect(textOf(delivered)).toContain(copy.answer);
    });

    await test.step('billing shows usage and upgrades to Pro through the signed webhook', async () => {
      await page.goto('/settings/billing');
      for (const key of ['seats', 'documents', 'ai_queries_monthly']) {
        await expect(page.locator(`[data-test="billing-meter"][data-limit-key="${key}"]`).first()).toBeVisible();
      }
      await app.shot('journey-billing-trial');
      await page.getByTestId('billing-change-plan').click();
      const dialog = page.getByTestId('upgrade-dialog');
      await expect(dialog).toBeVisible();
      await dialog.locator('[data-test="upgrade-plan-option"][data-plan="pro"]').click();
      await dialog.getByTestId('upgrade-submit').click();
      await expect(page).toHaveURL(/\/dev\/fake-checkout\//, { timeout: 20_000 });
      await expect(page.getByTestId('checkout-amount')).toContainText(app.locale === 'ru' ? '₽' : '$');
      await app.shot('journey-checkout');
      await page.getByTestId('fake-checkout-pay').click();
      await expect(page).toHaveURL(/\/settings\/billing/, { timeout: 30_000 });
      await expect(async () => {
        await page.reload();
        await expect(page.getByTestId('billing-plan-card')).toHaveAttribute('data-plan', 'pro', { timeout: 2_000 });
        await expect(page.getByTestId('billing-plan-card')).toHaveAttribute('data-status', 'active', { timeout: 2_000 });
      }).toPass({ timeout: 60_000, intervals: [1_000, 2_000] });
      await expect(page.getByTestId('billing-plan-card')).toContainText(app.locale === 'ru' ? '₽' : '$');
      await app.shot('journey-billing-pro');
      expect(employeeId).toBeGreaterThan(0);
    });
  });
});

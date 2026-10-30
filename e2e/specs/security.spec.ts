import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures.ts';
import { DevTelegram, linksOf } from '../support/bot.ts';
import { appRequest } from '../support/http.ts';
import { inertiaProps } from '../support/inertia.ts';

const DEMO_BOT_TOKEN_PREFIX = '7000000001:TEST';

interface Row {
  id: number;
  number?: number;
  department?: { id: number; name: string } | null;
}

async function demoIds(page: Page): Promise<{ incident: number; task: number; document: number; employee: number; question: number }> {
  const incidents = await inertiaProps<{ incidents: { data: Row[] } }>(page, '/incidents?view=table');
  const tasks = await inertiaProps<{ tasks: { data: Row[] } }>(page, '/tasks?view=table');
  const documents = await inertiaProps<{ documents: { data: Row[] } }>(page, '/knowledge');
  const employees = await inertiaProps<{ employees: { data: Row[] } }>(page, '/employees');
  const questions = await inertiaProps<{ questions: { data: Row[] } }>(page, '/unanswered');
  return {
    incident: incidents.incidents.data[0]!.id,
    task: tasks.tasks.data[0]!.id,
    document: documents.documents.data[0]!.id,
    employee: employees.employees.data[0]!.id,
    question: questions.questions.data[0]!.id,
  };
}

const denied = (status: number) => status === 403 || status === 404;

test.describe('security', () => {
  test('another workspace cannot read or change records by id', { tag: '@flow' }, async ({ page, app, openSession }) => {
    await app.loginAsDemo();
    const ids = await demoIds(page);

    const intruder = await openSession();
    await intruder.app.registerOwner('intruder');
    const other = intruder.page;

    for (const path of [`/incidents/${ids.incident}`, `/knowledge/${ids.document}`, `/employees/${ids.employee}/export`]) {
      const response = await other.request.get(path, { maxRedirects: 0 });
      expect(denied(response.status()), `GET ${path} → ${response.status()}`).toBe(true);
    }
    const writes: Array<[string, 'POST' | 'PATCH' | 'DELETE', unknown]> = [
      [`/incidents/${ids.incident}`, 'PATCH', { status: 'closed', comment: 'intrusion' }],
      [`/incidents/${ids.incident}/comments`, 'POST', { body: 'intrusion' }],
      [`/tasks/${ids.task}`, 'PATCH', { status: 'cancelled' }],
      [`/knowledge/${ids.document}/archive`, 'POST', {}],
      [`/employees/${ids.employee}/link`, 'POST', {}],
      [`/employees/${ids.employee}`, 'DELETE', {}],
      [`/unanswered/${ids.question}/answer`, 'POST', { answer_text: 'intrusion' }],
    ];
    for (const [path, method, data] of writes) {
      const response = await appRequest(other, path, { method, data });
      expect(denied(response.status()), `${method} ${path} → ${response.status()}`).toBe(true);
    }

    const after = await demoIds(page);
    expect(after.incident).toBe(ids.incident);
    await page.goto(`/incidents/${ids.incident}`);
    await expect(page.getByText('intrusion')).toHaveCount(0);
  });

  test('a manager only sees and acts inside their departments', { tag: '@flow' }, async ({ page, app }) => {
    await app.loginAsDemo();
    const all = await inertiaProps<{ incidents: { data: Array<Row & { department: { id: number; name: string } | null }> } }>(page, '/incidents?view=table');
    const owner = await inertiaProps<{ departments: Array<{ id: number; name: string; code?: string }> }>(page, '/departments');
    const residential = owner.departments.find((department) => department.code === 'residential')!;
    const foreign = all.incidents.data.find((incident) => incident.department && incident.department.id !== residential.id)!;
    const own = all.incidents.data.find((incident) => incident.department?.id === residential.id)!;
    expect(foreign && own).toBeTruthy();

    await page.context().clearCookies();
    await app.login('manager@opsbot.test');
    const scoped = await inertiaProps<{ incidents: { data: Row[] } }>(page, '/incidents?view=table');
    expect(scoped.incidents.data.map((incident) => incident.id)).not.toContain(foreign.id);
    expect(scoped.incidents.data.map((incident) => incident.id)).toContain(own.id);

    const forbidden = await page.request.get(`/incidents/${foreign.id}`, { maxRedirects: 0 });
    expect(denied(forbidden.status()), `foreign incident → ${forbidden.status()}`).toBe(true);
    const allowed = await page.request.get(`/incidents/${own.id}`, { maxRedirects: 0 });
    expect(allowed.status()).toBe(200);

    const change = await appRequest(page, `/incidents/${foreign.id}`, { method: 'PATCH', data: { severity: 'low' } });
    expect(denied(change.status()), `PATCH foreign incident → ${change.status()}`).toBe(true);

    const employees = await inertiaProps<{ employees: { data: Array<{ departments: Array<{ id: number }> }> } }>(page, '/employees');
    for (const employee of employees.employees.data) {
      expect(employee.departments.some((department) => department.id === residential.id), 'manager sees an employee outside the department').toBe(true);
    }
    for (const path of ['/bot', '/departments', '/settings/billing', '/audit']) {
      const response = await page.request.get(path, { maxRedirects: 0 });
      expect(denied(response.status()), `manager GET ${path} → ${response.status()}`).toBe(true);
    }
  });

  test('the bot token never reaches the browser and internal endpoints need a signature', { tag: '@flow' }, async ({ page, app, request }) => {
    await app.loginAsDemo();
    for (const path of ['/bot', '/onboarding/setup', '/dashboard', '/employees', '/settings/integrations']) {
      await page.goto(path);
      expect(await page.content(), `${path} leaks the bot token`).not.toContain(DEMO_BOT_TOKEN_PREFIX);
    }
    const csv = await page.request.get('/audit/export');
    expect(await csv.text()).not.toContain(DEMO_BOT_TOKEN_PREFIX);

    for (const base of [app.config.baseURL, app.config.internalURL]) {
      const credentials = await request.get(`${base}/internal/v1/telegram/bots/1/credentials`);
      expect(credentials.status(), `${base} credentials without signature`).toBe(401);
    }
  });

  test('a tampered or foreign signed document link is refused', { tag: '@flow' }, async ({ page, app, request }) => {
    const telegram = new DevTelegram(request, app.config.workerURL, '1');
    const reply = await telegram.say(
      { id: 900000003, firstName: 'Maria', username: 'maria_demo', languageCode: 'en' },
      "What should I do if I scratched a client's table?",
    );
    const link = linksOf(reply).find((href) => href.includes('/s/doc/'));
    expect(link).toBeTruthy();
    expect(new URL(link!).origin, 'signed links must use the public app origin').toBe(new URL(app.config.baseURL).origin);

    const tampered = new URL(link!);
    tampered.searchParams.set('u', '1');
    const response = await page.goto(tampered.toString());
    expect(response!.status()).toBeGreaterThanOrEqual(400);

    const otherDocument = new URL(link!);
    otherDocument.pathname = otherDocument.pathname.replace(/\/s\/doc\/\d+/, '/s/doc/1');
    const swapped = await page.goto(otherDocument.toString());
    expect(swapped!.status()).toBeGreaterThanOrEqual(400);
  });
});

import type { Page } from '@playwright/test';
import { expect, test, type AppHelpers } from '../fixtures.ts';
import { DevTelegram, linksOf, type TelegramPerson } from '../support/bot.ts';
import { inertiaProps } from '../support/inertia.ts';

const PUBLIC_PAGES = ['/', '/pricing', '/register', '/legal/terms', '/legal/privacy', '/legal/dpa', '/legal/consent-ru', '/legal/employee-notice'];

const APP_PAGES = [
  '/dashboard',
  '/knowledge',
  '/incidents',
  '/incidents?view=table',
  '/tasks',
  '/unanswered',
  '/employees',
  '/departments',
  '/bot',
  '/analytics',
  '/assistant',
  '/assistant/history',
  '/onboarding/setup',
  '/settings/assistant',
  '/settings/billing',
  '/settings/members',
  '/audit',
];

const RAW_KEY = /\b(?:ui|marketing|incidents|tasks|questions|documents|bot|billing|roles|routing|flash|errors|employees|validation)\.[a-z_]+(?:\.[a-z0-9_]+)+\b/;
const EMOJI = /[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{2B50}\u{2B55}\u{FE0F}]/u;

const slug = (path: string) => (path === '/' ? 'landing' : path.slice(1).replace(/[/?=&]+/g, '-'));

function watchConsole(page: Page): string[] {
  const errors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(message.text());
  });
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  return errors;
}

async function inspect(app: AppHelpers, page: Page, path: string, errors: string[]): Promise<void> {
  await page.waitForLoadState('networkidle').catch(() => undefined);
  const text = await page.evaluate(() => document.body.innerText);
  expect.soft(text.match(RAW_KEY)?.[0] ?? null, `raw translation key on ${path}`).toBeNull();
  expect.soft(text.match(EMOJI)?.[0] ?? null, `emoji on ${path}`).toBeNull();
  if (app.locale === 'ru') {
    const cyrillic = (text.match(/[А-Яа-яЁё]/g) ?? []).length;
    expect.soft(cyrillic, `${path} is not translated to Russian`).toBeGreaterThan(20);
  }
  await app.expectTheme(app.theme);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect.soft(overflow, `${path} is wider than the viewport`).toBeLessThanOrEqual(1);
  await app.axe(slug(path), { soft: true });
  expect.soft(errors, `console errors on ${path}`).toEqual([]);
}

test.describe('OpsBot pages render, translate and pass axe', () => {
  test('public pages', { tag: '@visual' }, async ({ page, app }) => {
    const errors = watchConsole(page);
    await page.goto('/');
    await app.setLocale(app.locale);
    for (const path of PUBLIC_PAGES) {
      await test.step(path, async () => {
        errors.length = 0;
        await page.goto(path);
        await inspect(app, page, path, errors);
        await app.shot(`page-${slug(path)}`);
      });
    }
  });

  test('signed-in domain pages', { tag: '@visual' }, async ({ page, app, demoLock: _demoLock }) => {
    test.setTimeout(300_000);
    const errors = watchConsole(page);
    await app.loginAsDemo();
    await app.setLocale(app.locale);
    for (const path of APP_PAGES) {
      await test.step(path, async () => {
        errors.length = 0;
        await page.goto(path);
        await expect(page, `${path} redirected`).toHaveURL(new RegExp(`${path.split('?')[0]!.replace(/\//g, '\\/')}(?:[?#]|$)`));
        await inspect(app, page, path, errors);
        await app.shot(`page-${slug(path)}`);
      });
    }

    await test.step('document page', async () => {
      const props = await inertiaProps<{ documents: { data: Array<{ id: number; approval_status: string }> } }>(page, '/knowledge');
      const approved = props.documents.data.find((doc) => doc.approval_status === 'approved');
      expect(approved).toBeTruthy();
      errors.length = 0;
      await page.goto(`/knowledge/${approved!.id}`);
      await inspect(app, page, '/knowledge/{id}', errors);
      await app.shot('page-knowledge-show');
    });

    await test.step('upgrade dialog', async () => {
      await page.goto('/settings/billing');
      await page.getByTestId('billing-change-plan').click();
      await expect(page.getByTestId('upgrade-dialog')).toBeVisible();
      await app.axe('upgrade-dialog', { soft: true });
      await app.shot('page-upgrade-dialog', false);
    });
  });

  test('signed document viewer from a bot answer', { tag: '@visual' }, async ({ page, app, request }) => {
    const errors = watchConsole(page);
    const employee: TelegramPerson =
      app.locale === 'ru'
        ? { id: 900000001, firstName: 'Айгуль', username: 'aigul_demo', languageCode: 'ru' }
        : { id: 900000003, firstName: 'Maria', username: 'maria_demo', languageCode: 'en' };
    const telegram = new DevTelegram(request, app.config.workerURL, '1');
    const question = app.locale === 'ru' ? 'Что делать, если я поцарапала стол у клиента?' : "What should I do if I scratched a client's table?";
    const reply = await telegram.say(employee, question);
    const link = linksOf(reply).find((href) => href.includes('/s/doc/'));
    expect(link, 'answer without a signed link').toBeTruthy();
    await page.goto(link!);
    await expect(page.getByTestId('viewer-excerpt')).toBeVisible();
    await inspect(app, page, '/s/doc', errors);
    await app.shot('page-document-viewer');
  });

  test('app shell: bottom tab bar at 375, live desk at 1440', { tag: '@visual' }, async ({ page, app, demoLock: _demoLock }) => {
    await app.loginAsDemo();
    await app.setLocale(app.locale);
    await page.setViewportSize({ width: 375, height: 812 });
    for (const path of ['/dashboard', '/incidents', '/tasks', '/unanswered', '/employees', '/knowledge', '/analytics', '/settings/billing']) {
      await page.goto(path);
      await expect(page.getByTestId('shell-tabbar')).toBeVisible();
      await app.expectNoHorizontalScroll();
      await app.shot(`shell-375-${slug(path)}`);
    }
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/dashboard');
    await app.expectNoHorizontalScroll();
    await app.shot('shell-768-dashboard');
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard');
    await expect(page.getByTestId('shell-desk')).toBeVisible();
    await expect(page.getByTestId('shell-tabbar')).toBeHidden();
    await app.shot('shell-1440-dashboard');
  });
});

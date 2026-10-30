import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import type { Page } from '@playwright/test';
import { E2E_DIR } from '../support/config.ts';
import { expect, test, type AppHelpers, type AppLocale, type AppTheme } from '../fixtures.ts';

const GALLERY_PROJECT = 'chromium-en-light';
const OUT_DIR = join(E2E_DIR, 'artifacts', 'brand');
const LOCALES: AppLocale[] = ['ru', 'en'];
const THEMES: AppTheme[] = ['light', 'dark'];
const WIDTHS = [1440, 375];

const QUESTION: Record<AppLocale, string> = {
  ru: 'Поцарапала стол у клиента, что делать?',
  en: 'I scratched a client’s table, what should I do?',
};

const GUEST_PAGES = [
  { name: 'landing', path: '/' },
  { name: 'register', path: '/register' },
];

const APP_PAGES = [
  { name: 'dashboard', path: '/dashboard' },
  { name: 'assistant', path: '/assistant' },
  { name: 'knowledge', path: '/knowledge' },
  { name: 'billing', path: '/settings/billing' },
  { name: 'settings', path: '/settings/workspace' },
];

async function applyVariant(page: Page, app: AppHelpers, locale: AppLocale, theme: AppTheme, width: number): Promise<void> {
  await page.setViewportSize({ width, height: width < 768 ? 812 : 900 });
  await page.emulateMedia({ colorScheme: theme, reducedMotion: 'reduce' });
  await app.setLocale(locale);
  await app.setTheme(theme);
  await app.expectTheme(theme);
}

async function capture(page: Page, name: string): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: join(OUT_DIR, `${name}.png`), fullPage: true, animations: 'disabled', caret: 'hide' });
}

test.describe('brand gallery', () => {
  test('captures the key screens in every locale, theme and width', { tag: '@visual' }, async ({ page, app, openSession, demoLock: _demoLock }, testInfo) => {
    test.skip(testInfo.project.name !== GALLERY_PROJECT, 'the gallery is captured once, in its own matrix');
    test.setTimeout(20 * 60_000);
    mkdirSync(OUT_DIR, { recursive: true });

    const guest = await openSession();
    for (const locale of LOCALES) {
      for (const theme of THEMES) {
        for (const width of WIDTHS) {
          await guest.page.goto('/');
          await applyVariant(guest.page, guest.app, locale, theme, width);
          for (const target of GUEST_PAGES) {
            await guest.page.goto(target.path);
            await expect(guest.page.locator('h1').first()).toBeVisible();
            await guest.app.expectNoHorizontalScroll();
            await capture(guest.page, `${target.name}-${locale}-${theme}-${width}`);
          }
        }
      }
    }

    await app.loginAsDemo();

    for (const locale of LOCALES) {
      for (const theme of THEMES) {
        for (const width of WIDTHS) {
          await applyVariant(page, app, locale, theme, width);
          for (const target of APP_PAGES) {
            await page.goto(target.path);
            await expect(page.getByTestId('ops-shell')).toBeVisible();
            if (target.name === 'assistant') {
              await page.locator('textarea[name="message"]').fill(QUESTION[locale]);
              await page.getByTestId('assistant-send').click();
              await expect(page.getByTestId('assistant-answer').last()).toBeVisible({ timeout: 60_000 });
            }
            await app.expectNoHorizontalScroll();
            await capture(page, `${target.name}-${locale}-${theme}-${width}`);
          }
        }
      }
    }
  });
});

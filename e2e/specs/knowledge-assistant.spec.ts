import { join } from 'node:path';
import type { Page } from '@playwright/test';
import { E2E_DIR } from '../support/config.ts';
import { expect, test, uniqueId, type AppLocale } from '../fixtures.ts';

const FIXTURE = join(E2E_DIR, 'data', 'zephyr-travel-policy.md');

const QUESTION: Record<AppLocale, string> = {
  en: 'What is the Zephyr daily travel allowance?',
  ru: 'Какой суточный лимит командировки «Зефир»?',
};

const UNKNOWN_QUESTION: Record<AppLocale, string> = {
  en: 'Which pets are allowed on the orbital research station Kepler-9?',
  ru: 'Каких питомцев разрешено брать на орбитальную станцию «Кеплер-9»?',
};

async function uploadDocument(page: Page, title: string): Promise<number> {
  await page.goto('/knowledge');
  await page.getByTestId('knowledge-upload').click();
  const dialog = page.getByRole('dialog');
  await dialog.getByTestId('knowledge-upload-file').setInputFiles(FIXTURE);
  await dialog.locator('#upload-title').fill(title);
  await dialog.getByTestId('knowledge-upload-submit').click();
  await expect(dialog).toBeHidden({ timeout: 30_000 });
  const row = page.getByTestId('knowledge-row').filter({ hasText: title });
  await expect(row).toBeVisible();
  await expect(async () => {
    await page.reload();
    await expect(row).toHaveAttribute('data-status', 'indexed', { timeout: 2_000 });
  }).toPass({ timeout: 120_000, intervals: [1_000, 2_000, 3_000] });
  await expect(row).toHaveAttribute('data-approval', 'draft');
  return Number(await row.getAttribute('data-document-id'));
}

async function ask(page: Page, question: string) {
  await page.goto('/assistant');
  await page.locator('textarea[name="message"]').fill(question);
  await page.getByTestId('assistant-send').click();
  const answer = page.getByTestId('assistant-answer').last();
  await expect(answer).toBeVisible({ timeout: 60_000 });
  return answer;
}

test.describe('knowledge base & assistant', () => {
  test('a draft is never cited; once approved the assistant answers with a citation', { tag: '@flow' }, async ({
    page,
    app,
    owner: _owner,
  }, testInfo) => {
    const title = `E2E Zephyr policy ${uniqueId(testInfo)}`;
    const id = await uploadDocument(page, title);
    await app.shot('knowledge-draft-indexed');

    const draftAnswer = await ask(page, QUESTION[app.locale]);
    await expect(draftAnswer).toHaveAttribute('data-refused', 'true');
    await expect(page.getByTestId('assistant-citation').filter({ hasText: title })).toHaveCount(0);

    await page.goto(`/knowledge/${id}`);
    await page.getByTestId('knowledge-approve').click();
    await page.getByTestId('knowledge-approve-confirm').click();
    await expect(async () => {
      await page.goto('/knowledge');
      await expect(page.getByTestId('knowledge-row').filter({ hasText: title })).toHaveAttribute('data-approval', 'approved', { timeout: 2_000 });
    }).toPass({ timeout: 30_000 });

    await expect(async () => {
      const answer = await ask(page, QUESTION[app.locale]);
      await expect(answer).toHaveAttribute('data-refused', 'false', { timeout: 2_000 });
    }).toPass({ timeout: 60_000, intervals: [2_000, 3_000] });
    const answer = page.getByTestId('assistant-answer').last();
    await expect(answer).toContainText('4750');
    await expect(page.getByTestId('assistant-citation').filter({ hasText: title }).first()).toBeVisible();
    await app.shot('assistant-answer');

    await page.goto('/assistant/history');
    await expect(page.getByText(QUESTION[app.locale]).first()).toBeVisible();
  });

  test('the assistant refuses questions that are not covered by approved documents', { tag: '@flow' }, async ({
    page,
    app,
    owner: _owner,
  }) => {
    const answer = await ask(page, UNKNOWN_QUESTION[app.locale]);
    await expect(answer).toHaveAttribute('data-refused', 'true');
    await expect(page.getByTestId('assistant-refusal')).toBeVisible();
    await expect(page.getByTestId('assistant-citation')).toHaveCount(0);
  });
});

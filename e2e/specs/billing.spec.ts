import type { Page } from '@playwright/test';
import { expect, test } from '../fixtures.ts';

async function openCheckout(page: Page, plan: 'starter' | 'pro' | 'business'): Promise<void> {
  await page.goto('/settings/billing');
  await page.getByTestId('billing-change-plan').click();
  const dialog = page.getByTestId('upgrade-dialog');
  await expect(dialog).toBeVisible();
  await dialog.locator(`[data-test="upgrade-plan-option"][data-plan="${plan}"]`).click();
  await dialog.getByTestId('upgrade-submit').click();
  await expect(page).toHaveURL(/\/dev\/fake-checkout\//, { timeout: 20_000 });
}

test.describe('billing', () => {
  test('a new workspace starts on the Pro trial and fake checkout activates Starter through the webhook path', { tag: '@flow' }, async ({
    page,
    app,
    owner: _owner,
  }) => {
    await page.goto('/settings/billing');
    const card = page.getByTestId('billing-plan-card');
    await expect(card).toHaveAttribute('data-plan', 'pro');
    await expect(card).toHaveAttribute('data-status', 'trialing');
    await expect(page.locator('[data-test="billing-state-banner"][data-state="trialing"]')).toBeVisible();
    await app.shot('billing-trial');

    await openCheckout(page, 'starter');
    await app.axe('fake-checkout');
    await app.shot('fake-checkout');
    await page.getByTestId('fake-checkout-pay').click();
    await expect(page).toHaveURL(/\/settings\/billing/, { timeout: 20_000 });

    await expect(async () => {
      await page.reload();
      await expect(card).toHaveAttribute('data-status', 'active', { timeout: 2_000 });
    }).toPass({ timeout: 60_000, intervals: [1_000, 2_000] });
    await expect(card).toHaveAttribute('data-plan', 'starter');
    await app.shot('billing-active');
  });

  test('cancelling the fake checkout keeps the trial', { tag: '@flow' }, async ({ page, owner: _owner }) => {
    await openCheckout(page, 'business');
    await page.getByTestId('fake-checkout-cancel').click();
    await expect(page).toHaveURL(/\/settings\/billing/, { timeout: 20_000 });
    await page.reload();
    const card = page.getByTestId('billing-plan-card');
    await expect(card).toHaveAttribute('data-status', 'trialing');
    await expect(card).toHaveAttribute('data-plan', 'pro');
  });
});

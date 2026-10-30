import type { Page } from '@playwright/test';

export interface InertiaPage<P = Record<string, unknown>> {
  component: string;
  url: string;
  props: P;
}

export async function inertiaPage<P = Record<string, unknown>>(page: Page): Promise<InertiaPage<P>> {
  const raw = await page.evaluate(() => {
    const state = window.history.state as { page?: unknown } | null;
    if (state?.page && typeof state.page === 'object') return JSON.stringify(state.page);
    const script = document.querySelector('script[data-page]');
    if (script?.textContent) return script.textContent;
    const root = document.getElementById('app');
    return root?.dataset.page ?? null;
  });
  if (!raw) throw new Error(`no Inertia page payload on ${page.url()}`);
  return JSON.parse(raw) as InertiaPage<P>;
}

export async function inertiaProps<P = Record<string, unknown>>(page: Page, path: string): Promise<P> {
  await page.goto(path);
  return (await inertiaPage<P>(page)).props;
}

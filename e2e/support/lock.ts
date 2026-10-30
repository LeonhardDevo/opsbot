import { mkdirSync, rmSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { E2E_DIR } from './config.ts';

const LOCK_DIR = join(E2E_DIR, 'test-results', '.locks');

export interface LockOptions {
  timeoutMs?: number;
  staleMs?: number;
  pollMs?: number;
}

function isStale(path: string, staleMs: number): boolean {
  try {
    return Date.now() - statSync(path).mtimeMs > staleMs;
  } catch {
    return false;
  }
}

export async function acquireLock(name: string, options: LockOptions = {}): Promise<() => void> {
  if (!/^[a-z0-9-]+$/.test(name)) throw new Error(`invalid lock name "${name}"`);
  const { timeoutMs = 15 * 60_000, staleMs = 20 * 60_000, pollMs = 250 } = options;
  mkdirSync(LOCK_DIR, { recursive: true });
  const path = join(LOCK_DIR, `${name}.lock`);
  const deadline = Date.now() + timeoutMs;
  while (true) {
    try {
      mkdirSync(path);
      return () => rmSync(path, { recursive: true, force: true });
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'EEXIST') throw error;
      if (isStale(path, staleMs)) {
        rmSync(path, { recursive: true, force: true });
        continue;
      }
      if (Date.now() > deadline) throw new Error(`timed out waiting for the "${name}" lock`);
      await new Promise((resolve) => setTimeout(resolve, pollMs));
    }
  }
}

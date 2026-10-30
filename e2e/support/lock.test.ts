import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { acquireLock } from './lock.ts';

describe('acquireLock', () => {
  it('hands the lock to one holder at a time', async () => {
    const order: string[] = [];
    const release = await acquireLock('unit-test-lock');
    const second = acquireLock('unit-test-lock', { pollMs: 10 }).then((releaseSecond) => {
      order.push('second');
      releaseSecond();
    });
    await new Promise((resolve) => setTimeout(resolve, 50));
    order.push('first');
    release();
    await second;
    assert.deepEqual(order, ['first', 'second']);
  });

  it('gives up after the timeout and rejects invalid names', async () => {
    const release = await acquireLock('unit-test-timeout');
    await assert.rejects(() => acquireLock('unit-test-timeout', { timeoutMs: 30, pollMs: 10 }), /timed out/);
    release();
    await assert.rejects(() => acquireLock('../escape'), /invalid lock name/);
  });
});

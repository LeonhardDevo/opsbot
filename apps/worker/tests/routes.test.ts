import { describe, expect, it } from 'vitest';
import type { JobDeps } from '../src/jobs/deps.js';
import { createHandlers } from '../src/jobs/index.js';
import { DEFAULT_JOB_OPTIONS, extractJobId, indexJobId, telegramUpdateJobId } from '../src/queues/defaults.js';
import { ALL_QUEUES, JOB_NAMES, QUEUE_SETTINGS, QUEUES } from '../src/queues/names.js';
import { TOPIC_ROUTES, resolveQueue, topicsForQueue } from '../src/queues/routes.js';

describe('topic → queue routing', () => {
  it('routes every foundation topic to its queue', () => {
    expect(resolveQueue('email.send')).toBe('emails');
    expect(resolveQueue('telegram.notify')).toBe('telegram');
    expect(resolveQueue('document.uploaded')).toBe('documents');
    expect(resolveQueue('document.deleted')).toBe('documents');
    expect(resolveQueue('workspace.reembed')).toBe('documents');
  });

  it('returns null for unknown topics and prototype keys', () => {
    expect(resolveQueue('invoice.paid')).toBeNull();
    expect(resolveQueue('toString')).toBeNull();
    expect(resolveQueue('__proto__')).toBeNull();
  });

  it('lists topics per queue', () => {
    expect(topicsForQueue(QUEUES.documents).sort()).toEqual([
      'document.deleted',
      'document.metadata_changed',
      'document.uploaded',
      'workspace.purge',
      'workspace.reembed',
    ]);
    expect(topicsForQueue(QUEUES.privacy).sort()).toEqual(['privacy.export.requested', 'privacy.purge.due', 'user.purge']);
    expect(topicsForQueue(QUEUES.telegramAdmin).sort()).toEqual(['telegram.bot.disconnect', 'telegram.context.invalidate']);
    expect(topicsForQueue(QUEUES.telegram)).toContain('opsbot.question.escalated');
    expect(topicsForQueue(QUEUES.telegram)).toHaveLength(11);
  });

  it('has a handler for every routed topic and for direct queues', () => {
    const handlers = createHandlers({ settings: { extractConcurrency: 2 } } as JobDeps);
    for (const [topic, queue] of Object.entries(TOPIC_ROUTES)) {
      expect(handlers[queue][topic], `handler for ${topic}`).toBeTypeOf('function');
    }
    expect(handlers[QUEUES.stripeEvents][JOB_NAMES.stripeEvent]).toBeTypeOf('function');
    expect(handlers[QUEUES.telegramUpdates][JOB_NAMES.telegramUpdate]).toBeTypeOf('function');
    for (const name of [JOB_NAMES.documentExtract, JOB_NAMES.documentIndex]) expect(handlers[QUEUES.documents][name]).toBeTypeOf('function');
    for (const name of [JOB_NAMES.remindersTick, JOB_NAMES.documentsTick, JOB_NAMES.limitsTick, JOB_NAMES.botsReconcile]) {
      expect(handlers[QUEUES.opsbotSchedules][name]).toBeTypeOf('function');
    }
  });

  it('defines settings for every queue and the platform job defaults', () => {
    for (const queue of ALL_QUEUES) expect(QUEUE_SETTINGS[queue].concurrency).toBeGreaterThan(0);
    expect(DEFAULT_JOB_OPTIONS).toMatchObject({
      attempts: 5,
      backoff: { type: 'exponential', delay: 2000 },
      removeOnComplete: 1000,
      removeOnFail: 5000,
    });
  });

  it('derives per-bot job ids from Telegram update ids', () => {
    expect(telegramUpdateJobId('platform', 42)).toBe('tg-update-platform-42');
    expect(telegramUpdateJobId('7', 42)).toBe('tg-update-7-42');
    expect(telegramUpdateJobId('7', 42)).not.toBe(telegramUpdateJobId('8', 42));
    expect(extractJobId(5, 2)).toBe('extract:5:2');
    expect(indexJobId(5, 2)).toBe('index:5:2');
  });

  it('has no queue-wide limiter on the telegram queue', () => {
    expect(QUEUE_SETTINGS.telegram).toEqual({ concurrency: 10 });
    expect(QUEUE_SETTINGS['opsbot-schedules'].concurrency).toBe(1);
  });
});

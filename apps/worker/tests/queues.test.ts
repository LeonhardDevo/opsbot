import { setTimeout as sleep } from 'node:timers/promises';
import { QueueEvents, UnrecoverableError, type Job, type Worker } from 'bullmq';
import type { FastifyInstance } from 'fastify';
import { Redis } from 'ioredis';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { RedisActionStore, RedisCache, RedisRateLimiter } from '../src/bot/stores.js';
import { MemoryWebhookEventStore } from '../src/db/webhookEvents.js';
import { buildServer } from '../src/http/server.js';
import { createSilentLogger } from '../src/logger.js';
import { Metrics } from '../src/metrics.js';
import { extractJobId, telegramUpdateJobId } from '../src/queues/defaults.js';
import { registerSchedules } from '../src/jobs/schedules.js';
import { RedisRateBuckets, telegramBuckets } from '../src/telegram/buckets.js';
import { RedisLockStore } from '../src/telegram/leaderLock.js';
import { BotRegistry } from '../src/bot/botRegistry.js';
import { createTestBot } from './helpers/bot.js';
import { companyCredentials } from './helpers/fakes.js';
import { QueueRegistry } from '../src/queues/enqueuer.js';
import { redisOptionsFromUrl } from '../src/queues/redis.js';
import { closeWorkers, startWorkers } from '../src/queues/workers.js';
import { PRODUCT } from './helpers/bot.js';
import { redisAvailable, announceSkip, testRedisUrl, uniqueSuffix } from './helpers/infra.js';

const redisUrl = await redisAvailable();
const skipSuffix = redisUrl ? '' : announceSkip('Redis (BullMQ / store tests)', testRedisUrl());

const prefix = `test-${uniqueSuffix()}`;

describe.skipIf(!redisUrl)(`BullMQ + Redis (real Redis)${skipSuffix}`, () => {
  let registry: QueueRegistry;
  let redis: Redis;

  beforeAll(() => {
    registry = new QueueRegistry(redisOptionsFromUrl(redisUrl!), prefix);
    redis = new Redis(redisOptionsFromUrl(redisUrl!));
  });

  afterAll(async () => {
    for (const queue of registry.queues.values()) await queue.obliterate({ force: true }).catch(() => undefined);
    await registry.close();
    const keys = await redis.keys(`${prefix}*`);
    if (keys.length > 0) await redis.del(...keys);
    await redis.quit();
  });

  it('dedupes jobs by id (idempotent producers)', async () => {
    const first = await registry.add('emails', 'email.send', { a: 1 }, 'outbox-id-1');
    const second = await registry.add('emails', 'email.send', { a: 2 }, 'outbox-id-1');
    expect(first).toEqual({ jobId: 'outbox-id-1', created: true });
    expect(second).toEqual({ jobId: 'outbox-id-1', created: false });
    const job = await registry.get('emails').getJob('outbox-id-1');
    expect(job?.data).toEqual({ a: 1 });
    expect(job?.opts).toMatchObject({ attempts: 5, backoff: { type: 'exponential', delay: 2000 } });
  });

  it('accepts derived Telegram job ids', async () => {
    const result = await registry.add('telegram-updates', 'telegram.update', { bot_key: '7', update: { update_id: 12 } }, telegramUpdateJobId('7', 12));
    expect(result.created).toBe(true);
    const other = await registry.add('telegram-updates', 'telegram.update', { bot_key: '8', update: { update_id: 12 } }, telegramUpdateJobId('8', 12));
    expect(other.created).toBe(true);
    const extract = await registry.add('documents', 'document.extract', { document_id: 1 }, extractJobId(1, 2), { attempts: 3 });
    expect(extract.created).toBe(true);
    expect((await registry.get('documents').getJob(extractJobId(1, 2)))?.opts.attempts).toBe(3);
  });

  it('processes jobs through the instrumented processor', async () => {
    const metrics = new Metrics({ defaultMetrics: false });
    const queue = registry.get('documents');
    const events = new QueueEvents('documents', { connection: redisOptionsFromUrl(redisUrl!), prefix });
    await events.waitUntilReady();
    const workers: Worker[] = startWorkers(
      {
        documents: {
          'workspace.reembed': async (data) => ({ echoed: data }),
          'document.deleted': async () => {
            throw new UnrecoverableError('bad payload');
          },
        },
      },
      { connection: redisOptionsFromUrl(redisUrl!), prefix, logger: createSilentLogger(), metrics },
    );
    try {
      const ok = await queue.add('workspace.reembed', { workspace_id: 3 }, { jobId: 'ok-1' });
      expect(await ok.waitUntilFinished(events, 10_000)).toEqual({ echoed: { workspace_id: 3 } });

      const bad = await queue.add('document.deleted', { document_id: 1 }, { jobId: 'bad-1' });
      await expect(bad.waitUntilFinished(events, 10_000)).rejects.toThrow('bad payload');
      const failed = (await queue.getJob('bad-1')) as Job;
      expect(failed.attemptsMade).toBe(1);

      const rendered = await metrics.render();
      expect(rendered.body).toContain('worker_jobs_total{queue="documents",name="workspace.reembed",status="completed"} 1');
      expect(rendered.body).toContain('worker_jobs_total{queue="documents",name="document.deleted",status="failed"} 1');
    } finally {
      await closeWorkers(workers);
      await events.close();
    }
  });

  it('stores actions with TTL, rate-limits per window and caches values', async () => {
    const keyPrefix = `${prefix}:kv:`;
    const actions = new RedisActionStore(redis, keyPrefix);
    const id = await actions.put(
      { type: 'ask', payload: { question: 'q' }, telegram_user_id: 1, chat_id: 1, workspace_id: 1, user_id: 1, locale: 'en', created_at: 1 },
      30,
    );
    expect(id).toMatch(/^[A-Za-z0-9]{12}$/);
    expect(await actions.get(id)).toMatchObject({ type: 'ask', payload: { question: 'q' } });
    expect(await redis.ttl(`${keyPrefix}action:${id}`)).toBeGreaterThan(0);
    expect(await actions.get('missing')).toBeNull();

    const limiter = new RedisRateLimiter(redis, keyPrefix);
    const hits = [await limiter.hit('chat:1', 60), await limiter.hit('chat:1', 60), await limiter.hit('chat:2', 60)];
    expect(hits).toEqual([1, 2, 1]);

    const cache = new RedisCache(redis, keyPrefix);
    await cache.set('k', 'v', 30);
    expect(await cache.get('k')).toBe('v');
    await cache.del('k');
    expect(await cache.get('k')).toBeNull();
  });

  it('registers the repeatable schedules once', async () => {
    const queue = registry.get('opsbot-schedules');
    await registerSchedules(queue);
    await registerSchedules(queue);
    const schedulers = await queue.getJobSchedulers();
    expect(schedulers.map((scheduler) => scheduler.key).sort()).toEqual([
      'opsbot.bots.reconcile',
      'opsbot.documents.tick',
      'opsbot.limits.tick',
      'opsbot.reminders.tick',
    ]);
  });

  it('meters telegram sends with per-bot and per-chat token buckets', async () => {
    let now = 1_000_000;
    const buckets = new RedisRateBuckets(redis, `${prefix}:rate:`, () => now);
    const rates = { botPerS: 25, chatPerS: 1 };
    expect(await buckets.take(telegramBuckets('7', 1, rates))).toBe(0);
    const wait = await buckets.take(telegramBuckets('7', 1, rates));
    expect(wait).toBeGreaterThan(0);
    expect(wait).toBeLessThanOrEqual(1000);
    expect(await buckets.take(telegramBuckets('7', 2, rates))).toBe(0);
    expect(await buckets.take(telegramBuckets('8', 1, rates))).toBe(0);
    now += 1000;
    expect(await buckets.take(telegramBuckets('7', 1, rates))).toBe(0);
    for (let chat = 10; chat < 35; chat++) await buckets.take(telegramBuckets('9', chat, rates));
    expect(await buckets.take(telegramBuckets('9', 99, rates))).toBeGreaterThan(0);
  });

  it('elects one polling leader per bot and lets only the holder renew', async () => {
    const locks = new RedisLockStore(redis, `${prefix}:lock:`);
    expect(await locks.acquire('poll-lock:7', 'replica-a', 30_000)).toBe(true);
    expect(await locks.acquire('poll-lock:7', 'replica-b', 30_000)).toBe(false);
    expect(await locks.renew('poll-lock:7', 'replica-b', 30_000)).toBe(false);
    expect(await locks.renew('poll-lock:7', 'replica-a', 30_000)).toBe(true);
    await locks.release('poll-lock:7', 'replica-b');
    expect(await locks.acquire('poll-lock:7', 'replica-b', 30_000)).toBe(false);
    await locks.release('poll-lock:7', 'replica-a');
    expect(await locks.acquire('poll-lock:7', 'replica-b', 30_000)).toBe(true);
  });

  it('evicts registry entries on every replica through pub/sub', async () => {
    const replicas = [createTestBot(), createTestBot()];
    const subscribers = replicas.map(() => new Redis(redisOptionsFromUrl(redisUrl!)));
    const channel = `${prefix}:bot-registry`;
    try {
      for (const [index, replica] of replicas.entries()) {
        replica.credentials.set(7, companyCredentials());
        await replica.registry.get('7');
        await replica.registry.subscribe(subscribers[index]!, channel);
      }
      await BotRegistry.publishEvict(redis, channel, '7');
      for (let attempt = 0; attempt < 50 && replicas.some((replica) => replica.registry.peek('7')); attempt++) await sleep(20);
      expect(replicas.map((replica) => replica.registry.peek('7'))).toEqual([undefined, undefined]);
    } finally {
      for (const subscriber of subscribers) subscriber.disconnect();
    }
  });

  describe('queue dashboard', () => {
    let server: FastifyInstance;
    const auth = `Basic ${Buffer.from('admin:s3cret').toString('base64')}`;

    beforeAll(async () => {
      server = await buildServer({
        logger: createSilentLogger(),
        metrics: new Metrics({ defaultMetrics: false }),
        enqueuer: registry,
        webhookEvents: new MemoryWebhookEventStore(),
        product: PRODUCT,
        stripeWebhookSecret: 'whsec_test_local',
        telegramWebhook: { enabled: false, bots: { get: async () => null } },
        devRegistry: null,
        internalBots: null,
        dashboard: { queues: [...registry.queues.values()], user: 'admin', password: 's3cret' },
        readiness: {},
      });
    });

    afterAll(async () => {
      await server.close();
    });

    it('requires basic auth', async () => {
      const anonymous = await server.inject({ method: 'GET', url: '/admin/queues' });
      expect(anonymous.statusCode).toBe(401);
      expect(anonymous.headers['www-authenticate']).toContain('Basic');
      const wrong = await server.inject({ method: 'GET', url: '/admin/queues', headers: { authorization: `Basic ${Buffer.from('admin:x').toString('base64')}` } });
      expect(wrong.statusCode).toBe(401);
    });

    it('serves the board without any external asset and with a strict CSP', async () => {
      const page = await server.inject({ method: 'GET', url: '/admin/queues', headers: { authorization: auth } });
      expect(page.statusCode).toBe(200);
      expect(page.body).toContain('<div id="root">');
      expect(page.body).not.toMatch(/https?:\/\/(?!localhost)/);
      expect(page.headers['content-security-policy']).toContain("default-src 'self'");

      const api = await server.inject({ method: 'GET', url: '/admin/queues/api/queues', headers: { authorization: auth } });
      expect(api.statusCode).toBe(200);
      const names = (api.json<{ queues: Array<{ name: string }> }>()).queues.map((queue) => queue.name).sort();
      expect(names).toEqual(['billing', 'documents', 'emails', 'opsbot-schedules', 'privacy', 'stripe-events', 'telegram', 'telegram-admin', 'telegram-updates']);
      await sleep(10);
    });
  });
});

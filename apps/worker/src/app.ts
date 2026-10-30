import { setTimeout as sleep } from 'node:timers/promises';
import type { Worker } from 'bullmq';
import type { FastifyInstance } from 'fastify';
import type { Redis } from 'ioredis';
import type pg from 'pg';
import { AccountResolver } from './bot/accounts.js';
import { ALLOWED_UPDATES, BotRegistry, PLATFORM_BOT_KEY, REGISTRY_CHANNEL } from './bot/botRegistry.js';
import { createBotComposer } from './bot/createBot.js';
import { createModuleRegistry } from './bot/moduleRegistry.js';
import { defaultModules } from './bot/modules/index.js';
import { RedisActionStore, RedisCache, RedisRateLimiter } from './bot/stores.js';
import type { BotHealth, BotServices } from './bot/types.js';
import { AiClient } from './clients/ai.js';
import { WebClient } from './clients/web.js';
import type { AppConfig } from './config.js';
import { migrate } from './db/migrate.js';
import { createPool } from './db/pool.js';
import { PgChatPreferences, PgGroupNotices } from './db/telegramChats.js';
import { PgWebhookEventStore } from './db/webhookEvents.js';
import { buildServer } from './http/server.js';
import { Translator } from './i18n/index.js';
import { createHandlers, type JobDeps } from './jobs/index.js';
import { registerSchedules } from './jobs/schedules.js';
import { errorFields, type Logger } from './logger.js';
import { SmtpMailer } from './mail/mailer.js';
import { Metrics } from './metrics.js';
import { OutboxRelay } from './outbox/relay.js';
import { QueueRegistry } from './queues/enqueuer.js';
import { QUEUES } from './queues/names.js';
import { scheduleBilling, schedulePrivacyPurge } from './queues/scheduler.js';
import { createRedis, redisOptionsFromUrl } from './queues/redis.js';
import { closeWorkers, startWorkers } from './queues/workers.js';
import { RedisRateBuckets } from './telegram/buckets.js';
import { RedisLockStore } from './telegram/leaderLock.js';
import { PollingSupervisor } from './telegram/polling.js';
import { TelegramSender } from './telegram/sender.js';
import { BotVerifier, registerCommands } from './telegram/verify.js';

const NOTIFICATION_ACTION_TTL_S = 7 * 24 * 3600;
const EXTRACT_TTL_S = 24 * 3600;
const SEEN_REPORT_INTERVAL_MS = 10 * 60_000;
const FLOW_TTL_S = 900;
const REPLY_TTL_S = 1800;
const MAX_FILE_BYTES = 110 * 1024 * 1024;

export interface WorkerApp {
  readonly config: AppConfig;
  readonly server: FastifyInstance;
  readonly registry: BotRegistry;
  readonly metrics: Metrics;
  start(): Promise<void>;
  stop(): Promise<void>;
}

function createHealth(web: WebClient, registry: () => BotRegistry, logger: Logger): BotHealth {
  const lastSeen = new Map<string, number>();
  return {
    async seen(botKey, requestId) {
      if (botKey === PLATFORM_BOT_KEY) return;
      const now = Date.now();
      if (now - (lastSeen.get(botKey) ?? 0) < SEEN_REPORT_INTERVAL_MS) return;
      lastSeen.set(botKey, now);
      await web.reportBotStatus(Number(botKey), { status: 'active', seen_at: new Date(now).toISOString() }, requestId);
    },
    async unauthorized(botKey, requestId) {
      registry().evict(botKey);
      if (botKey === PLATFORM_BOT_KEY) return;
      await web.reportBotStatus(Number(botKey), { status: 'revoked', error_code: 'revoked' }, requestId).catch((error: unknown) => {
        logger.warn(errorFields(error), 'bot revoked report failed');
      });
    },
  };
}

export async function createApp(config: AppConfig, logger: Logger): Promise<WorkerApp> {
  const metrics = new Metrics();
  const pool: pg.Pool = createPool(config.database.url, config.database.poolMax, logger);
  const redis: Redis = createRedis(config.redis.url, `${config.product.slug}-worker`);
  const subscriber: Redis = createRedis(config.redis.url, `${config.product.slug}-worker-sub`);
  for (const connection of [redis, subscriber]) {
    connection.on('error', (error) => logger.warn(errorFields(error), 'redis connection error'));
  }
  const connection = redisOptionsFromUrl(config.redis.url);
  const queues = new QueueRegistry(connection, config.redis.queuePrefix);
  metrics.setQueueDepthSource(async () =>
    Promise.all(
      [...queues.queues.values()].map(async (queue) => ({
        queue: queue.name,
        counts: await queue.getJobCounts('waiting', 'active', 'delayed', 'failed', 'completed', 'prioritized', 'paused'),
      })),
    ),
  );

  const web = new WebClient({ service: 'web', baseUrl: config.internal.webUrl, secret: config.internal.keys.web, timeoutMs: config.timeouts.webMs });
  const ai = new AiClient(
    { service: 'ai', baseUrl: config.internal.aiUrl, secret: config.internal.keys.ai, timeoutMs: config.timeouts.webMs },
    { chatMs: config.timeouts.aiChatMs, ingestMs: config.timeouts.aiIngestMs, extractMs: config.timeouts.extractMs },
  );

  const translator = new Translator(config.product.name);
  const modules = createModuleRegistry(defaultModules(), translator);
  const cache = new RedisCache(redis, config.redis.keyPrefix);
  const actions = new RedisActionStore(redis, config.redis.keyPrefix);
  const preferences = new PgChatPreferences(pool);
  const groupNotices = new PgGroupNotices(pool);
  const accounts = new AccountResolver(web, cache, config.telegram.accountCacheTtlS);
  let registryRef: BotRegistry | null = null;
  const health = createHealth(web, () => registryRef as BotRegistry, logger.child({ component: 'telegram' }));
  const services: BotServices = {
    settings: {
      productName: config.product.name,
      botUsername: config.telegram.botUsername,
      webPublicUrl: config.internal.webPublicUrl,
      rateLimit: config.telegram.rateLimit,
      rateWindowS: config.telegram.rateWindowS,
      actionTtlS: config.telegram.actionTtlS,
      flowTtlS: FLOW_TTL_S,
      replyTtlS: REPLY_TTL_S,
    },
    translator,
    web,
    ai,
    actions,
    state: cache,
    rateLimiter: new RedisRateLimiter(redis, config.redis.keyPrefix),
    preferences,
    groupNotices,
    accounts,
    logger: logger.child({ component: 'bot' }),
    metrics,
    modules,
    health,
  };
  const registry = new BotRegistry({
    composer: createBotComposer(services),
    credentials: web,
    platform: {
      token: config.telegram.token,
      username: config.telegram.botUsername,
      productName: config.product.name,
      webhookSecret: config.telegram.webhookSecret,
    },
    driver: config.telegram.driver,
    apiRoot: config.telegram.apiRoot,
    logger: logger.child({ component: 'bot-registry' }),
    onChange: (size) => metrics.registryBots.set(size),
  });
  registryRef = registry;
  const channel = `${config.redis.keyPrefix}${REGISTRY_CHANNEL}`;
  const publishEvict = (botKey: string): Promise<void> => BotRegistry.publishEvict(redis, channel, botKey);

  const polling =
    config.telegram.mode === 'polling'
      ? new PollingSupervisor({
          bots: registry,
          activeBots: async () =>
            (await web.activeBots()).filter((bot) => bot.status === 'active' || bot.status === 'connecting').map((bot) => bot.bot_key),
          enqueuer: queues,
          locks: new RedisLockStore(redis, config.redis.keyPrefix),
          replicaId: config.replicaId,
          logger: logger.child({ component: 'polling' }),
        })
      : null;

  const verifier = new BotVerifier({
    registry,
    web,
    mode: config.telegram.mode,
    publicUrl: config.telegram.publicUrl,
    commands: modules.commands,
    translator,
    logger: logger.child({ component: 'bot-verify' }),
    publishEvict,
    ...(polling ? { startPolling: (botKey: string) => polling.ensure(botKey) } : {}),
  });

  const webhookEvents = new PgWebhookEventStore(pool);
  const mailer = new SmtpMailer(config.smtp);
  const sender = new TelegramSender({
    registry,
    buckets: new RedisRateBuckets(redis, config.redis.keyPrefix),
    web,
    rates: { botPerS: config.telegram.botRatePerS, chatPerS: config.telegram.chatRatePerS },
    logger: logger.child({ component: 'telegram-sender' }),
    metrics,
  });
  const jobDeps: JobDeps = {
    web,
    ai,
    mailer,
    sender,
    sentMarkers: cache,
    extracts: cache,
    webhookEvents,
    bots: registry,
    lifecycle: {
      stopPolling: async (botKey) => polling?.stopBot(botKey),
      publishEvict,
    },
    modules,
    telegramChats: preferences,
    translator,
    actions,
    accounts,
    preferences,
    groupNotices,
    enqueuer: queues,
    settings: {
      productSlug: config.product.slug,
      mailFrom: config.smtp.from,
      notificationActionTtlS: NOTIFICATION_ACTION_TTL_S,
      maxFileBytes: MAX_FILE_BYTES,
      extractTtlS: EXTRACT_TTL_S,
      extractConcurrency: 2,
      reembedBatchDocuments: 20,
    },
  };
  const handlers = createHandlers(jobDeps);

  const relay = new OutboxRelay({
    pool,
    enqueuer: queues,
    logger: logger.child({ component: 'outbox' }),
    metrics,
    table: config.outbox.table,
    channel: config.outbox.channel,
    pollMs: config.outbox.pollMs,
    batchSize: config.outbox.batchSize,
    listenConnectionString: config.database.url,
  });

  const server = await buildServer({
    logger,
    metrics,
    enqueuer: queues,
    webhookEvents,
    product: config.product,
    stripeWebhookSecret: config.stripe.webhookSecret,
    telegramWebhook: { enabled: config.telegram.mode === 'webhook', bots: registry },
    yookassaWebhook: { allowedIps: config.webhooks.yookassaAllowedIps, trustedProxyHops: config.webhooks.trustedProxyHops },
    devRegistry: config.devRoutes ? registry : null,
    internalBots: { secret: config.internal.keys.web, verifier },
    dashboard: config.dashboard ? { queues: [...queues.queues.values()], ...config.dashboard } : null,
    readiness: {
      postgres: async () => {
        await pool.query('SELECT 1');
      },
      redis: async () => {
        await redis.ping();
      },
    },
  });
  if (!config.dashboard) logger.warn('QUEUE_DASHBOARD_USER/PASSWORD not set; /admin/queues is disabled');

  let workers: Worker[] = [];

  async function startPlatformBot(): Promise<void> {
    const entry = await registry.get(PLATFORM_BOT_KEY);
    if (!entry || entry.transport === 'fake') {
      logger.info({ mode: config.telegram.mode }, 'platform bot runs on the fake transport; outbound calls are captured');
      return;
    }
    try {
      entry.info = await entry.bot.api.getMe();
    } catch (error) {
      logger.warn(errorFields(error), 'platform bot getMe failed; using static bot info');
    }
    await registerCommands(entry.bot.api, modules.commands, translator).catch((error: unknown) =>
      logger.warn(errorFields(error), 'platform setMyCommands failed'),
    );
    const url = config.telegram.webhookUrl ?? (config.telegram.publicUrl ? `${config.telegram.publicUrl}/webhooks/telegram/${PLATFORM_BOT_KEY}` : null);
    if (config.telegram.mode === 'webhook' && url && config.telegram.webhookSecret) {
      await entry.bot.api
        .setWebhook(url, { secret_token: config.telegram.webhookSecret, allowed_updates: [...ALLOWED_UPDATES] })
        .catch((error: unknown) => logger.error(errorFields(error), 'platform setWebhook failed'));
    }
  }

  return {
    config,
    server,
    registry,
    metrics,
    async start() {
      await retry(() => migrate(pool, logger), logger, 'postgres migrations');
      if (config.workersEnabled) {
        workers = startWorkers(handlers, { connection, prefix: config.redis.queuePrefix, logger, metrics });
        await registerSchedules(queues.get(QUEUES.opsbotSchedules));
        await schedulePrivacyPurge(queues.get(QUEUES.privacy), config.privacyPurgeCron, logger).catch((error: unknown) =>
          logger.error(errorFields(error), 'privacy purge scheduler registration failed'),
        );
        await scheduleBilling(queues.get(QUEUES.billing), config.billingRenewalsCron, logger).catch((error: unknown) =>
          logger.error(errorFields(error), 'billing scheduler registration failed'),
        );
      }
      if (config.outbox.enabled) await relay.start();
      await registry.subscribe(subscriber, channel);
      registry.startSweeper();
      if (config.telegram.mode !== 'disabled') await startPlatformBot();
      if (polling) await polling.start();
      await server.listen({ host: config.host, port: config.port });
      logger.info(
        {
          port: config.port,
          telegram: config.telegram.mode,
          driver: config.telegram.driver,
          devRoutes: config.devRoutes,
          dashboard: Boolean(config.dashboard),
          product: config.product.slug,
        },
        'worker ready',
      );
    },
    async stop() {
      await server.close().catch(() => undefined);
      await polling?.stop();
      registry.stopSweeper();
      await relay.stop();
      await closeWorkers(workers);
      await queues.close();
      mailer.close();
      await subscriber.quit().catch(() => undefined);
      await redis.quit().catch(() => undefined);
      await pool.end().catch(() => undefined);
    },
  };
}

async function retry<T>(fn: () => Promise<T>, logger: Logger, what: string, attempts = 15, delayMs = 2000): Promise<T> {
  for (let attempt = 1; ; attempt++) {
    try {
      return await fn();
    } catch (error) {
      if (attempt >= attempts) throw error;
      logger.warn({ attempt, ...errorFields(error) }, `${what} failed; retrying`);
      await sleep(delayMs);
    }
  }
}

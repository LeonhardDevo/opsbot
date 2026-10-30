import { randomUUID } from 'node:crypto';
import type { Queue } from 'bullmq';
import Fastify, { LogController, type FastifyBaseLogger, type FastifyInstance } from 'fastify';
import type { BotEntry, BotRegistry } from '../bot/botRegistry.js';
import type { BotVerifier } from '../telegram/verify.js';
import type { WebhookEventStore } from '../db/webhookEvents.js';
import { errorFields, type Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import type { JobEnqueuer } from '../queues/enqueuer.js';
import { devRoutes } from './routes/dev.js';
import { internalBotRoutes } from './routes/internalBots.js';
import { healthRoutes, type ReadinessCheck } from './routes/health.js';
import { queueDashboardRoutes } from './routes/queueDashboard.js';
import { stripeWebhookRoutes } from './routes/stripe.js';
import { telegramWebhookRoutes } from './routes/telegram.js';
import { yookassaWebhookRoutes } from './routes/yookassa.js';

export interface HttpServerDeps {
  logger: Logger;
  metrics: Metrics;
  enqueuer: JobEnqueuer;
  webhookEvents: WebhookEventStore;
  product: { name: string; slug: string };
  stripeWebhookSecret: string;
  telegramWebhook: { enabled: boolean; bots: { get(botKey: string): Promise<BotEntry | null> } };
  yookassaWebhook?: { allowedIps: readonly string[]; trustedProxyHops: number };
  devRegistry: BotRegistry | null;
  internalBots: { secret: string; verifier: BotVerifier } | null;
  dashboard: { queues: Queue[]; user: string; password: string } | null;
  readiness: Record<string, ReadinessCheck>;
}

const QUIET_ROUTES = new Set(['/healthz', '/readyz', '/metrics']);

export async function buildServer(deps: HttpServerDeps): Promise<FastifyInstance> {
  const logger: FastifyBaseLogger = deps.logger.child({ component: 'http' });
  const app = Fastify({
    loggerInstance: logger,
    logController: new LogController({ disableRequestLogging: true }),
    requestIdHeader: 'x-request-id',
    genReqId: () => randomUUID(),
    trustProxy: true,
    bodyLimit: 1024 * 1024,
  });

  app.addHook('onSend', async (request, reply, payload) => {
    reply.header('x-request-id', request.id);
    return payload;
  });
  app.addHook('onResponse', async (request, reply) => {
    const path = request.url.split('?')[0] ?? request.url;
    const fields = { method: request.method, path, status: reply.statusCode, ms: Math.round(reply.elapsedTime) };
    if (QUIET_ROUTES.has(path)) request.log.debug(fields, 'request');
    else request.log.info(fields, 'request');
  });
  app.setErrorHandler((error: Error & { statusCode?: number }, request, reply) => {
    const status = error.statusCode && error.statusCode >= 400 ? error.statusCode : 500;
    if (status >= 500) request.log.error(errorFields(error), 'request failed');
    return reply.code(status).send({ error: status >= 500 ? 'internal_error' : error.message });
  });
  app.setNotFoundHandler((_request, reply) => reply.code(404).send({ error: 'not_found' }));

  await app.register(healthRoutes({ productSlug: deps.product.slug, metrics: deps.metrics, checks: deps.readiness }));
  await app.register(
    stripeWebhookRoutes({
      webhookSecret: deps.stripeWebhookSecret,
      webhookEvents: deps.webhookEvents,
      enqueuer: deps.enqueuer,
      metrics: deps.metrics,
    }),
  );
  if (deps.yookassaWebhook) {
    await app.register(
      yookassaWebhookRoutes({
        ...deps.yookassaWebhook,
        webhookEvents: deps.webhookEvents,
        enqueuer: deps.enqueuer,
        metrics: deps.metrics,
      }),
    );
  }
  await app.register(
    telegramWebhookRoutes({
      enabled: deps.telegramWebhook.enabled,
      bots: deps.telegramWebhook.bots,
      enqueuer: deps.enqueuer,
      metrics: deps.metrics,
    }),
  );
  if (deps.internalBots) {
    await app.register(internalBotRoutes(deps.internalBots));
  }
  if (deps.devRegistry) {
    await app.register(devRoutes({ registry: deps.devRegistry }));
  }
  if (deps.dashboard) {
    await app.register(
      queueDashboardRoutes({
        ...deps.dashboard,
        productName: deps.product.name,
        productSlug: deps.product.slug,
      }),
    );
  }
  return app;
}

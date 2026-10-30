import type { FastifyPluginAsync } from 'fastify';
import type { Update } from 'grammy/types';
import { isBotKey, type BotEntry } from '../../bot/botRegistry.js';
import { safeEqual } from '../../clients/signing.js';
import type { Metrics } from '../../metrics.js';
import type { JobEnqueuer } from '../../queues/enqueuer.js';
import { enqueueUpdate } from '../../telegram/polling.js';

export interface TelegramRouteDeps {
  enabled: boolean;
  bots: { get(botKey: string): Promise<BotEntry | null> };
  enqueuer: JobEnqueuer;
  metrics?: Metrics;
}

export const TELEGRAM_SECRET_HEADER = 'x-telegram-bot-api-secret-token';

export function telegramWebhookRoutes(deps: TelegramRouteDeps): FastifyPluginAsync {
  return async (app) => {
    app.post<{ Params: { botKey: string } }>('/webhooks/telegram/:botKey', { bodyLimit: 1024 * 1024 }, async (request, reply) => {
      const { botKey } = request.params;
      if (!deps.enabled || !isBotKey(botKey)) return reply.code(404).send({ error: 'not_found' });
      const entry = await deps.bots.get(botKey).catch(() => null);
      if (!entry || entry.status !== 'active' || !entry.webhookSecret) {
        deps.metrics?.webhooks.inc({ provider: 'telegram', result: 'unknown_bot' });
        return reply.code(404).send({ error: 'not_found' });
      }
      const provided = request.headers[TELEGRAM_SECRET_HEADER];
      if (typeof provided !== 'string' || !safeEqual(provided, entry.webhookSecret)) {
        deps.metrics?.webhooks.inc({ provider: 'telegram', result: 'unauthorized' });
        return reply.code(401).send({ error: 'unauthorized' });
      }
      const update = request.body as Partial<Update> | null;
      if (!update || typeof update !== 'object' || typeof update.update_id !== 'number') {
        deps.metrics?.webhooks.inc({ provider: 'telegram', result: 'invalid' });
        return reply.code(400).send({ error: 'invalid_update' });
      }
      const { created } = await enqueueUpdate(deps.enqueuer, botKey, update as Update);
      deps.metrics?.webhooks.inc({ provider: 'telegram', result: created ? 'accepted' : 'duplicate' });
      return reply.send({ ok: true });
    });
  };
}

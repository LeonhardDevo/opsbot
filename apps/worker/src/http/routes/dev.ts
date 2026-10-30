import type { FastifyPluginAsync } from 'fastify';
import type { Update } from 'grammy/types';
import { z } from 'zod';
import { PLATFORM_BOT_KEY, isBotKey, type BotRegistry } from '../../bot/botRegistry.js';
import { buildDevUpdate, devUpdateShorthandSchema } from '../../bot/devUpdate.js';

function isFullUpdate(value: unknown): value is Update {
  if (value === null || typeof value !== 'object') return false;
  const record = value as Record<string, unknown>;
  return typeof record.update_id === 'number' && ('message' in record || 'callback_query' in record || 'my_chat_member' in record);
}

export function devRoutes(deps: { registry: BotRegistry }): FastifyPluginAsync {
  return async (app) => {
    app.post('/dev/telegram/update', async (request, reply) => {
      const body = (request.body ?? {}) as Record<string, unknown>;
      const botKey = typeof body.bot_key === 'string' ? body.bot_key : PLATFORM_BOT_KEY;
      if (!isBotKey(botKey)) return reply.code(422).send({ error: 'invalid_bot_key' });
      const entry = await deps.registry.get(botKey);
      if (!entry) return reply.code(404).send({ error: 'unknown_bot' });
      let update: Update;
      if (isFullUpdate(body.update)) {
        update = body.update;
      } else if (isFullUpdate(body)) {
        update = body;
      } else {
        const { bot_key: _ignored, ...shorthand } = body;
        const parsed = devUpdateShorthandSchema.safeParse(shorthand);
        if (!parsed.success) {
          return reply.code(422).send({ error: 'invalid_update', details: z.prettifyError(parsed.error) });
        }
        update = buildDevUpdate(parsed.data, { id: entry.info.id, username: entry.info.username });
      }
      const outbound = await entry.handleUpdateCaptured(update);
      return reply.send({ update_id: update.update_id, bot_key: botKey, outbound });
    });

    app.get('/dev/telegram/outbox', async (request) => {
      const botKey = (request.query as { bot_key?: unknown }).bot_key;
      return { outbound: deps.registry.capture.offlineCalls(typeof botKey === 'string' ? botKey : undefined) };
    });

    app.delete('/dev/telegram/outbox', async (request, reply) => {
      const botKey = (request.query as { bot_key?: unknown }).bot_key;
      deps.registry.capture.clearOffline(typeof botKey === 'string' ? botKey : undefined);
      return reply.code(204).send();
    });
  };
}

import { z } from 'zod';
import { errorFields } from '../logger.js';
import type { JobDeps } from './deps.js';
import { errorMessage, parseOutboxJob, withGiveUp, type JobContext, type JobHandler } from './types.js';

const botKey = z.string().regex(/^[1-9][0-9]{0,18}$/);

export const botDisconnectSchema = z.object({
  bot_key: botKey,
  telegram_bot_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
});

export const contextInvalidateSchema = z.object({
  workspace_id: z.number().int().positive(),
  user_id: z.number().int().positive(),
  keys: z
    .array(z.object({ bot_key: z.string().regex(/^(platform|[1-9][0-9]{0,18})$/), telegram_user_id: z.number().int().positive() }))
    .max(100),
});

export function createBotDisconnectHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, botDisconnectSchema);
    const key = payload.bot_key;
    await withGiveUp(
      ctx,
      async () => {
        await deps.lifecycle.stopPolling(key);
        const entry = await deps.bots.get(key, ctx.requestId).catch((error: unknown) => {
          ctx.logger.warn(errorFields(error), 'bot credentials lookup failed during disconnect');
          return null;
        });
        if (entry) {
          await entry.bot.api.deleteWebhook({ drop_pending_updates: true }).catch((error: unknown) => {
            ctx.logger.warn(errorFields(error), 'telegram deleteWebhook failed during disconnect');
          });
        }
        const chats = await deps.preferences.forgetBot(key);
        const groups = await deps.groupNotices.forgetBot(key);
        deps.bots.evict(key);
        await deps.lifecycle.publishEvict(key).catch(() => undefined);
        await deps.web.reportBotStatus(payload.telegram_bot_id, { status: 'disconnected' }, ctx.requestId);
        ctx.logger.info({ botKey: key, chats, groups }, 'company bot disconnected');
      },
      async (error) => {
        ctx.logger.error({ botKey: key, error: errorMessage(error) }, 'bot disconnect gave up; web finalizes on the daily tick');
      },
    );
    return { disconnected: key };
  };
}

export function createContextInvalidateHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, contextInvalidateSchema);
    for (const item of payload.keys) await deps.accounts.invalidate(item.bot_key, item.telegram_user_id);
    ctx.logger.info({ userId: payload.user_id, keys: payload.keys.length }, 'telegram contexts invalidated');
    return { invalidated: payload.keys.length };
  };
}

import { z } from 'zod';
import type { JobDeps } from './deps.js';
import { permanent, type JobContext, type JobHandler } from './types.js';

export const telegramUpdateJobSchema = z.object({
  bot_key: z.string().regex(/^(platform|[1-9][0-9]{0,18})$/),
  update: z.object({ update_id: z.number().int().nonnegative() }).passthrough(),
});

export function createTelegramUpdateHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const parsed = telegramUpdateJobSchema.safeParse(data);
    if (!parsed.success) throw permanent('Telegram update job needs {bot_key, update}');
    const { bot_key: botKey, update } = parsed.data;
    const entry = await deps.bots.get(botKey, ctx.requestId);
    if (!entry) {
      ctx.logger.info({ botKey, updateId: update.update_id }, 'telegram update dropped: bot is not connected');
      return { dropped: true };
    }
    await entry.handleUpdate(update);
    ctx.logger.debug({ botKey, updateId: update.update_id }, 'telegram update handled');
    return { updateId: update.update_id };
  };
}

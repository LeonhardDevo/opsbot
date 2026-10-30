import { z } from 'zod';
import type { JobDeps } from './deps.js';
import { errorMessage, permanent, withGiveUp, type JobContext, type JobHandler } from './types.js';

export const YOOKASSA_PROVIDER = 'yookassa';

export const yookassaEventId = (event: string, objectId: string): string => `${event}:${objectId}`;

export const yookassaNotificationSchema = z
  .object({
    type: z.literal('notification'),
    event: z.enum(['payment.succeeded', 'payment.canceled', 'payment.waiting_for_capture']),
    object: z.object({ id: z.string().regex(/^[A-Za-z0-9_-]{1,64}$/) }).passthrough(),
  })
  .passthrough();

export type YooKassaNotification = z.infer<typeof yookassaNotificationSchema>;

async function forward(
  deps: JobDeps,
  ctx: JobContext,
  provider: string,
  eventId: string,
  send: () => Promise<void>,
): Promise<void> {
  await withGiveUp(
    ctx,
    async () => {
      try {
        await send();
      } catch (error) {
        await deps.webhookEvents.markFailed(provider, eventId, errorMessage(error)).catch(() => undefined);
        throw error;
      }
    },
    async (error) => {
      ctx.logger.error({ provider, eventId, error: errorMessage(error) }, 'billing webhook forwarding gave up');
    },
  );
  await deps.webhookEvents.markProcessed(provider, eventId);
}

export function createYooKassaNotificationHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const parsed = yookassaNotificationSchema.safeParse(data);
    if (!parsed.success) throw permanent(`Invalid YooKassa notification: ${z.prettifyError(parsed.error)}`);
    const notification = parsed.data;
    const eventId = yookassaEventId(notification.event, notification.object.id);
    await forward(deps, ctx, YOOKASSA_PROVIDER, eventId, () => deps.web.forwardYooKassaNotification(notification, ctx.requestId));
    ctx.logger.info({ eventId }, 'yookassa notification forwarded to web');
    return { forwarded: eventId };
  };
}

export function createBillingRenewalsHandler(deps: JobDeps): JobHandler {
  return async (_data: unknown, ctx: JobContext) => {
    const result = await deps.web.runBillingRenewals(ctx.requestId);
    ctx.logger.info(result, 'billing renewals run');
    return result;
  };
}

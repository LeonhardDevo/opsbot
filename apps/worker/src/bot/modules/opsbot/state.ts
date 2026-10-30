import { z } from 'zod';
import type { BotContext } from '../../types.js';

export const INCIDENT_CATEGORIES = ['damage', 'complaint', 'no_access', 'injury', 'lost_item', 'other'] as const;
export type IncidentCategory = (typeof INCIDENT_CATEGORIES)[number];

export const incidentFlowSchema = z.object({
  step: z.enum(['category', 'description', 'confirm']),
  category: z.enum(INCIDENT_CATEGORIES).nullish(),
  description: z.string().nullish(),
  prompt_message_id: z.number().nullish(),
});
export type IncidentFlow = z.infer<typeof incidentFlowSchema>;

export const replyStateSchema = z.object({
  question_id: z.number().int().positive(),
  prompt_message_id: z.number().int(),
  display_number: z.string().nullish(),
});
export type ReplyState = z.infer<typeof replyStateSchema>;

export function flowKey(botKey: string, telegramUserId: number): string {
  return `flow:${botKey}:${telegramUserId}`;
}

export function replyKey(botKey: string, telegramUserId: number): string {
  return `reply:${botKey}:${telegramUserId}`;
}

async function readJson<T>(ctx: BotContext, key: string, schema: z.ZodType<T>): Promise<T | null> {
  const raw = await ctx.services.state.get(key).catch(() => null);
  if (raw === null) return null;
  try {
    const parsed = schema.safeParse(JSON.parse(raw));
    return parsed.success ? parsed.data : null;
  } catch {
    return null;
  }
}

export async function readFlow(ctx: BotContext): Promise<IncidentFlow | null> {
  if (!ctx.from) return null;
  return readJson(ctx, flowKey(ctx.botKey, ctx.from.id), incidentFlowSchema);
}

export async function writeFlow(ctx: BotContext, flow: IncidentFlow): Promise<void> {
  if (!ctx.from) return;
  await ctx.services.state.set(flowKey(ctx.botKey, ctx.from.id), JSON.stringify(flow), ctx.services.settings.flowTtlS);
}

export async function clearFlow(ctx: BotContext): Promise<void> {
  if (!ctx.from) return;
  await ctx.services.state.del(flowKey(ctx.botKey, ctx.from.id)).catch(() => undefined);
}

export async function readReply(ctx: BotContext): Promise<ReplyState | null> {
  if (!ctx.from) return null;
  return readJson(ctx, replyKey(ctx.botKey, ctx.from.id), replyStateSchema);
}

export async function writeReply(ctx: BotContext, state: ReplyState): Promise<void> {
  if (!ctx.from) return;
  await ctx.services.state.set(replyKey(ctx.botKey, ctx.from.id), JSON.stringify(state), ctx.services.settings.replyTtlS);
}

export async function clearReply(ctx: BotContext): Promise<void> {
  if (!ctx.from) return;
  await ctx.services.state.del(replyKey(ctx.botKey, ctx.from.id)).catch(() => undefined);
}

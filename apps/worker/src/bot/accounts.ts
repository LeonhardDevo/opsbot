import { telegramContextSchema, type TelegramContext, type WebApi } from '../clients/web.js';
import type { KeyValueCache } from './stores.js';

const NEGATIVE_TTL_CAP_S = 15;

export function accountCacheKey(botKey: string, telegramUserId: number): string {
  return `account:${botKey}:${telegramUserId}`;
}

export class AccountResolver {
  constructor(
    private readonly web: WebApi,
    private readonly cache: KeyValueCache,
    private readonly ttlS: number,
  ) {}

  async get(botKey: string, telegramUserId: number, requestId?: string): Promise<TelegramContext | null> {
    const key = accountCacheKey(botKey, telegramUserId);
    const cached = await this.cache.get(key).catch(() => null);
    if (cached !== null) {
      if (cached === 'null') return null;
      const parsed = telegramContextSchema.safeParse(JSON.parse(cached));
      if (parsed.success) return parsed.data;
    }
    const context = await this.web.telegramContext({ botKey, telegramUserId }, requestId);
    const ttl = context ? this.ttlS : Math.min(this.ttlS, NEGATIVE_TTL_CAP_S);
    await this.cache.set(key, context ? JSON.stringify(context) : 'null', ttl).catch(() => undefined);
    return context;
  }

  async invalidate(botKey: string, telegramUserId: number): Promise<void> {
    await this.cache.del(accountCacheKey(botKey, telegramUserId)).catch(() => undefined);
  }
}

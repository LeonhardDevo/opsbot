import { Bot, type Composer } from 'grammy';
import type { Update, UserFromGetMe } from 'grammy/types';
import type { Redis } from 'ioredis';
import type { BotCredentials } from '../clients/web.js';
import { errorFields, type Logger } from '../logger.js';
import { TelegramCapture, fakeBotInfo, isFakeToken, staticBotInfo, tokenBotId, type OutboundCall } from '../telegram/fakeDriver.js';
import { withBotKey } from './middleware.js';
import type { BotContext } from './types.js';

export const BOT_KEY_PATTERN = /^(platform|[1-9][0-9]{0,18})$/;
export const PLATFORM_BOT_KEY = 'platform';
export const OFFLINE_PLATFORM_TOKEN = '0:offline-fake-token';
export const ALLOWED_UPDATES = ['message', 'callback_query', 'my_chat_member'] as const;

export type Transport = 'real' | 'fake';

export interface BotEntry {
  readonly key: string;
  readonly bot: Bot<BotContext>;
  readonly transport: Transport;
  readonly webhookSecret: string | null;
  readonly status: string;
  readonly tgBotId: number;
  readonly createdAt: number;
  lastUsedAt: number;
  info: UserFromGetMe;
  handleUpdate(update: Update): Promise<void>;
  handleUpdateCaptured(update: Update): Promise<OutboundCall[]>;
}

export interface CredentialsSource {
  botCredentials(botId: number, requestId?: string): Promise<BotCredentials | null>;
}

export interface PlatformBotOptions {
  token: string | undefined;
  username: string;
  productName: string;
  webhookSecret: string | undefined;
}

export interface BotRegistryOptions {
  composer: Composer<BotContext>;
  credentials: CredentialsSource;
  platform: PlatformBotOptions;
  driver: 'auto' | 'fake';
  apiRoot: string;
  capture?: TelegramCapture;
  logger: Logger;
  maxEntries?: number;
  idleMs?: number;
  inactiveTtlMs?: number;
  now?: () => number;
  onChange?: (size: number) => void;
}

export const REGISTRY_CHANNEL = 'bot-registry';
const LIVE_STATUSES = new Set(['active', 'connecting', 'revoked', 'disconnecting']);

export function isBotKey(value: string): boolean {
  return BOT_KEY_PATTERN.test(value);
}

export class BotRegistry {
  readonly capture: TelegramCapture;
  private readonly entries = new Map<string, BotEntry>();
  private readonly pending = new Map<string, Promise<BotEntry | null>>();
  private readonly maxEntries: number;
  private readonly idleMs: number;
  private readonly inactiveTtlMs: number;
  private readonly now: () => number;
  private sweepTimer: NodeJS.Timeout | null = null;

  constructor(private readonly options: BotRegistryOptions) {
    this.capture = options.capture ?? new TelegramCapture();
    this.maxEntries = options.maxEntries ?? 500;
    this.idleMs = options.idleMs ?? 30 * 60_000;
    this.inactiveTtlMs = options.inactiveTtlMs ?? 15_000;
    this.now = options.now ?? Date.now;
  }

  get size(): number {
    return this.entries.size;
  }

  keys(): string[] {
    return [...this.entries.keys()];
  }

  peek(botKey: string): BotEntry | undefined {
    return this.entries.get(botKey);
  }

  async get(botKey: string, requestId?: string): Promise<BotEntry | null> {
    if (!isBotKey(botKey)) return null;
    const cached = this.entries.get(botKey);
    if (cached && this.isFresh(cached)) {
      cached.lastUsedAt = this.now();
      this.entries.delete(botKey);
      this.entries.set(botKey, cached);
      return cached;
    }
    if (cached) this.evict(botKey);
    const inflight = this.pending.get(botKey);
    if (inflight) return inflight;
    const build = this.build(botKey, requestId).finally(() => this.pending.delete(botKey));
    this.pending.set(botKey, build);
    return build;
  }

  evict(botKey: string): boolean {
    const removed = this.entries.delete(botKey);
    if (removed) this.options.onChange?.(this.entries.size);
    return removed;
  }

  sweep(): number {
    const cutoff = this.now() - this.idleMs;
    let removed = 0;
    for (const [key, entry] of this.entries) {
      if (key !== PLATFORM_BOT_KEY && entry.lastUsedAt < cutoff) {
        this.entries.delete(key);
        removed++;
      }
    }
    if (removed > 0) this.options.onChange?.(this.entries.size);
    return removed;
  }

  startSweeper(intervalMs = 60_000): void {
    this.sweepTimer ??= setInterval(() => this.sweep(), intervalMs);
    this.sweepTimer.unref();
  }

  stopSweeper(): void {
    if (this.sweepTimer) clearInterval(this.sweepTimer);
    this.sweepTimer = null;
  }

  async subscribe(subscriber: Redis, channel: string): Promise<void> {
    subscriber.on('message', (received: string, message: string) => {
      if (received !== channel) return;
      try {
        const parsed = JSON.parse(message) as { type?: unknown; bot_key?: unknown };
        if (parsed.type === 'evict' && typeof parsed.bot_key === 'string') this.evict(parsed.bot_key);
      } catch (error) {
        this.options.logger.warn(errorFields(error), 'invalid bot registry message');
      }
    });
    await subscriber.subscribe(channel);
  }

  static async publishEvict(publisher: Redis, channel: string, botKey: string): Promise<void> {
    await publisher.publish(channel, JSON.stringify({ type: 'evict', bot_key: botKey }));
  }

  transportFor(token: string | undefined): Transport {
    if (this.options.driver === 'fake' || isFakeToken(token)) return 'fake';
    return 'real';
  }

  createBot(botKey: string, token: string, info: UserFromGetMe, transport: Transport): Bot<BotContext> {
    const bot = new Bot<BotContext>(token, { botInfo: info, client: { apiRoot: this.options.apiRoot } });
    bot.api.config.use(this.capture.transformer({ botKey, offline: transport === 'fake', botInfo: () => bot.botInfo }));
    bot.use(withBotKey(botKey));
    bot.use(this.options.composer);
    return bot;
  }

  private isFresh(entry: BotEntry): boolean {
    if (entry.key === PLATFORM_BOT_KEY || entry.status === 'active') return true;
    return this.now() - entry.createdAt < this.inactiveTtlMs;
  }

  private async build(botKey: string, requestId?: string): Promise<BotEntry | null> {
    if (botKey === PLATFORM_BOT_KEY) return this.store(this.platformEntry());
    const credentials = await this.options.credentials.botCredentials(Number(botKey), requestId);
    if (!credentials || credentials.bot_key !== botKey || !LIVE_STATUSES.has(credentials.status)) return null;
    const transport = this.transportFor(credentials.token);
    const info =
      transport === 'fake'
        ? fakeBotInfo(tokenBotId(credentials.token) ?? credentials.tg_bot_id, credentials.username)
        : fakeBotInfo(credentials.tg_bot_id, credentials.username ?? `bot_${credentials.tg_bot_id}`, credentials.username);
    const bot = this.createBot(botKey, credentials.token, info, transport);
    return this.store(
      this.entry({
        key: botKey,
        bot,
        transport,
        webhookSecret: credentials.webhook_secret ?? null,
        status: credentials.status,
        tgBotId: credentials.tg_bot_id,
      }),
    );
  }

  private platformEntry(): BotEntry {
    const { token, username, productName, webhookSecret } = this.options.platform;
    const transport = this.transportFor(token);
    const info = staticBotInfo(username, productName, tokenBotId(token ?? '') ?? 1);
    const bot = this.createBot(PLATFORM_BOT_KEY, token ?? OFFLINE_PLATFORM_TOKEN, info, transport);
    return this.entry({
      key: PLATFORM_BOT_KEY,
      bot,
      transport,
      webhookSecret: webhookSecret ?? null,
      status: 'active',
      tgBotId: info.id,
    });
  }

  private entry(fields: Omit<BotEntry, 'createdAt' | 'lastUsedAt' | 'info' | 'handleUpdate' | 'handleUpdateCaptured'>): BotEntry {
    const created = this.now();
    const capture = this.capture;
    const entry: BotEntry = {
      ...fields,
      createdAt: created,
      lastUsedAt: created,
      get info() {
        return fields.bot.botInfo;
      },
      set info(value: UserFromGetMe) {
        fields.bot.botInfo = value;
      },
      async handleUpdate(update) {
        await fields.bot.handleUpdate(update);
      },
      async handleUpdateCaptured(update) {
        const { outbound } = await capture.capture(() => fields.bot.handleUpdate(update));
        return outbound;
      },
    };
    return entry;
  }

  private store(entry: BotEntry): BotEntry {
    this.entries.set(entry.key, entry);
    while (this.entries.size > this.maxEntries) {
      const oldest = [...this.entries.keys()].find((key) => key !== PLATFORM_BOT_KEY);
      if (oldest === undefined) break;
      this.entries.delete(oldest);
    }
    this.options.onChange?.(this.entries.size);
    return entry;
  }
}

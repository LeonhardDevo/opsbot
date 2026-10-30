import { GrammyError, type InlineKeyboard } from 'grammy';
import type { BotEntry, BotRegistry } from '../bot/botRegistry.js';
import { PLATFORM_BOT_KEY } from '../bot/botRegistry.js';
import { stripHtml } from '../bot/format.js';
import type { WebApi } from '../clients/web.js';
import type { Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import { telegramBuckets, type RateBuckets } from './buckets.js';

export class TelegramRateDelay extends Error {
  constructor(public readonly waitMs: number) {
    super(`telegram send delayed by ${waitMs} ms`);
    this.name = 'TelegramRateDelay';
  }
}

export class TelegramChatBlockedError extends Error {
  readonly retryable = false;

  constructor() {
    super('chat_blocked');
    this.name = 'TelegramChatBlockedError';
  }
}

export class TelegramBotRevokedError extends Error {
  readonly retryable = false;

  constructor(botKey: string) {
    super(`bot ${botKey} token was rejected by Telegram`);
    this.name = 'TelegramBotRevokedError';
  }
}

export class TelegramBotUnavailableError extends Error {
  readonly retryable = false;

  constructor(botKey: string) {
    super(`bot ${botKey} is not available`);
    this.name = 'TelegramBotUnavailableError';
  }
}

export interface SendInput {
  botKey: string;
  chatId: number | string;
  html: string;
  keyboard?: InlineKeyboard;
  telegramUserId?: number | null;
  requestId?: string;
}

export interface TelegramSenderApi {
  send(input: SendInput): Promise<{ message_id: number }>;
}

export class TelegramSender implements TelegramSenderApi {
  constructor(
    private readonly deps: {
      registry: BotRegistry;
      buckets: RateBuckets;
      web: WebApi;
      rates: { botPerS: number; chatPerS: number };
      logger: Logger;
      metrics?: Metrics;
    },
  ) {}

  async send(input: SendInput): Promise<{ message_id: number }> {
    const entry = await this.deps.registry.get(input.botKey, input.requestId);
    if (!entry) throw new TelegramBotUnavailableError(input.botKey);
    const wait = await this.deps.buckets.take(telegramBuckets(input.botKey, input.chatId, this.deps.rates));
    if (wait > 0) throw new TelegramRateDelay(wait);
    try {
      const message = await this.sendHtml(entry, input);
      this.deps.metrics?.telegramSent.inc({ bot_key_class: input.botKey === PLATFORM_BOT_KEY ? 'platform' : 'company' });
      return message;
    } catch (error) {
      throw await this.mapError(entry, input, error);
    }
  }

  private async sendHtml(entry: BotEntry, input: SendInput): Promise<{ message_id: number }> {
    const extra = {
      link_preview_options: { is_disabled: true },
      ...(input.keyboard ? { reply_markup: input.keyboard } : {}),
    };
    try {
      return await entry.bot.api.sendMessage(input.chatId, input.html, { parse_mode: 'HTML', ...extra });
    } catch (error) {
      if (error instanceof GrammyError && error.error_code === 400 && /parse entities/i.test(error.description)) {
        this.deps.logger.warn({ botKey: input.botKey }, 'telegram rejected HTML; sending plain text');
        return entry.bot.api.sendMessage(input.chatId, stripHtml(input.html), extra);
      }
      throw error;
    }
  }

  private async mapError(entry: BotEntry, input: SendInput, error: unknown): Promise<unknown> {
    if (!(error instanceof GrammyError)) return error;
    if (error.error_code === 429) {
      const retryAfter = Number(error.parameters.retry_after ?? 1);
      return new TelegramRateDelay(Math.max(1, retryAfter) * 1000);
    }
    if (error.error_code === 401) {
      if (entry.key !== PLATFORM_BOT_KEY) {
        await this.deps.web
          .reportBotStatus(Number(entry.key), { status: 'revoked', error_code: 'revoked' }, input.requestId)
          .catch(() => undefined);
      }
      this.deps.registry.evict(entry.key);
      return new TelegramBotRevokedError(entry.key);
    }
    if (error.error_code === 403) {
      const userId = input.telegramUserId ?? (typeof input.chatId === 'number' && input.chatId > 0 ? input.chatId : null);
      if (userId !== null) {
        await this.deps.web.reportChatBlocked(entry.key, userId, true, input.requestId).catch(() => undefined);
      }
      return new TelegramChatBlockedError();
    }
    return error;
  }
}

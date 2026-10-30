import { AsyncLocalStorage } from 'node:async_hooks';
import type { Transformer } from 'grammy';
import type { UserFromGetMe } from 'grammy/types';

export interface OutboundCall {
  bot_key: string;
  method: string;
  payload: Record<string, unknown>;
  message_id?: number;
}

export const FAKE_TOKEN_PATTERN = /^\d+:TEST[A-Za-z0-9_-]{30,}$/;
const OFFLINE_LOG_SIZE = 500;

export function isFakeToken(token: string | undefined): boolean {
  return token === undefined || FAKE_TOKEN_PATTERN.test(token);
}

export function tokenBotId(token: string): number | null {
  const prefix = /^(\d{1,16}):/.exec(token)?.[1];
  return prefix ? Number(prefix) : null;
}

export function fakeBotInfo(botId: number, username?: string | null, firstName?: string | null): UserFromGetMe {
  return {
    id: botId,
    is_bot: true,
    first_name: firstName ?? `Test Bot ${botId}`,
    username: username ?? `test_${botId}_bot`,
    can_join_groups: false,
    can_read_all_group_messages: false,
    supports_inline_queries: false,
    can_connect_to_business: false,
    has_main_web_app: false,
  } as UserFromGetMe;
}

export function staticBotInfo(username: string, productName: string, botId = 1): UserFromGetMe {
  return fakeBotInfo(botId, username, productName);
}

export interface TransformerOptions {
  botKey: string;
  offline: boolean;
  botInfo: () => UserFromGetMe;
}

export class TelegramCapture {
  private readonly storage = new AsyncLocalStorage<OutboundCall[]>();
  private readonly offline: OutboundCall[] = [];
  private messageSeq = 1000;

  async capture<T>(fn: () => Promise<T>): Promise<{ result: T; outbound: OutboundCall[] }> {
    const outbound: OutboundCall[] = [];
    const result = await this.storage.run(outbound, fn);
    return { result, outbound };
  }

  offlineCalls(botKey?: string): OutboundCall[] {
    return this.offline.filter((call) => botKey === undefined || call.bot_key === botKey);
  }

  clearOffline(botKey?: string): void {
    if (botKey === undefined) {
      this.offline.length = 0;
      return;
    }
    const kept = this.offline.filter((call) => call.bot_key !== botKey);
    this.offline.length = 0;
    this.offline.push(...kept);
  }

  transformer(options: TransformerOptions): Transformer {
    return async (prev, method, payload, signal) => {
      const sink = this.storage.getStore();
      if (!sink && !options.offline) return prev(method, payload, signal);
      const call: OutboundCall = { bot_key: options.botKey, method, payload: toPlain(payload) };
      if (sink) {
        sink.push(call);
      } else {
        this.offline.push(call);
        if (this.offline.length > OFFLINE_LOG_SIZE) this.offline.shift();
      }
      const result = this.fakeResult(method, call.payload, options.botInfo());
      const messageId = messageIdOf(result);
      if (messageId !== null) call.message_id = messageId;
      return { ok: true, result: result as never };
    };
  }

  private fakeResult(method: string, payload: Record<string, unknown>, info: UserFromGetMe): unknown {
    const chatId = typeof payload.chat_id === 'number' || typeof payload.chat_id === 'string' ? payload.chat_id : 0;
    const message = (): Record<string, unknown> => ({
      message_id: this.messageSeq++,
      date: Math.floor(Date.now() / 1000),
      chat: { id: chatId, type: 'private' },
      from: { id: info.id, is_bot: true, first_name: info.first_name, username: info.username },
      ...(typeof payload.text === 'string' ? { text: payload.text } : {}),
    });
    switch (method) {
      case 'getMe':
        return info;
      case 'getUpdates':
        return [];
      case 'sendMessage':
      case 'sendPhoto':
      case 'sendDocument':
      case 'forwardMessage':
      case 'editMessageText':
      case 'editMessageReplyMarkup':
        return message();
      case 'copyMessage':
        return { message_id: this.messageSeq++ };
      case 'getWebhookInfo':
        return { url: '', has_custom_certificate: false, pending_update_count: 0 };
      default:
        return true;
    }
  }
}

function messageIdOf(result: unknown): number | null {
  if (result === null || typeof result !== 'object') return null;
  const id = (result as { message_id?: unknown }).message_id;
  return typeof id === 'number' ? id : null;
}

function toPlain(payload: unknown): Record<string, unknown> {
  if (payload === null || typeof payload !== 'object') return {};
  try {
    return JSON.parse(JSON.stringify(payload)) as Record<string, unknown>;
  } catch {
    return { ...(payload as Record<string, unknown>) };
  }
}

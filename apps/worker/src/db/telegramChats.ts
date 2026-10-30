import type pg from 'pg';
import type { Locale } from '../i18n/index.js';

export interface ChatPreferences {
  getLocale(botKey: string, telegramUserId: number): Promise<Locale | null>;
  setLocale(botKey: string, telegramUserId: number, chatId: number, locale: Locale, username?: string | null): Promise<void>;
  forgetBot(botKey: string): Promise<number>;
  forget(telegramUserIds: readonly number[]): Promise<number>;
}

function safeSchema(schema: string): string {
  if (!/^[a-z_][a-z0-9_]*$/.test(schema)) throw new Error(`Unsafe schema name: ${schema}`);
  return schema;
}

export class PgChatPreferences implements ChatPreferences {
  private readonly table: string;

  constructor(
    private readonly pool: pg.Pool,
    schema = 'worker',
  ) {
    this.table = `"${safeSchema(schema)}".telegram_chats`;
  }

  async getLocale(botKey: string, telegramUserId: number): Promise<Locale | null> {
    const { rows } = await this.pool.query<{ locale: string | null }>(
      `SELECT locale FROM ${this.table} WHERE bot_key = $1 AND telegram_user_id = $2`,
      [botKey, telegramUserId],
    );
    const locale = rows[0]?.locale;
    return locale === 'ru' || locale === 'en' ? locale : null;
  }

  async setLocale(botKey: string, telegramUserId: number, chatId: number, locale: Locale, username: string | null = null): Promise<void> {
    await this.pool.query(
      `INSERT INTO ${this.table} (bot_key, telegram_user_id, chat_id, username, locale)
       VALUES ($1, $2, $3, $4, $5)
       ON CONFLICT (bot_key, telegram_user_id)
       DO UPDATE SET chat_id = EXCLUDED.chat_id, username = COALESCE(EXCLUDED.username, ${this.table}.username),
                     locale = EXCLUDED.locale, updated_at = now()`,
      [botKey, telegramUserId, chatId, username, locale],
    );
  }

  async forgetBot(botKey: string): Promise<number> {
    const result = await this.pool.query(`DELETE FROM ${this.table} WHERE bot_key = $1`, [botKey]);
    return result.rowCount ?? 0;
  }

  async forget(telegramUserIds: readonly number[]): Promise<number> {
    if (telegramUserIds.length === 0) return 0;
    const result = await this.pool.query(`DELETE FROM ${this.table} WHERE telegram_user_id = ANY($1::bigint[])`, [[...telegramUserIds]]);
    return result.rowCount ?? 0;
  }
}

export class MemoryChatPreferences implements ChatPreferences {
  readonly locales = new Map<string, Locale>();

  async getLocale(botKey: string, telegramUserId: number): Promise<Locale | null> {
    return this.locales.get(`${botKey}:${telegramUserId}`) ?? null;
  }

  async setLocale(botKey: string, telegramUserId: number, _chatId: number, locale: Locale): Promise<void> {
    this.locales.set(`${botKey}:${telegramUserId}`, locale);
  }

  async forgetBot(botKey: string): Promise<number> {
    let removed = 0;
    for (const key of [...this.locales.keys()]) {
      if (key.startsWith(`${botKey}:`)) {
        this.locales.delete(key);
        removed++;
      }
    }
    return removed;
  }

  async forget(telegramUserIds: readonly number[]): Promise<number> {
    const ids = new Set(telegramUserIds.map(String));
    let removed = 0;
    for (const key of [...this.locales.keys()]) {
      if (ids.has(key.slice(key.indexOf(':') + 1))) {
        this.locales.delete(key);
        removed++;
      }
    }
    return removed;
  }
}

export interface GroupNotices {
  markOnce(botKey: string, chatId: number): Promise<boolean>;
  forgetBot(botKey: string): Promise<number>;
}

export class PgGroupNotices implements GroupNotices {
  private readonly table: string;

  constructor(
    private readonly pool: pg.Pool,
    schema = 'worker',
  ) {
    this.table = `"${safeSchema(schema)}".telegram_group_notices`;
  }

  async markOnce(botKey: string, chatId: number): Promise<boolean> {
    const result = await this.pool.query(
      `INSERT INTO ${this.table} (bot_key, chat_id) VALUES ($1, $2) ON CONFLICT (bot_key, chat_id) DO NOTHING RETURNING chat_id`,
      [botKey, chatId],
    );
    return result.rowCount === 1;
  }

  async forgetBot(botKey: string): Promise<number> {
    const result = await this.pool.query(`DELETE FROM ${this.table} WHERE bot_key = $1`, [botKey]);
    return result.rowCount ?? 0;
  }
}

export class MemoryGroupNotices implements GroupNotices {
  readonly chats = new Set<string>();

  async markOnce(botKey: string, chatId: number): Promise<boolean> {
    const key = `${botKey}:${chatId}`;
    if (this.chats.has(key)) return false;
    this.chats.add(key);
    return true;
  }

  async forgetBot(botKey: string): Promise<number> {
    let removed = 0;
    for (const key of [...this.chats]) {
      if (key.startsWith(`${botKey}:`)) {
        this.chats.delete(key);
        removed++;
      }
    }
    return removed;
  }
}

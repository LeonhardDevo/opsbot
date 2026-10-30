import pg from 'pg';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { migrate } from '../src/db/migrate.js';
import { migrations } from '../src/db/migrations.js';
import { PgChatPreferences, PgGroupNotices } from '../src/db/telegramChats.js';
import { PgWebhookEventStore } from '../src/db/webhookEvents.js';
import { createSilentLogger } from '../src/logger.js';
import { postgresAvailable, announceSkip, testDatabaseUrl, uniqueSuffix } from './helpers/infra.js';

const databaseUrl = await postgresAvailable();
const skipSuffix = databaseUrl ? '' : announceSkip('Postgres (worker schema tests)', testDatabaseUrl());

const schema = `worker_t_${uniqueSuffix()}`;

describe.skipIf(!databaseUrl)(`worker schema (real Postgres)${skipSuffix}`, () => {
  let pool: pg.Pool;

  beforeAll(async () => {
    pool = new pg.Pool({ connectionString: databaseUrl!, max: 4 });
  });

  afterAll(async () => {
    await pool.query(`DROP SCHEMA IF EXISTS "${schema}" CASCADE`);
    await pool.end();
  });

  it('applies migrations once, concurrently safe', async () => {
    const [first, second] = await Promise.all([
      migrate(pool, createSilentLogger(), { schema }),
      migrate(pool, createSilentLogger(), { schema }),
    ]);
    expect([...first, ...second].sort()).toEqual(migrations.map((migration) => migration.version).sort());
    expect(await migrate(pool, createSilentLogger(), { schema })).toEqual([]);
    const { rows } = await pool.query<{ table_name: string }>(
      `SELECT table_name FROM information_schema.tables WHERE table_schema = $1 ORDER BY table_name`,
      [schema],
    );
    expect(rows.map((row) => row.table_name)).toEqual(['schema_migrations', 'telegram_chats', 'telegram_group_notices', 'webhook_events']);
  });

  it('dedupes webhook events by provider + event id and tracks processing', async () => {
    const store = new PgWebhookEventStore(pool, schema);
    const input = { provider: 'stripe', eventId: `evt_${uniqueSuffix()}`, type: 'invoice.paid', payload: { id: 'x', amount: 5 } };
    expect(await store.record(input)).toEqual({ inserted: true, processed: false });
    expect(await store.record(input)).toEqual({ inserted: false, processed: false });
    await store.markFailed('stripe', input.eventId, 'web 503');
    await store.markProcessed('stripe', input.eventId);
    expect(await store.record(input)).toEqual({ inserted: false, processed: true });
    const { rows } = await pool.query<{ attempts: number; last_error: string | null; payload: unknown }>(
      `SELECT attempts, last_error, payload FROM "${schema}".webhook_events WHERE event_id = $1`,
      [input.eventId],
    );
    expect(rows[0]).toEqual({ attempts: 2, last_error: null, payload: { id: 'x', amount: 5 } });
    expect(await store.record({ ...input, provider: 'other' })).toEqual({ inserted: true, processed: false });
  });

  it('stores locale preferences per bot and user', async () => {
    const prefs = new PgChatPreferences(pool, schema);
    expect(await prefs.getLocale('platform', 1)).toBeNull();
    await prefs.setLocale('platform', 1, 1, 'ru', 'anna');
    await prefs.setLocale('platform', 1, 1, 'en');
    await prefs.setLocale('7', 1, 1, 'ru');
    expect(await prefs.getLocale('platform', 1)).toBe('en');
    expect(await prefs.getLocale('7', 1)).toBe('ru');
    const { rows } = await pool.query<{ username: string }>(
      `SELECT username FROM "${schema}".telegram_chats WHERE bot_key = 'platform' AND telegram_user_id = 1`,
    );
    expect(rows[0]?.username).toBe('anna');
    expect(await prefs.forgetBot('7')).toBe(1);
    expect(await prefs.getLocale('7', 1)).toBeNull();
    expect(await prefs.getLocale('platform', 1)).toBe('en');
  });

  it('records the group notice once per bot and chat', async () => {
    const notices = new PgGroupNotices(pool, schema);
    expect(await notices.markOnce('7', -100)).toBe(true);
    expect(await notices.markOnce('7', -100)).toBe(false);
    expect(await notices.markOnce('8', -100)).toBe(true);
    expect(await notices.forgetBot('7')).toBe(1);
    expect(await notices.markOnce('7', -100)).toBe(true);
  });

  it('forgets a Telegram user on every bot', async () => {
    const prefs = new PgChatPreferences(pool, schema);
    await prefs.setLocale('platform', 41, 41, 'ru');
    await prefs.setLocale('7', 41, 41, 'en');
    await prefs.setLocale('7', 42, 42, 'en');
    expect(await prefs.forget([41])).toBe(2);
    expect(await prefs.forget([])).toBe(0);
    expect(await prefs.getLocale('platform', 41)).toBeNull();
    expect(await prefs.getLocale('7', 42)).toBe('en');
  });

  it('purges webhook events older than the retention', async () => {
    const store = new PgWebhookEventStore(pool, schema);
    await store.record({ provider: 'stripe', eventId: 'retention_old', type: 't', payload: {} });
    await store.record({ provider: 'stripe', eventId: 'retention_new', type: 't', payload: {} });
    await pool.query(`UPDATE "${schema}".webhook_events SET received_at = now() - interval '31 days' WHERE event_id = 'retention_old'`);

    expect(await store.purgeOlderThan(30)).toBe(1);
    const { rows } = await pool.query<{ event_id: string }>(`SELECT event_id FROM "${schema}".webhook_events WHERE event_id LIKE 'retention_%' ORDER BY event_id`);
    expect(rows.map((row) => row.event_id)).toEqual(['retention_new']);
    await expect(store.purgeOlderThan(0)).rejects.toThrow('Invalid retention');
  });
});

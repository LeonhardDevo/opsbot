import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { AI_OFFLINE_ENV, e2eOverrides, rewriteDatabaseUrl, type E2EContext } from './services.ts';

const ctx: E2EContext = {
  devDb: 'opsbot',
  e2eDb: 'opsbot_e2e',
  baseURL: 'http://127.0.0.1:8200',
  internalURL: 'http://127.0.0.1:8203',
  workerURL: 'http://127.0.0.1:8201',
  aiURL: 'http://127.0.0.1:8202',
  slug: 'opsbot',
};

describe('rewriteDatabaseUrl', () => {
  it('rewrites every postgres driver prefix and keeps query strings', () => {
    assert.equal(rewriteDatabaseUrl('postgres://app:app@127.0.0.1:5442/opsbot', 'opsbot', 'opsbot_e2e'), 'postgres://app:app@127.0.0.1:5442/opsbot_e2e');
    assert.equal(
      rewriteDatabaseUrl('postgresql+psycopg://app:app@127.0.0.1:5442/opsbot?sslmode=disable', 'opsbot', 'opsbot_e2e'),
      'postgresql+psycopg://app:app@127.0.0.1:5442/opsbot_e2e?sslmode=disable',
    );
  });

  it('leaves other databases and non-postgres URLs alone', () => {
    assert.equal(rewriteDatabaseUrl('postgres://app:app@127.0.0.1:5442/opsbot_test', 'opsbot', 'opsbot_e2e'), null);
    assert.equal(rewriteDatabaseUrl('redis://127.0.0.1:6392/0', 'opsbot', 'opsbot_e2e'), null);
    assert.equal(rewriteDatabaseUrl('http://127.0.0.1:8200/opsbot', 'opsbot', 'opsbot_e2e'), null);
  });
});

describe('e2eOverrides', () => {
  it('web: isolated database, cache prefixes, fake billing and the e2e origin', () => {
    const overrides = e2eOverrides('web', { DB_DATABASE: 'opsbot', DB_URL: '' }, ctx);
    assert.equal(overrides.APP_ENV, 'e2e');
    assert.equal(overrides.DB_DATABASE, 'opsbot_e2e');
    assert.equal(overrides.APP_URL, ctx.baseURL);
    assert.equal(overrides.BILLING_DRIVER, 'fake');
    assert.match(overrides.REDIS_PREFIX!, /e2e/);
    assert.equal(overrides.DB_URL, undefined);
  });

  it('worker: rewrites DATABASE_URL, enables dev routes and keeps Telegram offline', () => {
    const overrides = e2eOverrides('worker', { DATABASE_URL: 'postgres://app:app@127.0.0.1:5442/opsbot', TELEGRAM_BOT_TOKEN: 'real' }, ctx);
    assert.equal(overrides.DATABASE_URL, 'postgres://app:app@127.0.0.1:5442/opsbot_e2e');
    assert.equal(overrides.DEV_ROUTES, '1');
    assert.equal(overrides.TELEGRAM_BOT_TOKEN, '');
    assert.equal(overrides.TELEGRAM_MODE, 'disabled');
    assert.equal(overrides.WEB_URL, ctx.internalURL);
    assert.equal(overrides.WEB_PUBLIC_URL, ctx.baseURL);
    assert.notEqual(overrides.QUEUE_PREFIX, 'bull');
    assert.match(overrides.REDIS_PREFIX!, /^opsbot-e2e:/);
  });

  it('ai: rewrites any postgres URL key and forces offline providers', () => {
    const overrides = e2eOverrides('ai', { RAG_DATABASE_URL: 'postgresql+psycopg://app:app@127.0.0.1:5442/opsbot' }, ctx);
    assert.equal(overrides.RAG_DATABASE_URL, 'postgresql+psycopg://app:app@127.0.0.1:5442/opsbot_e2e');
    for (const [key, value] of Object.entries(AI_OFFLINE_ENV)) assert.equal(overrides[key], value);
    assert.equal(overrides.WEB_URL, ctx.internalURL);
    assert.equal(overrides.WEB_PUBLIC_URL, ctx.baseURL);
  });
});

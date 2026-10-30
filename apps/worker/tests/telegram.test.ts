import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { Writable } from 'node:stream';
import { setTimeout as sleep } from 'node:timers/promises';
import { GrammyError } from 'grammy';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { signRequest } from '../src/clients/signing.js';
import { BotRegistry } from '../src/bot/botRegistry.js';
import { createBotComposer } from '../src/bot/createBot.js';
import { createLogger, createSilentLogger, scrubSecrets } from '../src/logger.js';
import { Metrics } from '../src/metrics.js';
import { MemoryEnqueuer } from '../src/queues/enqueuer.js';
import { assertNoSecrets, findSecret, SecretInPayloadError } from '../src/queues/secrets.js';
import { MemoryRateBuckets, telegramBuckets } from '../src/telegram/buckets.js';
import { FAKE_TOKEN_PATTERN, isFakeToken, tokenBotId } from '../src/telegram/fakeDriver.js';
import { MemoryLockStore } from '../src/telegram/leaderLock.js';
import { PollingSupervisor } from '../src/telegram/polling.js';
import {
  TelegramBotRevokedError,
  TelegramBotUnavailableError,
  TelegramChatBlockedError,
  TelegramRateDelay,
  TelegramSender,
} from '../src/telegram/sender.js';
import { BotVerifier } from '../src/telegram/verify.js';
import { createTestBot, devServer, type TestBot } from './helpers/bot.js';
import { COMPANY_TOKEN, companyCredentials } from './helpers/fakes.js';

const REAL_TOKEN = '123456789:AAHrealLookingTokenValue0123456789abcd';

class FakeBotApi {
  readonly calls: Array<{ token: string; method: string; body: Record<string, unknown> }> = [];
  readonly batches: unknown[][] = [];
  failures = new Map<string, { status: number; description: string; parameters?: Record<string, unknown> }>();
  meId = 123456789;
  private server!: Server;
  root = '';

  async start(): Promise<void> {
    this.server = createServer((req, res) => {
      const chunks: Buffer[] = [];
      req.on('data', (chunk: Buffer) => chunks.push(chunk));
      req.on('end', () => {
        const parts = (req.url ?? '').split('/');
        const method = parts.pop() ?? '';
        const token = (parts.pop() ?? '').replace(/^bot/, '');
        const raw = Buffer.concat(chunks).toString('utf8');
        const body = raw ? (JSON.parse(raw) as Record<string, unknown>) : {};
        this.calls.push({ token, method, body });
        const failure = this.failures.get(method);
        if (failure) {
          res.writeHead(failure.status, { 'content-type': 'application/json' });
          res.end(JSON.stringify({ ok: false, error_code: failure.status, description: failure.description, parameters: failure.parameters }));
          return;
        }
        const reply = (result: unknown) => {
          res.writeHead(200, { 'content-type': 'application/json' });
          res.end(JSON.stringify({ ok: true, result }));
        };
        if (method === 'getMe') return reply({ id: this.meId, is_bot: true, first_name: 'Clean Line', username: 'clean_line_bot', can_join_groups: true });
        if (method === 'sendMessage') return reply({ message_id: 55, date: 1, chat: { id: body.chat_id, type: 'private' }, text: body.text });
        if (method === 'getUpdates') {
          const batch = this.batches.shift();
          if (batch) return reply(batch);
          setTimeout(() => reply([]), 30);
          return;
        }
        return reply(true);
      });
    });
    await new Promise<void>((resolve) => this.server.listen(0, '127.0.0.1', resolve));
    this.root = `http://127.0.0.1:${(this.server.address() as AddressInfo).port}`;
  }

  async stop(): Promise<void> {
    this.server.closeAllConnections();
    await new Promise<void>((resolve) => this.server.close(() => resolve()));
  }

  methods(): string[] {
    return this.calls.map((call) => call.method);
  }
}

let api: FakeBotApi;

beforeEach(async () => {
  api = new FakeBotApi();
  await api.start();
});

afterEach(async () => {
  await api.stop();
});

describe('FakeTelegramDriver', () => {
  it('recognises test tokens and derives the bot id from the token', () => {
    expect(FAKE_TOKEN_PATTERN.test(COMPANY_TOKEN)).toBe(true);
    expect(isFakeToken(`123456789:TEST${'x'.repeat(30)}`)).toBe(true);
    expect(isFakeToken(REAL_TOKEN)).toBe(false);
    expect(isFakeToken(undefined)).toBe(true);
    expect(tokenBotId(COMPANY_TOKEN)).toBe(7000000001);
  });

  it('answers getMe with a test identity and captures every call', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials({ username: null })]]) });
    const entry = (await bot.registry.get('7'))!;
    expect(entry.transport).toBe('fake');
    const me = await entry.bot.api.getMe();
    expect(me).toMatchObject({ id: 7000000001, username: 'test_7000000001_bot', can_join_groups: false });
    await entry.bot.api.sendMessage(5, 'hi');
    expect(bot.registry.capture.offlineCalls('7').map((call) => call.method)).toEqual(['getMe', 'sendMessage']);
    expect(bot.registry.capture.offlineCalls('platform')).toEqual([]);
  });
});

describe('BotRegistry', () => {
  let bot: TestBot;
  let now: number;

  beforeEach(() => {
    now = 1_000_000;
    bot = createTestBot({ credentials: new Map([[7, companyCredentials()], [8, companyCredentials({ bot_key: '8', status: 'connecting' })]]) });
  });

  it('builds entries lazily, once per key, from the credentials endpoint', async () => {
    expect(bot.registry.size).toBe(0);
    const [a, b] = await Promise.all([bot.registry.get('7'), bot.registry.get('7')]);
    expect(a).toBe(b);
    expect(bot.web.botCredentials).toHaveBeenCalledTimes(1);
    expect(a).toMatchObject({ key: '7', status: 'active', webhookSecret: 'company-webhook-secret-0123456789', transport: 'fake' });
    expect(await bot.registry.get('99')).toBeNull();
    expect(await bot.registry.get('not-a-key')).toBeNull();
    expect((await bot.registry.get('platform'))?.status).toBe('active');
  });

  it('evicts on demand, after idling and when over capacity; refreshes non-active entries quickly', async () => {
    const limited = new BotRegistry({
      composer: createBotComposer(bot.services),
      credentials: bot.web,
      platform: { token: undefined, username: 'acme_bot', productName: 'Acme Desk', webhookSecret: undefined },
      driver: 'auto',
      apiRoot: 'https://api.telegram.org',
      logger: createSilentLogger(),
      maxEntries: 2,
      idleMs: 1000,
      inactiveTtlMs: 100,
      now: () => now,
    });
    await limited.get('platform');
    await limited.get('7');
    await limited.get('8');
    expect(limited.size).toBe(2);
    expect(limited.peek('platform')).toBeDefined();
    expect(limited.peek('7')).toBeUndefined();
    const before = bot.web.botCredentials.mock.calls.filter((call) => call[0] === 8).length;
    await limited.get('8');
    expect(bot.web.botCredentials.mock.calls.filter((call) => call[0] === 8).length).toBe(before);
    now += 200;
    await limited.get('8');
    expect(bot.web.botCredentials.mock.calls.filter((call) => call[0] === 8).length).toBe(before + 1);
    now += 5000;
    expect(limited.sweep()).toBe(1);
    expect(limited.keys()).toEqual(['platform']);
    await limited.get('7');
    expect(limited.evict('7')).toBe(true);
    expect(limited.evict('7')).toBe(false);
  });

  it('serves updates through the shared handlers with ctx.botKey', async () => {
    const entry = (await bot.registry.get('7'))!;
    const outbound = await entry.handleUpdateCaptured({
      update_id: 1,
      message: { message_id: 1, date: 1, chat: { id: 5, type: 'private', first_name: 'A' }, from: { id: 5, is_bot: false, first_name: 'A' }, text: '/privacy', entities: [{ type: 'bot_command', offset: 0, length: 8 }] },
    });
    expect(outbound).toHaveLength(1);
    expect(outbound[0]).toMatchObject({ bot_key: '7', method: 'sendMessage' });
  });
});

describe('TelegramSender', () => {
  function senderFor(bot: TestBot, buckets = new MemoryRateBuckets(() => 0)) {
    return new TelegramSender({
      registry: bot.registry,
      buckets,
      web: bot.web,
      rates: { botPerS: 25, chatPerS: 1 },
      logger: createSilentLogger(),
      metrics: new Metrics({ defaultMetrics: false }),
    });
  }

  it('sends through the registry entry and counts sends by bot class', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials()]]) });
    const sender = senderFor(bot);
    expect(await sender.send({ botKey: '7', chatId: 5, html: '<b>Hi</b>' })).toMatchObject({ message_id: expect.any(Number) });
    expect(bot.registry.capture.offlineCalls('7')[0]).toMatchObject({ method: 'sendMessage', payload: { chat_id: 5, text: '<b>Hi</b>', parse_mode: 'HTML' } });
    expect(bot.registry.capture.offlineCalls('7')[0]?.message_id).toEqual(expect.any(Number));
    await expect(sender.send({ botKey: '99', chatId: 5, html: 'x' })).rejects.toBeInstanceOf(TelegramBotUnavailableError);
  });

  it('delays when a bucket is empty instead of sending', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials()]]) });
    let now = 0;
    const sender = senderFor(bot, new MemoryRateBuckets(() => now));
    await sender.send({ botKey: '7', chatId: 5, html: 'one' });
    const delayed = await sender.send({ botKey: '7', chatId: 5, html: 'two' }).catch((error: unknown) => error);
    expect(delayed).toBeInstanceOf(TelegramRateDelay);
    expect((delayed as TelegramRateDelay).waitMs).toBe(1000);
    expect(await sender.send({ botKey: '7', chatId: 6, html: 'other chat' })).toBeDefined();
    now = 1000;
    expect(await sender.send({ botKey: '7', chatId: 5, html: 'three' })).toBeDefined();
    expect(bot.registry.capture.offlineCalls('7')).toHaveLength(3);
  });

  it('maps 429, 401 and 403 from Telegram', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials({ token: REAL_TOKEN, tg_bot_id: 123456789 })]]), apiRoot: api.root });
    const sender = senderFor(bot);
    api.failures.set('sendMessage', { status: 429, description: 'Too Many Requests: retry after 7', parameters: { retry_after: 7 } });
    const limited = await sender.send({ botKey: '7', chatId: 5, html: 'x' }).catch((error: unknown) => error);
    expect(limited).toMatchObject({ waitMs: 7000 });
    api.failures.set('sendMessage', { status: 403, description: 'Forbidden: bot was blocked by the user' });
    await expect(sender.send({ botKey: '7', chatId: 6, html: 'x' })).rejects.toBeInstanceOf(TelegramChatBlockedError);
    expect(bot.web.reportChatBlocked).toHaveBeenCalledWith('7', 6, true, undefined);
    api.failures.set('sendMessage', { status: 401, description: 'Unauthorized' });
    await expect(sender.send({ botKey: '7', chatId: 7, html: 'x' })).rejects.toBeInstanceOf(TelegramBotRevokedError);
    expect(bot.web.reportBotStatus).toHaveBeenCalledWith(7, { status: 'revoked', error_code: 'revoked' }, undefined);
    expect(bot.registry.peek('7')).toBeUndefined();
  });

  it('falls back to plain text when Telegram cannot parse the HTML', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials({ token: REAL_TOKEN, tg_bot_id: 123456789 })]]), apiRoot: api.root });
    const sender = senderFor(bot);
    const entry = (await bot.registry.get('7'))!;
    const original = entry.bot.api.sendMessage.bind(entry.bot.api);
    let first = true;
    vi.spyOn(entry.bot.api, 'sendMessage').mockImplementation(async (chatId, text, other) => {
      if (first) {
        first = false;
        throw new GrammyError('Bad Request', { ok: false, error_code: 400, description: "Bad Request: can't parse entities" }, 'sendMessage', {});
      }
      return original(chatId, text, other);
    });
    await sender.send({ botKey: '7', chatId: 5, html: '<b>bold &amp; more</b>' });
    expect(api.calls.at(-1)?.body).toMatchObject({ text: 'bold & more' });
    expect(api.calls.at(-1)?.body.parse_mode).toBeUndefined();
  });
});

describe('PollingSupervisor', () => {
  it('runs one loop per bot across replicas and enqueues per-bot jobs', async () => {
    const credentials = new Map([[7, companyCredentials({ token: REAL_TOKEN, tg_bot_id: 123456789 })]]);
    const locks = new MemoryLockStore();
    const enqueuer = new MemoryEnqueuer();
    const make = (replicaId: string) => {
      const bot = createTestBot({ credentials, apiRoot: api.root, platformToken: undefined });
      return new PollingSupervisor({
        bots: bot.registry,
        activeBots: async () => ['7'],
        enqueuer,
        locks,
        replicaId,
        logger: createSilentLogger(),
        pollTimeoutS: 0,
        resyncMs: 60_000,
      });
    };
    const a = make('replica-a');
    const b = make('replica-b');
    api.batches.push([{ update_id: 41, message: { message_id: 1, date: 1, chat: { id: 5, type: 'private' }, text: 'hi' } }]);
    await a.start();
    await b.start();
    expect(a.running).toEqual(['7']);
    expect(b.running).toEqual([]);
    expect(locks.holder('poll-lock:7')).toBe('replica-a');
    for (let attempt = 0; attempt < 50 && enqueuer.jobs.length === 0; attempt++) await sleep(20);
    expect(enqueuer.jobs[0]).toMatchObject({ queue: 'telegram-updates', jobId: 'tg-update-7-41', data: { bot_key: '7', update: { update_id: 41 } } });
    expect(api.methods()).toContain('deleteWebhook');
    await a.stop();
    expect(locks.holder('poll-lock:7')).toBeNull();
    await b.sync();
    expect(b.running).toEqual(['7']);
    await b.stop();
  });

  it('never polls fake-transport bots', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials()]]) });
    const supervisor = new PollingSupervisor({
      bots: bot.registry,
      activeBots: async () => ['7'],
      enqueuer: new MemoryEnqueuer(),
      locks: new MemoryLockStore(),
      replicaId: 'r',
      logger: createSilentLogger(),
    });
    await supervisor.start();
    expect(supervisor.running).toEqual([]);
    await supervisor.stop();
  });
});

describe('bot verification (ADR-0003)', () => {
  function verifier(bot: TestBot, mode: 'polling' | 'webhook' | 'disabled', publicUrl?: string) {
    return new BotVerifier({
      registry: bot.registry,
      web: bot.web,
      mode,
      publicUrl,
      commands: bot.services.modules.commands,
      translator: bot.services.translator,
      logger: createSilentLogger(),
    });
  }

  it('verifies a test token on the fake transport and registers commands', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials({ status: 'connecting', username: null })]]) });
    expect(await verifier(bot, 'webhook', 'https://worker.example.com').verify(7)).toEqual({
      ok: true,
      tg_bot_id: 7000000001,
      username: 'test_7000000001_bot',
      first_name: 'Test Bot 7000000001',
      can_join_groups: false,
      mode: 'fake',
    });
    const methods = bot.registry.capture.offlineCalls('7').map((call) => call.method);
    expect(methods).toEqual(['getMe', 'setMyCommands', 'setMyCommands']);
    expect(bot.registry.capture.offlineCalls('7')[2]?.payload).toMatchObject({ language_code: 'ru' });
  });

  it('maps getMe failures, mismatching ids and webhook errors', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials({ token: REAL_TOKEN, tg_bot_id: 123456789, status: 'connecting' })]]), apiRoot: api.root });
    expect(await verifier(bot, 'webhook', 'https://worker.example.com').verify(7)).toMatchObject({ ok: true, mode: 'webhook', username: 'clean_line_bot', can_join_groups: true });
    expect(api.calls.find((call) => call.method === 'setWebhook')?.body).toMatchObject({
      url: 'https://worker.example.com/webhooks/telegram/7',
      secret_token: 'company-webhook-secret-0123456789',
      allowed_updates: ['message', 'callback_query', 'my_chat_member'],
    });
    api.meId = 999;
    expect(await verifier(bot, 'webhook', 'https://worker.example.com').verify(7)).toEqual({ ok: false, error_code: 'token_mismatch' });
    api.meId = 123456789;
    api.failures.set('setWebhook', { status: 400, description: 'Bad Request: bad webhook: HTTPS url must be provided' });
    expect(await verifier(bot, 'webhook', 'https://worker.example.com').verify(7)).toEqual({ ok: false, error_code: 'webhook_failed' });
    expect(await verifier(bot, 'webhook', undefined).verify(7)).toEqual({ ok: false, error_code: 'webhook_failed' });
    api.failures.set('getMe', { status: 401, description: 'Unauthorized' });
    expect(await verifier(bot, 'polling').verify(7)).toEqual({ ok: false, error_code: 'invalid_token' });
    bot.credentials.delete(7);
    expect(await verifier(bot, 'polling').verify(7)).toEqual({ ok: false, error_code: 'revoked' });
  });

  it('reports network errors when Telegram is unreachable', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials({ token: REAL_TOKEN, tg_bot_id: 123456789 })]]), apiRoot: 'http://127.0.0.1:1' });
    expect(await verifier(bot, 'polling').verify(7)).toEqual({ ok: false, error_code: 'network' });
  });

  it('serves POST /internal/v1/telegram/bots/{id}/verify only to signed web requests', async () => {
    const bot = createTestBot({ credentials: new Map([[7, companyCredentials()]]) });
    const secret = 'internal-test-secret-0123456789';
    const server = await devServer(bot.registry, { internalBots: { secret, verifier: verifier(bot, 'webhook', 'https://w.example.com') } });
    try {
      const path = '/internal/v1/telegram/bots/7/verify';
      const signed = (service: string, key = secret) => ({
        'x-service': service,
        'x-signature': signRequest({ secret: key, method: 'POST', pathWithQuery: path, body: '{}' }),
        'content-type': 'application/json',
      });
      const ok = await server.inject({ method: 'POST', url: path, payload: '{}', headers: signed('web') });
      expect(ok.statusCode).toBe(200);
      expect(ok.json()).toMatchObject({ ok: true, mode: 'fake', tg_bot_id: 7000000001 });
      expect(ok.body).not.toContain(companyCredentials().token);
      expect((await server.inject({ method: 'POST', url: path, payload: '{}', headers: signed('ai') })).statusCode).toBe(403);
      expect((await server.inject({ method: 'POST', url: path, payload: '{}', headers: signed('web', 'wrong-secret-0123456789') })).statusCode).toBe(401);
      expect((await server.inject({ method: 'POST', url: path, payload: '{}' })).statusCode).toBe(401);
    } finally {
      await server.close();
    }
  });
});

describe('secret custody', () => {
  it('rejects job payloads that carry secrets', () => {
    expect(findSecret({ a: { Token: 'x' } })).toBe('$.a.Token');
    expect(findSecret({ list: [{ text: COMPANY_TOKEN }] })).toBe('$.list[0].text');
    expect(findSecret({ text: 'hello', bot_key: '7', webhook: 'x' })).toBeNull();
    expect(() => assertNoSecrets({ webhook_secret: 'x' })).toThrow(SecretInPayloadError);
    const enqueuer = new MemoryEnqueuer();
    return expect(enqueuer.add('telegram', 'telegram.notify', { password: 'p' }, 'id-1')).rejects.toBeInstanceOf(SecretInPayloadError);
  });

  it('allows tokens in external ingress queues', async () => {
    const enqueuer = new MemoryEnqueuer();
    await expect(enqueuer.add('telegram-updates', 'telegram.update', { bot_key: '7', update: { update_id: 1, message: { text: COMPANY_TOKEN } } }, 'tg-update-7-1')).resolves.toMatchObject({ created: true });
  });

  it('never logs bot tokens, webhook secrets or token-bearing URLs', () => {
    const lines: string[] = [];
    const destination = new Writable({
      write(chunk: Buffer, _encoding, callback) {
        lines.push(chunk.toString('utf8'));
        callback();
      },
    });
    const logger = createLogger({ level: 'info', destination });
    logger.info({ credentials: { token: COMPANY_TOKEN, webhook_secret: 'company-webhook-secret' } }, 'credentials loaded');
    logger.error({ err: new Error(`request to https://api.telegram.org/bot${REAL_TOKEN}/getMe failed`) }, 'telegram failed');
    const output = lines.join('\n');
    expect(output).not.toContain(COMPANY_TOKEN);
    expect(output).not.toContain(REAL_TOKEN);
    expect(output).not.toContain('company-webhook-secret');
    expect(output).toContain('bot<redacted>');
    expect(scrubSecrets(`token ${REAL_TOKEN}`)).toBe('token <redacted>');
  });
});

describe('MemoryRateBuckets', () => {
  it('computes the wait for the slowest bucket without consuming tokens', async () => {
    let now = 0;
    const buckets = new MemoryRateBuckets(() => now);
    const specs = telegramBuckets('7', 1, { botPerS: 2, chatPerS: 1 });
    expect(await buckets.take(specs)).toBe(0);
    expect(await buckets.take(specs)).toBe(1000);
    now = 500;
    expect(await buckets.take(specs)).toBe(500);
    now = 1000;
    expect(await buckets.take(specs)).toBe(0);
  });
});

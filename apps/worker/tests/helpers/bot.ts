import type { FastifyInstance } from 'fastify';
import { AccountResolver } from '../../src/bot/accounts.js';
import { BotRegistry } from '../../src/bot/botRegistry.js';
import { createBotComposer } from '../../src/bot/createBot.js';
import { createModuleRegistry } from '../../src/bot/moduleRegistry.js';
import { defaultModules } from '../../src/bot/modules/index.js';
import { MemoryActionStore, MemoryCache, MemoryRateLimiter } from '../../src/bot/stores.js';
import type { BotModule, BotServices } from '../../src/bot/types.js';
import type { BotCredentials, TelegramContext } from '../../src/clients/web.js';
import { MemoryChatPreferences, MemoryGroupNotices } from '../../src/db/telegramChats.js';
import { MemoryWebhookEventStore } from '../../src/db/webhookEvents.js';
import { buildServer } from '../../src/http/server.js';
import { Translator } from '../../src/i18n/index.js';
import { createSilentLogger } from '../../src/logger.js';
import { Metrics } from '../../src/metrics.js';
import { MemoryEnqueuer } from '../../src/queues/enqueuer.js';
import type { OutboundCall } from '../../src/telegram/fakeDriver.js';
import type { BotVerifier } from '../../src/telegram/verify.js';
import { fakeAi, fakeWeb } from './fakes.js';

export const PRODUCT = { name: 'Acme Desk', slug: 'acme' };

export interface TestBot {
  registry: BotRegistry;
  services: BotServices;
  web: ReturnType<typeof fakeWeb>;
  ai: ReturnType<typeof fakeAi>;
  actions: MemoryActionStore;
  state: MemoryCache;
  preferences: MemoryChatPreferences;
  groupNotices: MemoryGroupNotices;
  contexts: Map<number, TelegramContext>;
  credentials: Map<number, BotCredentials>;
}

export interface TestBotOptions {
  modules?: BotModule[];
  rateLimit?: number;
  webPublicUrl?: string;
  contexts?: Map<number, TelegramContext>;
  credentials?: Map<number, BotCredentials>;
  platformToken?: string;
  driver?: 'auto' | 'fake';
  apiRoot?: string;
}

export function createTestBot(options: TestBotOptions = {}): TestBot {
  const contexts = options.contexts ?? new Map<number, TelegramContext>();
  const credentials = options.credentials ?? new Map<number, BotCredentials>();
  const web = fakeWeb(contexts, credentials);
  const ai = fakeAi();
  const translator = new Translator(PRODUCT.name);
  const modules = createModuleRegistry(options.modules ?? defaultModules(), translator);
  const actions = new MemoryActionStore();
  const state = new MemoryCache();
  const preferences = new MemoryChatPreferences();
  const groupNotices = new MemoryGroupNotices();
  const services: BotServices = {
    settings: {
      productName: PRODUCT.name,
      botUsername: 'acme_bot',
      webPublicUrl: options.webPublicUrl ?? 'http://127.0.0.1:8200',
      rateLimit: options.rateLimit ?? 20,
      rateWindowS: 60,
      actionTtlS: 3600,
      flowTtlS: 900,
      replyTtlS: 1800,
    },
    translator,
    web,
    ai,
    actions,
    state,
    rateLimiter: new MemoryRateLimiter(),
    preferences,
    groupNotices,
    accounts: new AccountResolver(web, new MemoryCache(), 60),
    logger: createSilentLogger(),
    modules,
  };
  const registry = new BotRegistry({
    composer: createBotComposer(services),
    credentials: web,
    platform: { token: options.platformToken, username: 'acme_bot', productName: PRODUCT.name, webhookSecret: 'platform-secret' },
    driver: options.driver ?? 'auto',
    apiRoot: options.apiRoot ?? 'https://api.telegram.org',
    logger: createSilentLogger(),
  });
  return { registry, services, web, ai, actions, state, preferences, groupNotices, contexts, credentials };
}

export async function devServer(
  registry: BotRegistry,
  options: { enqueuer?: MemoryEnqueuer; webhookEnabled?: boolean; internalBots?: { secret: string; verifier: BotVerifier } } = {},
): Promise<FastifyInstance> {
  return buildServer({
    logger: createSilentLogger(),
    metrics: new Metrics({ defaultMetrics: false }),
    enqueuer: options.enqueuer ?? new MemoryEnqueuer(),
    webhookEvents: new MemoryWebhookEventStore(),
    product: PRODUCT,
    stripeWebhookSecret: 'whsec_test_local',
    telegramWebhook: { enabled: options.webhookEnabled ?? false, bots: registry },
    devRegistry: registry,
    internalBots: options.internalBots ?? null,
    dashboard: null,
    readiness: {},
  });
}

export async function sendDev(server: FastifyInstance, body: Record<string, unknown>): Promise<OutboundCall[]> {
  const response = await server.inject({ method: 'POST', url: '/dev/telegram/update', payload: body });
  if (response.statusCode !== 200) throw new Error(`dev route ${response.statusCode}: ${response.body}`);
  return response.json<{ outbound: OutboundCall[] }>().outbound;
}

export type SentMessage = {
  text: string;
  chat_id?: number;
  reply_markup?: { inline_keyboard?: Array<Array<Record<string, string>>>; force_reply?: boolean };
};

export function sentMessages(outbound: OutboundCall[]): SentMessage[] {
  return outbound.filter((call) => call.method === 'sendMessage').map((call) => call.payload as SentMessage);
}

export function buttons(message: SentMessage | undefined): Array<Record<string, string>> {
  return (message?.reply_markup?.inline_keyboard ?? []).flat();
}

export function callbackOf(message: SentMessage | undefined, text: string): string {
  const button = buttons(message).find((item) => item.text === text);
  if (!button?.callback_data) throw new Error(`no button "${text}" in ${JSON.stringify(buttons(message))}`);
  return button.callback_data;
}

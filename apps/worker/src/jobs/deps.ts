import type { BotEntry } from '../bot/botRegistry.js';
import type { AccountResolver } from '../bot/accounts.js';
import type { ActionStore, KeyValueCache } from '../bot/stores.js';
import type { ModuleRegistry } from '../bot/types.js';
import type { AiApi } from '../clients/ai.js';
import type { WebApi } from '../clients/web.js';
import type { ChatPreferences, GroupNotices } from '../db/telegramChats.js';
import type { WebhookEventStore } from '../db/webhookEvents.js';
import type { Translator } from '../i18n/index.js';
import type { Mailer } from '../mail/mailer.js';
import type { JobEnqueuer } from '../queues/enqueuer.js';
import type { TelegramSenderApi } from '../telegram/sender.js';

export interface BotLookup {
  get(botKey: string, requestId?: string): Promise<BotEntry | null>;
  evict(botKey: string): boolean;
}

export interface BotLifecycle {
  stopPolling(botKey: string): Promise<void>;
  publishEvict(botKey: string): Promise<void>;
}

export interface JobDeps {
  web: WebApi;
  ai: AiApi;
  mailer: Mailer;
  sender: TelegramSenderApi;
  sentMarkers: KeyValueCache;
  extracts: KeyValueCache;
  webhookEvents: WebhookEventStore;
  bots: BotLookup;
  lifecycle: BotLifecycle;
  modules: ModuleRegistry;
  telegramChats: Pick<ChatPreferences, 'forget'>;
  translator: Translator;
  actions: ActionStore;
  accounts: AccountResolver;
  preferences: ChatPreferences;
  groupNotices: GroupNotices;
  enqueuer: JobEnqueuer;
  settings: {
    productSlug: string;
    mailFrom: string;
    notificationActionTtlS: number;
    maxFileBytes: number;
    extractTtlS: number;
    extractConcurrency: number;
    reembedBatchDocuments: number;
  };
}

export const SENT_MARKER_TTL_S = 7 * 24 * 3600;

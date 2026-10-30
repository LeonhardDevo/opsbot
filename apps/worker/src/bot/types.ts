import type { Composer, Context } from 'grammy';
import type { AiApi } from '../clients/ai.js';
import type { TelegramContext, WebApi } from '../clients/web.js';
import type { ChatPreferences, GroupNotices } from '../db/telegramChats.js';
import type { Locale, MessageTree, Params, Translator } from '../i18n/index.js';
import type { Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import type { AccountResolver } from './accounts.js';
import type { ActionStore, KeyValueCache, RateLimiter, StoredAction } from './stores.js';

export interface BotSettings {
  productName: string;
  botUsername: string;
  webPublicUrl: string;
  rateLimit: number;
  rateWindowS: number;
  actionTtlS: number;
  flowTtlS: number;
  replyTtlS: number;
}

export interface BotHealth {
  unauthorized(botKey: string, requestId?: string): Promise<void>;
  seen(botKey: string, requestId?: string): Promise<void>;
}

export interface BotServices {
  settings: BotSettings;
  translator: Translator;
  web: WebApi;
  ai: AiApi;
  actions: ActionStore;
  state: KeyValueCache;
  rateLimiter: RateLimiter;
  preferences: ChatPreferences;
  groupNotices: GroupNotices;
  accounts: AccountResolver;
  logger: Logger;
  metrics?: Metrics;
  modules: ModuleRegistry;
  health?: BotHealth;
}

export interface BotFlavor {
  services: BotServices;
  botKey: string;
  requestId: string;
  locale: Locale;
  t: (key: string, params?: Params) => string;
  account: () => Promise<TelegramContext | null>;
  setLocale: (locale: Locale) => void;
  log: Logger;
}

export type BotContext = Context & BotFlavor;

export type ButtonCallback = string | { type: string; payload: Record<string, unknown> };

export interface NotifyButton {
  text: string;
  url?: string | null;
  callback?: ButtonCallback | null;
}

export interface NotificationHelpers {
  locale: Locale;
  t: (key: string, params?: Params) => string;
  plural: (key: string, count: number, params?: Params) => string;
  escape: (value: string) => string;
}

export type NotificationTemplate = (
  params: Record<string, unknown>,
  helpers: NotificationHelpers,
) => { html: string; buttons?: NotifyButton[] };

export type ActionHandler = (ctx: BotContext, action: StoredAction) => Promise<void>;

export interface BotCommand {
  command: string;
  descriptionKey: string;
  visible?: (account: TelegramContext | null) => boolean;
}

export interface BotModule {
  name: string;
  commands?: BotCommand[];
  messages?: Partial<Record<Locale, MessageTree>>;
  register?: (composer: Composer<BotContext>) => void;
  actions?: Record<string, ActionHandler>;
  notifications?: Record<string, NotificationTemplate>;
}

export interface ModuleRegistry {
  readonly modules: readonly BotModule[];
  readonly commands: readonly BotCommand[];
  action(type: string): ActionHandler | undefined;
  notification(name: string): NotificationTemplate | undefined;
}

import { GrammyError, HttpError, type Api } from 'grammy';
import { ALLOWED_UPDATES, type BotRegistry } from '../bot/botRegistry.js';
import type { BotCommand } from '../bot/types.js';
import type { WebApi } from '../clients/web.js';
import type { Translator } from '../i18n/index.js';
import { errorFields, type Logger } from '../logger.js';
import { fakeBotInfo, tokenBotId } from './fakeDriver.js';

export type VerifyErrorCode = 'invalid_token' | 'token_mismatch' | 'network' | 'webhook_failed' | 'revoked';
export type BotMode = 'webhook' | 'polling' | 'fake';

export type VerifyResult =
  | { ok: true; tg_bot_id: number; username: string | null; first_name: string | null; can_join_groups: boolean | null; mode: BotMode }
  | { ok: false; error_code: VerifyErrorCode };

export interface BotVerifierDeps {
  registry: BotRegistry;
  web: Pick<WebApi, 'botCredentials'>;
  mode: 'polling' | 'webhook' | 'disabled';
  publicUrl: string | undefined;
  commands: readonly BotCommand[];
  translator: Translator;
  logger: Logger;
  startPolling?: (botKey: string) => Promise<unknown>;
  publishEvict?: (botKey: string) => Promise<void>;
  timeoutMs?: number;
}

function classify(error: unknown): VerifyErrorCode {
  if (error instanceof GrammyError && (error.error_code === 401 || error.error_code === 404)) return 'invalid_token';
  if (error instanceof HttpError) return 'network';
  return 'network';
}

export async function registerCommands(
  api: Pick<Api, 'setMyCommands'>,
  commands: readonly BotCommand[],
  translator: Translator,
): Promise<void> {
  for (const locale of ['en', 'ru'] as const) {
    const list = commands.map((command) => ({
      command: command.command,
      description: translator.t(locale, command.descriptionKey).slice(0, 256),
    }));
    await api.setMyCommands(list, locale === 'en' ? {} : { language_code: locale });
  }
}

export class BotVerifier {
  constructor(private readonly deps: BotVerifierDeps) {}

  async verify(botId: number, requestId?: string): Promise<VerifyResult> {
    const credentials = await this.deps.web.botCredentials(botId, requestId);
    if (!credentials) return { ok: false, error_code: 'revoked' };
    const botKey = credentials.bot_key;
    this.deps.registry.evict(botKey);
    const transport = this.deps.registry.transportFor(credentials.token);
    const info =
      transport === 'fake'
        ? fakeBotInfo(tokenBotId(credentials.token) ?? credentials.tg_bot_id)
        : fakeBotInfo(credentials.tg_bot_id, credentials.username);
    const bot = this.deps.registry.createBot(botKey, credentials.token, info, transport);
    const signal = AbortSignal.timeout(this.deps.timeoutMs ?? 10_000) as unknown as Parameters<typeof bot.api.getMe>[0];
    let me;
    try {
      me = await bot.api.getMe(signal);
    } catch (error) {
      const code = classify(error);
      this.deps.logger.info({ botKey, code, ...errorFields(error) }, 'bot verification failed');
      return { ok: false, error_code: code };
    }
    if (me.id !== credentials.tg_bot_id) return { ok: false, error_code: 'token_mismatch' };
    bot.botInfo = me;

    let mode: BotMode;
    if (transport === 'fake') {
      mode = 'fake';
    } else if (this.deps.mode === 'webhook') {
      if (!this.deps.publicUrl || !credentials.webhook_secret) return { ok: false, error_code: 'webhook_failed' };
      try {
        await bot.api.setWebhook(`${this.deps.publicUrl}/webhooks/telegram/${botKey}`, {
          secret_token: credentials.webhook_secret,
          allowed_updates: [...ALLOWED_UPDATES],
          drop_pending_updates: false,
        });
      } catch (error) {
        this.deps.logger.warn({ botKey, ...errorFields(error) }, 'telegram setWebhook failed');
        return { ok: false, error_code: 'webhook_failed' };
      }
      mode = 'webhook';
    } else {
      mode = 'polling';
    }

    await registerCommands(bot.api, this.deps.commands, this.deps.translator).catch((error: unknown) => {
      this.deps.logger.warn({ botKey, ...errorFields(error) }, 'telegram setMyCommands failed');
    });
    this.deps.registry.evict(botKey);
    await this.deps.publishEvict?.(botKey).catch(() => undefined);
    if (mode === 'polling' && this.deps.mode === 'polling') {
      await this.deps.startPolling?.(botKey).catch((error: unknown) => {
        this.deps.logger.warn({ botKey, ...errorFields(error) }, 'starting polling failed');
      });
    }
    this.deps.logger.info({ botKey, mode, username: me.username }, 'company bot verified');
    return {
      ok: true,
      tg_bot_id: me.id,
      username: me.username ?? null,
      first_name: me.first_name ?? null,
      can_join_groups: me.can_join_groups ?? null,
      mode,
    };
  }
}

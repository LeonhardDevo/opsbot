import { pino, type DestinationStream, type Logger, type LoggerOptions } from 'pino';

export type { Logger } from 'pino';

export const REDACT_PATHS = [
  'req.headers.authorization',
  'req.headers["x-signature"]',
  'req.headers["stripe-signature"]',
  'req.headers["x-telegram-bot-api-secret-token"]',
  'headers.authorization',
  'headers["x-signature"]',
  '*.password',
  '*.token',
  '*.bot_token',
  '*.webhook_secret',
  '*.webhookSecret',
  '*.secret',
  '*.api_key',
  '*.apiKey',
  'config.internal.secret',
  'config.internal.keys.web',
  'config.internal.keys.ai',
  'config.telegram.token',
  'config.telegram.webhookSecret',
  'config.stripe.webhookSecret',
  'config.stripe.secretKey',
  'config.smtp.password',
  'config.dashboard.password',
];

export interface LoggerSettings {
  level: LoggerOptions['level'];
  pretty?: boolean;
  service?: string;
  destination?: DestinationStream;
}

export function createLogger(settings: LoggerSettings): Logger {
  const options: LoggerOptions = {
    level: settings.level ?? 'info',
    base: { service: settings.service ?? 'worker' },
    redact: { paths: REDACT_PATHS, censor: '[redacted]' },
    serializers: { err: serializeError },
    timestamp: pino.stdTimeFunctions.isoTime,
    formatters: {
      level: (label) => ({ level: label }),
    },
  };
  if (settings.pretty) {
    return pino({
      ...options,
      transport: { target: 'pino-pretty', options: { colorize: true, translateTime: 'SYS:HH:MM:ss.l' } },
    });
  }
  return settings.destination ? pino(options, settings.destination) : pino(options);
}

const BOT_TOKEN_IN_TEXT = /bot\d+:[A-Za-z0-9_-]+/g;
const BARE_TOKEN_IN_TEXT = /\b\d{5,16}:[A-Za-z0-9_-]{30,}\b/g;

export function scrubSecrets(value: string): string {
  return value.replace(BOT_TOKEN_IN_TEXT, 'bot<redacted>').replace(BARE_TOKEN_IN_TEXT, '<redacted>');
}

function scrubDeep(value: unknown, depth = 0): unknown {
  if (depth > 6) return value;
  if (typeof value === 'string') return scrubSecrets(value);
  if (Array.isArray(value)) return value.map((item) => scrubDeep(item, depth + 1));
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value as Record<string, unknown>).map(([key, item]) => [key, scrubDeep(item, depth + 1)]));
  }
  return value;
}

export function serializeError(error: unknown): unknown {
  const serialized = error instanceof Error ? pino.stdSerializers.err(error) : error;
  return scrubDeep(serialized);
}

export function createSilentLogger(): Logger {
  return pino({ level: 'silent' });
}

export function errorFields(error: unknown): { err: Error } {
  return { err: error instanceof Error ? error : new Error(typeof error === 'string' ? error : JSON.stringify(error)) };
}

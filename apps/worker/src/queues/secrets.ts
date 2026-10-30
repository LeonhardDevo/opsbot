const SECRET_KEYS = new Set(['token', 'bot_token', 'api_key', 'secret', 'webhook_secret', 'password']);
const BOT_TOKEN_VALUE = /^\d{5,16}:[A-Za-z0-9_-]{30,}$/;

export class SecretInPayloadError extends Error {
  readonly retryable = false;

  constructor(public readonly path: string) {
    super(`payload contains a secret at "${path}"`);
    this.name = 'SecretInPayloadError';
  }
}

export function findSecret(value: unknown, path = '$', depth = 0): string | null {
  if (depth > 32) return null;
  if (typeof value === 'string') return BOT_TOKEN_VALUE.test(value.trim()) ? path : null;
  if (Array.isArray(value)) {
    for (const [index, item] of value.entries()) {
      const found = findSecret(item, `${path}[${index}]`, depth + 1);
      if (found) return found;
    }
    return null;
  }
  if (value !== null && typeof value === 'object') {
    for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
      if (SECRET_KEYS.has(key.toLowerCase())) return `${path}.${key}`;
      const found = findSecret(item, `${path}.${key}`, depth + 1);
      if (found) return found;
    }
  }
  return null;
}

export function assertNoSecrets(value: unknown): void {
  const found = findSecret(value);
  if (found) throw new SecretInPayloadError(found);
}

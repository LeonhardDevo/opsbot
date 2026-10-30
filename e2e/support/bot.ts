import type { APIRequestContext } from '@playwright/test';

export interface CapturedCall {
  bot_key?: string;
  method: string;
  payload: Record<string, unknown>;
  message_id?: number;
}

export interface InlineButton {
  text: string;
  callback_data?: string;
  url?: string;
}

export interface TelegramPerson {
  id: number;
  firstName: string;
  username: string;
  languageCode: 'en' | 'ru';
}

const MESSAGE_METHODS = new Set(['sendMessage', 'editMessageText', 'sendPhoto', 'sendDocument']);

export function messages(calls: readonly CapturedCall[]): CapturedCall[] {
  return calls.filter((call) => MESSAGE_METHODS.has(call.method));
}

export function textOf(calls: readonly CapturedCall[]): string {
  return messages(calls)
    .map((call) => String(call.payload.text ?? call.payload.caption ?? ''))
    .join('\n');
}

export function buttonsOf(calls: readonly CapturedCall[]): InlineButton[] {
  const buttons: InlineButton[] = [];
  for (const call of calls) {
    const markup = call.payload.reply_markup as { inline_keyboard?: InlineButton[][] } | undefined;
    for (const row of markup?.inline_keyboard ?? []) buttons.push(...row);
  }
  return buttons;
}

export function findButton(calls: readonly CapturedCall[], label: RegExp): InlineButton | undefined {
  return buttonsOf(calls).find((button) => label.test(button.text));
}

export function linksOf(calls: readonly CapturedCall[]): string[] {
  const found: string[] = [];
  for (const call of messages(calls)) {
    const text = String(call.payload.text ?? '');
    for (const match of text.matchAll(/href="([^"]+)"/g)) found.push(match[1]!.replace(/&amp;/g, '&'));
  }
  for (const button of buttonsOf(calls)) if (button.url) found.push(button.url);
  return found;
}

export function chatIdOf(call: CapturedCall): number | null {
  const value = call.payload.chat_id;
  return typeof value === 'number' ? value : typeof value === 'string' && /^-?\d+$/.test(value) ? Number(value) : null;
}

let personSeq = 0;

export function newTelegramPerson(locale: 'en' | 'ru', firstName: string): TelegramPerson {
  personSeq += 1;
  const id = 700_000_000 + ((Date.now() % 100_000_000) * 10 + personSeq) % 1_200_000_000;
  return { id, firstName, username: `e2e_${id.toString(36)}`, languageCode: locale };
}

export class DevTelegram {
  private readonly request: APIRequestContext;
  private readonly workerURL: string;
  readonly botKey: string;

  constructor(request: APIRequestContext, workerURL: string, botKey: string) {
    this.request = request;
    this.workerURL = workerURL;
    this.botKey = botKey;
  }

  private async post(person: TelegramPerson, body: Record<string, unknown>): Promise<CapturedCall[]> {
    const response = await this.request.post(`${this.workerURL}/dev/telegram/update`, {
      data: {
        bot_key: this.botKey,
        from: { id: person.id, first_name: person.firstName, username: person.username, language_code: person.languageCode },
        ...body,
      },
    });
    if (!response.ok()) throw new Error(`dev/telegram/update → HTTP ${response.status()}: ${await response.text()}`);
    return ((await response.json()) as { outbound: CapturedCall[] }).outbound;
  }

  say(person: TelegramPerson, text: string, extra: Record<string, unknown> = {}): Promise<CapturedCall[]> {
    return this.post(person, { text, ...extra });
  }

  press(person: TelegramPerson, button: InlineButton | undefined): Promise<CapturedCall[]> {
    if (!button?.callback_data) throw new Error(`button without callback data: ${JSON.stringify(button)}`);
    return this.post(person, { callback_data: button.callback_data });
  }

  async outbox(): Promise<CapturedCall[]> {
    const response = await this.request.get(`${this.workerURL}/dev/telegram/outbox?bot_key=${encodeURIComponent(this.botKey)}`);
    if (!response.ok()) throw new Error(`dev/telegram/outbox → HTTP ${response.status()}`);
    return ((await response.json()) as { outbound: CapturedCall[] }).outbound;
  }

  async outboxFor(person: TelegramPerson): Promise<CapturedCall[]> {
    return messages(await this.outbox()).filter((call) => chatIdOf(call) === person.id);
  }
}

import { describe, expect, it } from 'vitest';
import en from '../src/i18n/en.json' with { type: 'json' };
import ru from '../src/i18n/ru.json' with { type: 'json' };
import { Translator, localeFromLanguageCode, type MessageTree } from '../src/i18n/index.js';

function leafKeys(tree: MessageTree, prefix = ''): string[] {
  return Object.entries(tree).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key;
    if (typeof value === 'string') return [path];
    if (Object.keys(value).every((k) => ['one', 'few', 'many', 'other'].includes(k)) && 'one' in value) return [path];
    return leafKeys(value, path);
  });
}

function placeholders(text: string): string[] {
  return [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1] ?? '').sort();
}

describe('bot i18n', () => {
  it('has the same keys in ru and en', () => {
    expect(leafKeys(ru as MessageTree).sort()).toEqual(leafKeys(en as MessageTree).sort());
  });

  it('uses the same placeholders in both locales', () => {
    const translator = new Translator('X');
    for (const key of leafKeys(en)) {
      const enText = translator.t('en', key, { count: 5 });
      const ruText = translator.t('ru', key, { count: 5 });
      expect(placeholders(ruText), key).toEqual(placeholders(enText));
    }
  });

  it('never hard-codes a product name in texts', () => {
    const translator = new Translator('X');
    for (const key of leafKeys(en)) {
      for (const locale of ['en', 'ru'] as const) {
        expect(translator.t(locale, key, { count: 2 }), key).not.toMatch(/opsbot|payflow|scampause/i);
      }
    }
  });

  it('contains no emoji or pictographs', () => {
    const raw = JSON.stringify(en) + JSON.stringify(ru);
    expect(raw).not.toMatch(/\p{Extended_Pictographic}/u);
  });

  it('fills {product} and params', () => {
    const translator = new Translator('Acme Desk');
    expect(translator.t('en', 'start.welcome_unlinked')).toContain('Acme Desk → <b>Settings → Integrations</b>');
    expect(translator.t('en', 'start.linked', { name: 'Ann' })).toContain('linked as <b>Ann</b>');
    expect(translator.t('ru', 'opsbot.privacy.text')).toContain('Ваши данные в Acme Desk');
  });

  it('uses correct Russian plural forms', () => {
    const translator = new Translator('X');
    expect(translator.plural('ru', 'assistant.sources', 1)).toBe('<b>1 источник:</b>');
    expect(translator.plural('ru', 'assistant.sources', 2)).toBe('<b>2 источника:</b>');
    expect(translator.plural('ru', 'assistant.sources', 5)).toBe('<b>5 источников:</b>');
    expect(translator.plural('ru', 'assistant.sources', 21)).toBe('<b>21 источник:</b>');
    expect(translator.plural('ru', 'assistant.sources', 11)).toBe('<b>11 источников:</b>');
    expect(translator.plural('en', 'assistant.sources', 1)).toBe('<b>Source:</b>');
    expect(translator.plural('en', 'assistant.sources', 3)).toBe('<b>Sources (3):</b>');
    expect(translator.plural('ru', 'opsbot.hours', 22)).toBe('22 часа');
    expect(translator.plural('ru', 'opsbot.tasks.title', 5)).toBe('<b>У вас 5 открытых задач</b>');
  });

  it('selects one/few/many for every Russian plural key at 1, 2, 5, 11, 21, 22', () => {
    const translator = new Translator('X');
    const expected: Record<number, 'one' | 'few' | 'many'> = { 1: 'one', 2: 'few', 5: 'many', 11: 'many', 21: 'one', 22: 'few' };
    const resolve = (tree: unknown, key: string): unknown => key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown>)[part], tree);
    const pluralKeys = leafKeys(ru as MessageTree).filter((key) => typeof resolve(ru, key) === 'object');
    expect(pluralKeys.length).toBeGreaterThan(5);
    for (const key of pluralKeys) {
      const forms = resolve(ru, key) as Record<string, string>;
      for (const [count, form] of Object.entries(expected)) {
        const template = forms[form] ?? '';
        expect(template, `${key}.${form}`).not.toBe('');
        expect(translator.plural('ru', key, Number(count)), `${key} ${count}`).toBe(template.replace('{count}', count));
      }
      expect(translator.plural('en', key, 1), key).not.toBe(translator.plural('en', key, 2));
    }
  });

  it('falls back to English, then to the key', () => {
    const translator = new Translator('X', { ru: { only: { ru: 'да' } }, en: { only: { en: 'yes' } } });
    expect(translator.t('ru', 'only.en')).toBe('yes');
    expect(translator.t('en', 'missing.key')).toBe('missing.key');
  });

  it('lets modules extend catalogs', () => {
    const translator = new Translator('X');
    translator.extend({ en: { incidents: { resolved: 'Incident {number} resolved' } }, ru: { incidents: { resolved: 'Инцидент {number} закрыт' } } });
    expect(translator.t('ru', 'incidents.resolved', { number: 'A-1' })).toBe('Инцидент A-1 закрыт');
    expect(translator.t('en', 'common.error')).toContain('Something went wrong');
  });

  it('maps Telegram language codes', () => {
    expect(localeFromLanguageCode('ru')).toBe('ru');
    expect(localeFromLanguageCode('ru-RU')).toBe('ru');
    expect(localeFromLanguageCode('en-GB')).toBe('en');
    expect(localeFromLanguageCode('de')).toBeNull();
    expect(localeFromLanguageCode(undefined)).toBeNull();
  });
});

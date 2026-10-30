import type { ChatResponse, Citation } from '../clients/ai.js';
import type { AiQueryLogged, ProposedAction } from '../clients/web.js';
import type { Locale, Params, Translator } from '../i18n/index.js';
import { escapeHtml, isPublicHttpUrl, markdownToTelegramHtml, toTelegramHtml } from './format.js';
import type { NotifyButton } from './types.js';

export const MAX_ACTION_BUTTONS = 4;
export const HIGH_CONFIDENCE = 0.7;

export type ConfidenceBand = 'high' | 'medium' | 'low';

export function confidenceBand(confidence: number, minConfidence: number, refused: boolean): ConfidenceBand {
  if (refused || confidence < minConfidence) return 'low';
  return confidence >= HIGH_CONFIDENCE ? 'high' : 'medium';
}

export function renderAnswerHtml(
  response: ChatResponse,
  logged: AiQueryLogged | null,
  locale: Locale,
  translator: Translator,
  options: { webPublicUrl: string; minConfidence: number },
): string {
  const t = (key: string, params?: Params): string => translator.t(locale, key, params);
  let body = response.answer_html.trim() !== '' ? toTelegramHtml(response.answer_html) : markdownToTelegramHtml(response.answer_markdown);
  if (body === '') body = response.refused ? t('assistant.refused_hint') : t('assistant.empty_answer');
  if (response.refused) return body;
  const parts = [body];
  if (response.answer_kind === 'document') {
    const sources = renderSources(response.citations, logged, locale, translator, options.webPublicUrl);
    if (sources !== '') parts.push(sources);
  }
  const band = response.confidence_band ?? confidenceBand(response.confidence, options.minConfidence, response.refused);
  if (band === 'medium' && response.answer_kind === 'document') parts.push(t('assistant.check_source'));
  return parts.join('\n\n');
}

export function renderSources(
  citations: readonly Citation[],
  logged: AiQueryLogged | null,
  locale: Locale,
  translator: Translator,
  webPublicUrl: string,
): string {
  if (citations.length === 0) return '';
  const urls = new Map<number, string>();
  for (const item of logged?.citations ?? []) {
    if (item.url) urls.set(item.index, item.url);
  }
  const lines = [translator.plural(locale, 'assistant.sources', citations.length)];
  citations.forEach((citation, index) => {
    const url = absoluteUrl(urls.get(index + 1) ?? null, webPublicUrl);
    const title = escapeHtml(citation.title);
    const label = url && /^https?:\/\//i.test(url) ? `<a href="${escapeHtml(url)}">${title}</a>` : title;
    const page = typeof citation.page === 'number' ? `, ${translator.t(locale, 'assistant.page', { page: citation.page })}` : '';
    lines.push(`[${index + 1}] ${label}${page}`);
  });
  return lines.join('\n');
}

function absoluteUrl(url: string | null, base: string): string | null {
  if (!url) return null;
  if (/^https?:\/\//i.test(url)) return url;
  if (url.startsWith('/')) return `${base}${url}`;
  return null;
}

const PREVIEW_TYPES = new Set(['create_incident', 'create_task']);

export function actionButtons(actions: readonly ProposedAction[]): NotifyButton[] {
  const buttons: NotifyButton[] = [];
  for (const action of actions) {
    if (buttons.length >= MAX_ACTION_BUTTONS) break;
    if (action.status !== 'proposed') continue;
    if (action.url) {
      if (isPublicHttpUrl(action.url)) buttons.push({ text: action.label, url: action.url });
      continue;
    }
    if (action.type === 'open_source') continue;
    buttons.push({
      text: action.label,
      callback: {
        type: 'opsbot.action',
        payload: { action_id: action.id, step: PREVIEW_TYPES.has(action.type) ? 'preview' : 'confirm' },
      },
    });
  }
  return buttons;
}

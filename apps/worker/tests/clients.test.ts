import { createServer, type IncomingMessage, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { AiClient, AiDocumentError } from '../src/clients/ai.js';
import { UpstreamHttpError, UpstreamNetworkError, isRetryableError } from '../src/clients/errors.js';
import { buildPathWithQuery, internalFetch } from '../src/clients/internalFetch.js';
import { verifySignature } from '../src/clients/signing.js';
import { WebClient } from '../src/clients/web.js';

const SECRET = 'shared-test-secret-0123456789';

interface Captured {
  method: string;
  url: string;
  headers: IncomingMessage['headers'];
  body: string;
  signatureOk: boolean;
}

type Responder = (request: Captured) => { status: number; body?: unknown };

let server: Server;
let baseUrl: string;
let captured: Captured[] = [];
let responder: Responder = () => ({ status: 200, body: { ok: true } });

beforeAll(async () => {
  server = createServer((req, res) => {
    const chunks: Buffer[] = [];
    req.on('data', (chunk: Buffer) => chunks.push(chunk));
    req.on('end', () => {
      const body = Buffer.concat(chunks).toString('utf8');
      const request: Captured = {
        method: req.method ?? '',
        url: req.url ?? '',
        headers: req.headers,
        body,
        signatureOk: verifySignature({
          secret: SECRET,
          header: req.headers['x-signature'] as string | undefined,
          method: req.method ?? '',
          pathWithQuery: req.url ?? '',
          body,
        }).ok,
      };
      captured.push(request);
      const reply = responder(request);
      res.writeHead(reply.status, { 'content-type': 'application/json' });
      res.end(reply.body === undefined ? '' : JSON.stringify(reply.body));
    });
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  baseUrl = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
});

afterAll(async () => {
  await new Promise<void>((resolve) => server.close(() => resolve()));
});

beforeEach(() => {
  captured = [];
  responder = () => ({ status: 200, body: { ok: true } });
});

const target = () => ({ service: 'web' as const, baseUrl, secret: SECRET, timeoutMs: 2000 });

describe('internalFetch', () => {
  it('sends signed requests that the receiver can verify', async () => {
    const response = await internalFetch(target(), {
      method: 'POST',
      path: '/internal/v1/deliveries',
      body: { reference: { type: 'incident', id: 5 }, status: 'sent', text: 'Привет' },
      requestId: '11111111-1111-4111-8111-111111111111',
    });
    expect(response.status).toBe(200);
    const request = captured[0]!;
    expect(request.signatureOk).toBe(true);
    expect(request.headers['x-service']).toBe('worker');
    expect(request.headers['x-request-id']).toBe('11111111-1111-4111-8111-111111111111');
    expect(request.headers['content-type']).toBe('application/json');
    expect(JSON.parse(request.body)).toMatchObject({ text: 'Привет' });
  });

  it('signs GET requests including the query string', async () => {
    await internalFetch(target(), { method: 'GET', path: '/internal/v1/telegram/context', query: { telegram_user_id: 42, skip: undefined } });
    expect(captured[0]!.url).toBe('/internal/v1/telegram/context?telegram_user_id=42');
    expect(captured[0]!.signatureOk).toBe(true);
    expect(captured[0]!.body).toBe('');
  });

  it('classifies HTTP and network failures', async () => {
    responder = () => ({ status: 503, body: { message: 'maintenance' } });
    const serverError = await internalFetch(target(), { method: 'GET', path: '/x' }).catch((error: unknown) => error);
    expect(serverError).toBeInstanceOf(UpstreamHttpError);
    expect((serverError as UpstreamHttpError).message).toContain('maintenance');
    expect(isRetryableError(serverError)).toBe(true);

    responder = () => ({ status: 422, body: { message: 'invalid' } });
    const clientError = await internalFetch(target(), { method: 'GET', path: '/x' }).catch((error: unknown) => error);
    expect(isRetryableError(clientError)).toBe(false);

    const network = await internalFetch({ ...target(), baseUrl: 'http://127.0.0.1:1' }, { method: 'GET', path: '/x' }).catch(
      (error: unknown) => error,
    );
    expect(network).toBeInstanceOf(UpstreamNetworkError);
    expect(isRetryableError(network)).toBe(true);
  });

  it('builds paths safely', () => {
    expect(buildPathWithQuery('/a', { q: 'a b', n: 1, t: true })).toBe('/a?q=a+b&n=1&t=true');
    expect(() => buildPathWithQuery('a')).toThrow();
  });
});

describe('WebClient', () => {
  it('returns null context for unlinked users and parses linked context', async () => {
    const web = new WebClient(target());
    responder = () => ({ status: 404, body: { message: 'not linked' } });
    expect(await web.telegramContext({ botKey: '7', telegramUserId: 1 })).toBeNull();
    expect(captured.at(-1)!.url).toBe('/internal/v1/telegram/context?bot_key=7&telegram_user_id=1');

    responder = () => ({
      status: 200,
      body: { user: { id: 1, name: 'Ann', locale: 'ru' }, workspace: { id: 2, name: 'W', plan: 'pro' }, role: 'owner', permissions: ['a'] },
    });
    const context = await web.telegramContext({ workspaceId: 2, userId: 1 });
    expect(context).toMatchObject({
      user: { id: 1, locale: 'ru', kind: 'web' },
      role: 'owner',
      permissions: ['a'],
      department_ids: [],
      privileged: false,
      notice: null,
      limits: { ai_queries_exhausted: false },
      settings: { min_confidence: 0.4, ai_external_allowed: false },
      workspace: { timezone: 'UTC', subscription_state: 'ok' },
    });
    expect(captured.at(-1)!.url).toBe('/internal/v1/telegram/context?workspace_id=2&user_id=1');
  });

  it('maps link responses', async () => {
    const web = new WebClient(target());
    const input = { bot_key: '7', token: 'emp_12345678', telegram_user_id: 5, chat_id: 5, username: null, language_code: 'ru' };
    responder = () => ({
      status: 200,
      body: { user: { id: 1, name: 'Ann', locale: 'en' }, workspace: { id: 2, name: 'W' }, notice_required: true },
    });
    expect(await web.linkTelegram(input)).toEqual({ ok: true, userName: 'Ann', workspaceName: 'W', locale: 'en', noticeRequired: true });
    expect(JSON.parse(captured.at(-1)!.body)).toMatchObject({ bot_key: '7', token: 'emp_12345678' });
    responder = () => ({ status: 422, body: { error: 'wrong_bot' } });
    expect(await web.linkTelegram(input)).toEqual({ ok: false, reason: 'wrong_bot' });
    responder = () => ({ status: 410 });
    expect(await web.linkTelegram(input)).toEqual({ ok: false, reason: 'invalid_token' });
    responder = () => ({ status: 409 });
    expect(await web.linkTelegram(input)).toEqual({ ok: false, reason: 'conflict' });
    responder = () => ({ status: 500 });
    await expect(web.linkTelegram(input)).rejects.toBeInstanceOf(UpstreamHttpError);
  });

  it('posts deliveries, stripe events, document status and query logs to the contract paths', async () => {
    const web = new WebClient(target());
    expect(await web.ping()).toBe(true);
    await web.reportDelivery({ reference: { type: 'x', id: 1 }, channel: 'email', status: 'sent' });
    await web.forwardStripeEvent({ id: 'evt_1', type: 'checkout.session.completed' });
    await web.reportDocumentStatus(9, { status: 'failed', upload_rev: 2, error_code: 'ocr_failed' });
    responder = () => ({
      status: 201,
      body: {
        id: 77,
        actions: [{ id: 'a1b2c3', type: 'create_incident', label: 'Create incident', url: null, status: 'proposed' }],
        citations: [{ index: 1, url: 'https://app.example.com/s/doc/abc' }],
      },
    });
    const logged = await web.logAiQuery({
      workspace_id: 1,
      user_id: 2,
      channel: 'telegram',
      bot_key: '7',
      conversation_id: 'tg-7-5',
      question: 'q',
      answer: 'a',
      answer_kind: 'document',
      refused: false,
      refusal_reason: null,
      confidence: 0.5,
      citations: [],
      tools_used: [],
      latency_ms: 10,
      provider: 'mock',
      model: 'm',
      role_at_time: 'employee',
      department_ids_at_time: [1],
      disclaimers: [],
      actions: [],
    });
    expect(logged).toMatchObject({ id: 77, actions: [{ id: 'a1b2c3', type: 'create_incident' }], citations: [{ index: 1 }] });
    expect(captured.map((request) => `${request.method} ${request.url}`)).toEqual([
      'GET /internal/v1/ping',
      'POST /internal/v1/deliveries',
      'POST /internal/v1/stripe/events',
      'POST /internal/v1/documents/9/status',
      'POST /internal/v1/ai-queries',
    ]);
    expect(captured.every((request) => request.signatureOk)).toBe(true);
  });

  it('reads bot credentials and treats 410 as gone', async () => {
    const web = new WebClient(target());
    responder = () => ({
      status: 200,
      body: { bot_key: '7', tg_bot_id: 123456, username: 'acme_bot', token: '123456:TEST' + 'x'.repeat(30), webhook_secret: 's', status: 'active' },
    });
    expect(await web.botCredentials(7)).toMatchObject({ bot_key: '7', tg_bot_id: 123456 });
    responder = () => ({ status: 410 });
    expect(await web.botCredentials(7)).toBeNull();
    expect(captured.at(-1)!.url).toBe('/internal/v1/telegram/bots/7/credentials');
  });

  it('calls the opsbot domain endpoints', async () => {
    const web = new WebClient(target());
    responder = (request) => {
      if (request.url.startsWith('/internal/v1/opsbot/queue')) return { status: 403, body: { message: 'forbidden' } };
      if (request.url.startsWith('/internal/v1/opsbot/tasks')) {
        return { status: 200, body: { tasks: [{ id: 1, display_number: 'T-1', title: 'Mop', status: 'todo', overdue: false }] } };
      }
      return { status: 200, body: { items: [], ok: true, message_html: 'ok' } };
    };
    expect(await web.opsbotTasks({ workspace_id: 1, user_id: 2, status: 'open' })).toHaveLength(1);
    expect(await web.opsbotHistory('7', 5)).toEqual([]);
    expect(await web.opsbotQueue('7', 5)).toBeNull();
    expect((await web.escalateLastQuestion('7', 5)).message_html).toBe('ok');
    await web.tick('reminders/dispatch');
    expect(captured.map((request) => request.url)).toEqual([
      '/internal/v1/opsbot/tasks?workspace_id=1&user_id=2&status=open&limit=10',
      '/internal/v1/opsbot/history?bot_key=7&telegram_user_id=5&limit=10',
      '/internal/v1/opsbot/queue?bot_key=7&telegram_user_id=5',
      '/internal/v1/opsbot/questions/escalate-last',
      '/internal/v1/opsbot/reminders/dispatch',
    ]);
  });
});

describe('AiClient', () => {
  const ai = () => new AiClient({ ...target(), service: 'ai' }, { chatMs: 2000, ingestMs: 2000, extractMs: 2000 });

  it('calls /v1/chat with the domain context and fills response defaults', async () => {
    responder = () => ({ status: 200, body: { answer_markdown: 'Hi', citations: [{ document_id: 1, title: 'Doc' }] } });
    const response = await ai().chat({
      workspace_id: 1,
      user: { id: 1, role: 'employee', locale: 'en', permissions: [], department_ids: [1], privileged: false },
      channel: 'telegram',
      message: 'hello',
      context: { today: '2026-10-22', timezone: 'Europe/Moscow', min_confidence: 0.4, ai_external_allowed: false },
    });
    expect(response).toMatchObject({ answer_markdown: 'Hi', answer_html: '', refused: false, actions: [], confidence: 0, answer_kind: 'document' });
    expect(captured[0]!.url).toBe('/v1/chat');
    expect(JSON.parse(captured[0]!.body)).toMatchObject({ context: { today: '2026-10-22', min_confidence: 0.4 } });
  });

  it('treats a missing document as already deleted', async () => {
    responder = () => ({ status: 404 });
    await expect(ai().deleteDocument(5, 1)).resolves.toBeUndefined();
    expect(captured[0]!.url).toBe('/v1/documents/5?workspace_id=1');
    expect(captured[0]!.method).toBe('DELETE');
  });

  it('extracts, indexes, patches metadata and re-embeds via the contract paths', async () => {
    responder = () => ({ status: 200, body: { pages: [{ page: 1, text: 'Hi' }], pages_total: 1, ocr_used: false } });
    const extraction = await ai().extractDocument(7, { data: Buffer.from('%PDF-1.4'), mime: 'application/pdf', filename: 'a.pdf', maxPages: 30 });
    expect(extraction.pages_total).toBe(1);
    expect(captured[0]!.url).toBe('/v1/documents/7/extract?mime=application%2Fpdf&filename=a.pdf&max_pages=30');
    expect(captured[0]!.signatureOk).toBe(true);
    responder = () => ({ status: 200, body: { pages: 1, chunks: 3 } });
    expect(await ai().indexDocument(7, Buffer.from([1, 2, 3]))).toMatchObject({ chunks: 3 });
    expect(captured[1]!.headers['content-encoding']).toBe('gzip');
    responder = () => ({ status: 200, body: { updated_chunks: 4 } });
    const patch = {
      workspace_id: 1,
      title: 'T',
      department_ids: [],
      roles: [],
      tags: [],
      version: null,
      effective_date: null,
      approval_status: 'approved' as const,
      archived: false,
    };
    expect(await ai().patchMetadata(7, patch)).toBe(4);
    responder = () => ({ status: 200, body: { chunks: 5, documents: 2, next_cursor: 9 } });
    expect(await ai().reembedBatch(1, 0, 20, false)).toMatchObject({ next_cursor: 9 });
    expect(captured.map((request) => `${request.method} ${request.url.split('?')[0]}`)).toEqual([
      'POST /v1/documents/7/extract',
      'POST /v1/documents/7/index',
      'PATCH /v1/documents/7/metadata',
      'POST /v1/workspaces/1/reembed',
    ]);
  });

  it('maps document error codes', async () => {
    responder = () => ({ status: 422, body: { error: 'too_many_pages', message: 'too many' } });
    const error = await ai()
      .extractDocument(7, { data: Buffer.from('x'), mime: null, filename: null, maxPages: 1 })
      .catch((caught: unknown) => caught);
    expect(error).toBeInstanceOf(AiDocumentError);
    expect((error as AiDocumentError).code).toBe('too_many_pages');
    responder = () => ({ status: 409, body: { error: 'stale_revision' } });
    await expect(ai().indexDocument(7, Buffer.from('x'))).rejects.toMatchObject({ code: 'stale_revision' });
    responder = () => ({ status: 404 });
    expect(await ai().patchMetadata(7, { workspace_id: 1, title: 'T', department_ids: [], roles: [], tags: [], version: null, effective_date: null, approval_status: 'draft', archived: false })).toBeNull();
  });

  it('purges a user and prunes memory via the contract paths', async () => {
    responder = () => ({ status: 404 });
    await expect(ai().purgeUser(9)).resolves.toBeUndefined();
    responder = () => ({ status: 200, body: { pruned: 3 } });
    await ai().pruneMemory({ default_days: 180, workspaces: [{ workspace_id: 2, days: 7 }] });
    expect(captured.map((request) => `${request.method} ${request.url}`)).toEqual(['DELETE /v1/users/9', 'POST /v1/privacy/prune-memory']);
  });
});

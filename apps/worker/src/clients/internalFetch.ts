import { randomUUID } from 'node:crypto';
import { REQUEST_ID_HEADER, SERVICE_HEADER, SIGNATURE_HEADER, signRequest, type ServiceName } from './signing.js';
import { UpstreamHttpError, UpstreamNetworkError } from './errors.js';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
export type QueryValue = string | number | boolean | null | undefined;

export interface InternalTarget {
  service: ServiceName;
  baseUrl: string;
  secret: string;
  timeoutMs: number;
  fetch?: typeof fetch;
}

export interface InternalRequest {
  method: HttpMethod;
  path: string;
  query?: Record<string, QueryValue>;
  body?: unknown;
  rawBody?: { data: Uint8Array; contentType: string; contentEncoding?: string };
  requestId?: string;
  timeoutMs?: number;
  signal?: AbortSignal;
}

export interface InternalResponse<T> {
  status: number;
  data: T;
  requestId: string;
}

export interface BinaryResponse {
  status: number;
  data: Buffer;
  contentType: string | null;
  filename: string | null;
  requestId: string;
}

interface Prepared {
  url: string;
  init: RequestInit;
  requestId: string;
  method: HttpMethod;
}

function prepare(target: InternalTarget, request: InternalRequest, accept: string): Prepared {
  const method = request.method;
  const pathWithQuery = buildPathWithQuery(request.path, request.query);
  const requestId = request.requestId ?? randomUUID();
  const headers: Record<string, string> = { Accept: accept, [SERVICE_HEADER]: 'worker', [REQUEST_ID_HEADER]: requestId };
  let body: string | Uint8Array | undefined;
  if (request.rawBody) {
    body = request.rawBody.data;
    headers['Content-Type'] = request.rawBody.contentType;
    if (request.rawBody.contentEncoding) headers['Content-Encoding'] = request.rawBody.contentEncoding;
  } else if (request.body !== undefined) {
    body = JSON.stringify(request.body);
    headers['Content-Type'] = 'application/json';
  }
  headers[SIGNATURE_HEADER] = signRequest({ secret: target.secret, method, pathWithQuery, body: body ?? '' });
  const timeout = AbortSignal.timeout(request.timeoutMs ?? target.timeoutMs);
  const signal = request.signal ? AbortSignal.any([timeout, request.signal]) : timeout;
  return {
    url: `${target.baseUrl}${pathWithQuery}`,
    init: { method, headers, body: body === undefined || body.length === 0 ? undefined : (body as RequestInit['body']), signal },
    requestId,
    method,
  };
}

async function send(target: InternalTarget, request: InternalRequest, prepared: Prepared): Promise<Response> {
  const doFetch = target.fetch ?? fetch;
  try {
    return await doFetch(prepared.url, prepared.init);
  } catch (error) {
    throw new UpstreamNetworkError(target.service, prepared.method, request.path, error);
  }
}

export async function internalFetch<T = unknown>(target: InternalTarget, request: InternalRequest): Promise<InternalResponse<T>> {
  const prepared = prepare(target, request, 'application/json');
  const response = await send(target, request, prepared);
  const data = await readBody(response);
  if (!response.ok) {
    throw new UpstreamHttpError(target.service, prepared.method, request.path, response.status, data);
  }
  return { status: response.status, data: data as T, requestId: prepared.requestId };
}

export async function internalFetchBinary(target: InternalTarget, request: InternalRequest, maxBytes: number): Promise<BinaryResponse> {
  const prepared = prepare(target, request, '*/*');
  const response = await send(target, request, prepared);
  if (!response.ok) {
    throw new UpstreamHttpError(target.service, prepared.method, request.path, response.status, await readBody(response));
  }
  const declared = Number(response.headers.get('content-length') ?? '0');
  if (declared > maxBytes) {
    throw new UpstreamHttpError(target.service, prepared.method, request.path, 413, { message: 'file too large' });
  }
  const data = Buffer.from(await response.arrayBuffer());
  if (data.byteLength > maxBytes) {
    throw new UpstreamHttpError(target.service, prepared.method, request.path, 413, { message: 'file too large' });
  }
  return {
    status: response.status,
    data,
    contentType: response.headers.get('content-type'),
    filename: filenameFromDisposition(response.headers.get('content-disposition')),
    requestId: prepared.requestId,
  };
}

export function filenameFromDisposition(value: string | null): string | null {
  if (!value) return null;
  let plain: string | null = null;
  for (const part of value.split(';')) {
    const [rawKey, ...rest] = part.trim().split('=');
    const key = (rawKey ?? '').toLowerCase();
    const raw = rest.join('=');
    if (key === 'filename*' && raw.includes("''")) {
      try {
        return decodeURIComponent(raw.split("''")[1] ?? '');
      } catch {
        continue;
      }
    }
    if (key === 'filename') plain = raw.replace(/^"|"$/g, '');
  }
  return plain;
}

export function buildPathWithQuery(path: string, query?: Record<string, QueryValue>): string {
  if (!path.startsWith('/')) throw new Error(`Internal path must start with "/": ${path}`);
  if (!query) return path;
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null) continue;
    params.append(key, String(value));
  }
  const qs = params.toString();
  return qs === '' ? path : `${path}?${qs}`;
}

async function readBody(response: Response): Promise<unknown> {
  const text = await response.text().catch(() => '');
  if (text === '') return null;
  const contentType = response.headers.get('content-type') ?? '';
  if (contentType.includes('json')) {
    try {
      return JSON.parse(text) as unknown;
    } catch {
      return text;
    }
  }
  return text;
}

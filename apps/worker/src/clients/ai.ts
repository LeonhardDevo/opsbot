import { z } from 'zod';
import { internalFetch, type InternalTarget } from './internalFetch.js';
import { UpstreamHttpError, isNotFound } from './errors.js';

export const citationSchema = z.object({
  document_id: z.number(),
  chunk_id: z.number().nullish(),
  title: z.string(),
  page: z.number().nullish(),
  snippet: z.string().nullish(),
  version: z.string().nullish(),
  effective_date: z.string().nullish(),
  tags: z.array(z.string()).default([]),
});
export type Citation = z.infer<typeof citationSchema>;

export const chatActionSchema = z.object({
  type: z.string().min(1),
  label: z.string().min(1),
  payload: z.record(z.string(), z.unknown()).nullish().transform((value) => value ?? {}),
});
export type ChatAction = z.infer<typeof chatActionSchema>;

export const chatResponseSchema = z.object({
  answer_markdown: z.string().default(''),
  answer_html: z.string().default(''),
  citations: z.array(citationSchema).default([]),
  confidence: z.number().min(0).max(1).default(0),
  confidence_band: z.enum(['high', 'medium', 'low']).nullish(),
  refused: z.boolean().default(false),
  refusal_reason: z.string().nullish(),
  answer_kind: z.enum(['document', 'tool_data']).catch('document').default('document'),
  disclaimers: z.array(z.string()).default([]),
  tools_used: z.array(z.string()).default([]),
  actions: z.array(chatActionSchema).default([]),
  provider: z.string().nullish(),
  model: z.string().nullish(),
  latency_ms: z.number().nullish(),
});
export type ChatResponse = z.infer<typeof chatResponseSchema>;

export interface ChatRequest {
  workspace_id: number;
  user: {
    id: number;
    role: string;
    locale: 'ru' | 'en';
    permissions: string[];
    department_ids: number[];
    privileged: boolean;
  };
  channel: 'telegram' | 'web';
  conversation_id?: string;
  message: string;
  context: { today: string | null; timezone: string; min_confidence: number; ai_external_allowed: boolean };
}

export const extractionSchema = z.object({
  pages: z.array(z.object({ page: z.number().int().positive(), text: z.string() })),
  pages_total: z.number().int().nonnegative(),
  ocr_used: z.boolean().default(false),
  language_guess: z.string().nullish(),
});
export type Extraction = z.infer<typeof extractionSchema>;

export class AiDocumentError extends Error {
  constructor(
    public readonly code: string,
    public readonly status: number,
    message: string,
  ) {
    super(message);
    this.name = 'AiDocumentError';
  }

  get retryable(): boolean {
    return this.status >= 500 || this.status === 429;
  }
}

const indexResultSchema = z.object({ pages: z.number().default(0), chunks: z.number().default(0) }).passthrough();
export type IndexResult = z.infer<typeof indexResultSchema>;

const reembedResultSchema = z
  .object({ chunks: z.number().default(0), documents: z.number().default(0), next_cursor: z.number().nullish() })
  .passthrough();
export type ReembedResult = z.infer<typeof reembedResultSchema>;

export interface MetadataPatch {
  workspace_id: number;
  title: string;
  department_ids: number[];
  roles: string[];
  tags: string[];
  version: string | null;
  effective_date: string | null;
  approval_status: 'draft' | 'approved' | 'archived';
  archived: boolean;
}

export interface AiApi {
  chat(request: ChatRequest, requestId?: string): Promise<ChatResponse>;
  extractDocument(
    documentId: number,
    file: { data: Uint8Array; mime: string | null; filename: string | null; maxPages: number | null },
    requestId?: string,
  ): Promise<Extraction>;
  indexDocument(documentId: number, gzippedBody: Uint8Array, requestId?: string): Promise<IndexResult>;
  patchMetadata(documentId: number, patch: MetadataPatch, requestId?: string): Promise<number | null>;
  deleteDocument(documentId: number, workspaceId: number, requestId?: string): Promise<void>;
  reembedBatch(
    workspaceId: number,
    cursor: number,
    batchDocuments: number,
    aiExternalAllowed: boolean,
    requestId?: string,
  ): Promise<ReembedResult>;
  purgeWorkspace(workspaceId: number, requestId?: string): Promise<void>;
  purgeUser(userId: number, requestId?: string): Promise<void>;
  pruneMemory(retention: MemoryRetention, requestId?: string): Promise<void>;
}

export interface MemoryRetention {
  default_days: number;
  workspaces: { workspace_id: number; days: number }[];
}

function documentError(error: unknown): never {
  if (error instanceof UpstreamHttpError && error.status < 500 && error.status !== 429) {
    const body = (error.body ?? {}) as { error?: unknown; message?: unknown };
    const code = typeof body.error === 'string' ? body.error : `http_${error.status}`;
    const message = typeof body.message === 'string' ? body.message : error.message;
    throw new AiDocumentError(code, error.status, message);
  }
  throw error;
}

export class AiClient implements AiApi {
  constructor(
    private readonly target: InternalTarget,
    private readonly timeouts: { chatMs: number; ingestMs: number; extractMs: number },
  ) {}

  async chat(request: ChatRequest, requestId?: string): Promise<ChatResponse> {
    const response = await internalFetch(this.target, {
      method: 'POST',
      path: '/v1/chat',
      body: request,
      requestId,
      timeoutMs: this.timeouts.chatMs,
    });
    return chatResponseSchema.parse(response.data);
  }

  async extractDocument(
    documentId: number,
    file: { data: Uint8Array; mime: string | null; filename: string | null; maxPages: number | null },
    requestId?: string,
  ): Promise<Extraction> {
    try {
      const response = await internalFetch(this.target, {
        method: 'POST',
        path: `/v1/documents/${documentId}/extract`,
        query: { mime: file.mime, filename: file.filename, max_pages: file.maxPages },
        rawBody: { data: file.data, contentType: 'application/octet-stream' },
        requestId,
        timeoutMs: this.timeouts.extractMs,
      });
      return extractionSchema.parse(response.data);
    } catch (error) {
      return documentError(error);
    }
  }

  async indexDocument(documentId: number, gzippedBody: Uint8Array, requestId?: string): Promise<IndexResult> {
    try {
      const response = await internalFetch(this.target, {
        method: 'POST',
        path: `/v1/documents/${documentId}/index`,
        rawBody: { data: gzippedBody, contentType: 'application/json', contentEncoding: 'gzip' },
        requestId,
        timeoutMs: this.timeouts.ingestMs,
      });
      return indexResultSchema.parse(response.data ?? {});
    } catch (error) {
      return documentError(error);
    }
  }

  async patchMetadata(documentId: number, patch: MetadataPatch, requestId?: string): Promise<number | null> {
    try {
      const response = await internalFetch(this.target, {
        method: 'PATCH',
        path: `/v1/documents/${documentId}/metadata`,
        body: patch,
        requestId,
      });
      return z.object({ updated_chunks: z.number().default(0) }).parse(response.data ?? {}).updated_chunks;
    } catch (error) {
      if (isNotFound(error)) return null;
      return documentError(error);
    }
  }

  async deleteDocument(documentId: number, workspaceId: number, requestId?: string): Promise<void> {
    try {
      await internalFetch(this.target, {
        method: 'DELETE',
        path: `/v1/documents/${documentId}`,
        query: { workspace_id: workspaceId },
        requestId,
      });
    } catch (error) {
      if (isNotFound(error)) return;
      throw error;
    }
  }

  async reembedBatch(
    workspaceId: number,
    cursor: number,
    batchDocuments: number,
    aiExternalAllowed: boolean,
    requestId?: string,
  ): Promise<ReembedResult> {
    const response = await internalFetch(this.target, {
      method: 'POST',
      path: `/v1/workspaces/${workspaceId}/reembed`,
      body: { cursor, batch_documents: batchDocuments, ai_external_allowed: aiExternalAllowed },
      requestId,
      timeoutMs: this.timeouts.ingestMs,
    });
    return reembedResultSchema.parse(response.data ?? {});
  }

  async purgeWorkspace(workspaceId: number, requestId?: string): Promise<void> {
    try {
      await internalFetch(this.target, {
        method: 'DELETE',
        path: `/v1/workspaces/${encodeURIComponent(String(workspaceId))}`,
        requestId,
        timeoutMs: this.timeouts.ingestMs,
      });
    } catch (error) {
      if (isNotFound(error)) return;
      throw error;
    }
  }

  async purgeUser(userId: number, requestId?: string): Promise<void> {
    try {
      await internalFetch(this.target, {
        method: 'DELETE',
        path: `/v1/users/${encodeURIComponent(String(userId))}`,
        requestId,
      });
    } catch (error) {
      if (isNotFound(error)) return;
      throw error;
    }
  }

  async pruneMemory(retention: MemoryRetention, requestId?: string): Promise<void> {
    await internalFetch(this.target, {
      method: 'POST',
      path: '/v1/privacy/prune-memory',
      body: retention,
      requestId,
      timeoutMs: this.timeouts.ingestMs,
    });
  }
}

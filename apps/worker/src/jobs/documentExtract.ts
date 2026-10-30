import { gunzipSync, gzipSync } from 'node:zlib';
import { z } from 'zod';
import { AiDocumentError, type Extraction } from '../clients/ai.js';
import { UpstreamHttpError, UpstreamNetworkError, isNotFound } from '../clients/errors.js';
import { QUEUES, JOB_NAMES } from '../queues/names.js';
import { DEFAULT_JOB_OPTIONS, EXTRACT_JOB_OPTIONS, extractJobId, indexJobId } from '../queues/defaults.js';
import type { JobDeps } from './deps.js';
import { parseOutboxJob, permanent, withGiveUp, type JobContext, type JobHandler } from './types.js';

export const PERMANENT_EXTRACT_CODES = new Set(['too_many_pages', 'unsupported_type', 'empty_text', 'ocr_failed', 'file_missing']);

export const documentMetadataSchema = z.object({
  department_ids: z.array(z.number().int().positive()).default([]),
  roles: z.array(z.string()).default([]),
  tags: z.array(z.string()).default([]),
  version: z.string().nullish(),
  effective_date: z.string().nullish(),
  approval_status: z.enum(['draft', 'approved', 'archived']).default('draft'),
  language: z.enum(['ru', 'en']).nullish(),
});

export const documentJobSchema = z.object({
  document_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
  upload_rev: z.number().int().nonnegative().default(1),
  title: z.string().min(1).max(500),
  mime: z.string().max(255).nullish(),
  size_bytes: z.number().int().nonnegative().nullish(),
  limits: z.object({ max_pages_per_document: z.number().int().positive().nullish() }).default({}),
  metadata: documentMetadataSchema.default({ department_ids: [], roles: [], tags: [], approval_status: 'draft' }),
  ai_external_allowed: z.boolean().default(false),
});
export type DocumentJob = z.infer<typeof documentJobSchema>;

export function extractCacheKey(documentId: number, uploadRev: number): string {
  return `extract:${documentId}:${uploadRev}`;
}

export function encodeExtraction(extraction: Extraction): string {
  return gzipSync(Buffer.from(JSON.stringify(extraction), 'utf8')).toString('base64');
}

export function decodeExtraction(value: string): Extraction | null {
  try {
    return JSON.parse(gunzipSync(Buffer.from(value, 'base64')).toString('utf8')) as Extraction;
  } catch {
    return null;
  }
}

export class Semaphore {
  private active = 0;
  private readonly waiting: Array<() => void> = [];

  constructor(private readonly limit: number) {}

  async run<T>(fn: () => Promise<T>): Promise<T> {
    if (this.active >= this.limit) await new Promise<void>((resolve) => this.waiting.push(resolve));
    this.active++;
    try {
      return await fn();
    } finally {
      this.active--;
      this.waiting.shift()?.();
    }
  }
}

export function documentErrorCode(error: unknown): string {
  if (error instanceof AiDocumentError) return error.code;
  if (isNotFound(error)) return 'file_missing';
  if (error instanceof UpstreamHttpError && error.status === 413) return 'unsupported_type';
  if (error instanceof UpstreamNetworkError && /timeout|aborted/i.test(String((error.cause as Error | undefined)?.name ?? error.message))) {
    return 'timeout';
  }
  return 'index_failed';
}

function isPermanentCode(code: string): boolean {
  return PERMANENT_EXTRACT_CODES.has(code);
}

export async function reportFailure(deps: JobDeps, job: DocumentJob, error: unknown, ctx: JobContext): Promise<void> {
  const code = documentErrorCode(error);
  await deps.web.reportDocumentStatus(
    job.document_id,
    { status: 'failed', upload_rev: job.upload_rev, error_code: code, error: code },
    ctx.requestId,
  );
}

export async function extractDocument(deps: JobDeps, job: DocumentJob, ctx: JobContext): Promise<Extraction> {
  await deps.web
    .reportDocumentStatus(job.document_id, { status: 'processing', upload_rev: job.upload_rev, pages_done: 0 }, ctx.requestId)
    .catch(() => undefined);
  const file = await deps.web.downloadDocument(job.document_id, deps.settings.maxFileBytes, ctx.requestId);
  const extraction = await deps.ai.extractDocument(
    job.document_id,
    {
      data: file.data,
      mime: job.mime ?? file.contentType,
      filename: file.filename,
      maxPages: job.limits.max_pages_per_document ?? null,
    },
    ctx.requestId,
  );
  await deps.extracts.set(extractCacheKey(job.document_id, job.upload_rev), encodeExtraction(extraction), deps.settings.extractTtlS);
  await ctx.progress({ pages_done: extraction.pages_total, pages_total: extraction.pages_total });
  await deps.web
    .reportDocumentStatus(
      job.document_id,
      {
        status: 'processing',
        upload_rev: job.upload_rev,
        pages_done: extraction.pages_total,
        pages_total: extraction.pages_total,
        ocr_used: extraction.ocr_used,
      },
      ctx.requestId,
    )
    .catch(() => undefined);
  return extraction;
}

export function createDocumentUploadedHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, documentJobSchema);
    const { created } = await deps.enqueuer.add(
      QUEUES.documents,
      JOB_NAMES.documentExtract,
      payload,
      extractJobId(payload.document_id, payload.upload_rev),
      EXTRACT_JOB_OPTIONS,
    );
    ctx.logger.info({ documentId: payload.document_id, uploadRev: payload.upload_rev, created }, 'document extraction queued');
    return { queued: created };
  };
}

export function createDocumentExtractHandler(deps: JobDeps): JobHandler {
  const semaphore = new Semaphore(Math.max(1, deps.settings.extractConcurrency));
  return async (data: unknown, ctx: JobContext) => {
    const parsed = documentJobSchema.safeParse(data);
    if (!parsed.success) throw permanent(`Invalid document.extract job: ${z.prettifyError(parsed.error)}`);
    const job = parsed.data;
    const extraction = await withGiveUp(
      ctx,
      () =>
        semaphore.run(async () => {
          try {
            return await extractDocument(deps, job, ctx);
          } catch (error) {
            const code = documentErrorCode(error);
            if (isPermanentCode(code)) throw permanent(`${code}: ${error instanceof Error ? error.message : String(error)}`);
            throw error;
          }
        }),
      async (error) => reportFailure(deps, job, unwrapPermanent(error), ctx),
    );
    await deps.enqueuer.add(QUEUES.documents, JOB_NAMES.documentIndex, job, indexJobId(job.document_id, job.upload_rev), DEFAULT_JOB_OPTIONS);
    ctx.logger.info(
      { documentId: job.document_id, uploadRev: job.upload_rev, pages: extraction.pages_total, ocrUsed: extraction.ocr_used },
      'document extracted',
    );
    return { pages: extraction.pages_total, ocr_used: extraction.ocr_used };
  };
}

function unwrapPermanent(error: unknown): unknown {
  if (error instanceof Error && error.name === 'UnrecoverableError') {
    const code = /^([a-z_]+):/.exec(error.message)?.[1];
    if (code && isPermanentCode(code)) return new AiDocumentError(code, 422, error.message);
  }
  return error;
}

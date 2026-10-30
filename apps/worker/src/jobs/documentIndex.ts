import { gzipSync } from 'node:zlib';
import { z } from 'zod';
import { AiDocumentError, type Extraction } from '../clients/ai.js';
import type { JobDeps } from './deps.js';
import {
  decodeExtraction,
  documentJobSchema,
  extractCacheKey,
  extractDocument,
  reportFailure,
  type DocumentJob,
} from './documentExtract.js';
import { permanent, withGiveUp, type JobContext, type JobHandler } from './types.js';

export function buildIndexBody(job: DocumentJob, extraction: Extraction): Buffer {
  const body = {
    workspace_id: job.workspace_id,
    upload_rev: job.upload_rev,
    title: job.title,
    mime: job.mime ?? 'application/octet-stream',
    ocr_used: extraction.ocr_used,
    archived: job.metadata.approval_status === 'archived',
    metadata: {
      ...job.metadata,
      language: job.metadata.language ?? (extraction.language_guess === 'ru' || extraction.language_guess === 'en' ? extraction.language_guess : null),
    },
    pages: extraction.pages,
    ai_external_allowed: job.ai_external_allowed,
  };
  return gzipSync(Buffer.from(JSON.stringify(body), 'utf8'));
}

async function loadExtraction(deps: JobDeps, job: DocumentJob, ctx: JobContext): Promise<Extraction> {
  const cached = await deps.extracts.get(extractCacheKey(job.document_id, job.upload_rev));
  const decoded = cached === null ? null : decodeExtraction(cached);
  if (decoded) return decoded;
  ctx.logger.warn({ documentId: job.document_id, uploadRev: job.upload_rev }, 'extracted pages expired; extracting again');
  return extractDocument(deps, job, ctx);
}

export function createDocumentIndexHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const parsed = documentJobSchema.safeParse(data);
    if (!parsed.success) throw permanent(`Invalid document.index job: ${z.prettifyError(parsed.error)}`);
    const job = parsed.data;
    const outcome = await withGiveUp(
      ctx,
      async () => {
        const extraction = await loadExtraction(deps, job, ctx);
        try {
          const result = await deps.ai.indexDocument(job.document_id, buildIndexBody(job, extraction), ctx.requestId);
          return { stale: false as const, extraction, result };
        } catch (error) {
          if (error instanceof AiDocumentError && error.code === 'stale_revision') return { stale: true as const };
          if (error instanceof AiDocumentError && !error.retryable) throw permanent(`${error.code}: ${error.message}`);
          throw error;
        }
      },
      async (error) => {
        const code = /^([a-z_]+):/.exec(error instanceof Error ? error.message : '')?.[1];
        await reportFailure(deps, job, code ? new AiDocumentError(code, 422, code) : error, ctx);
      },
    );
    if (outcome.stale) {
      ctx.logger.info({ documentId: job.document_id, uploadRev: job.upload_rev }, 'stale document revision ignored');
      return { stale: true };
    }
    await deps.web.reportDocumentStatus(
      job.document_id,
      {
        status: 'indexed',
        upload_rev: job.upload_rev,
        pages: outcome.extraction.pages_total,
        pages_done: outcome.extraction.pages_total,
        pages_total: outcome.extraction.pages_total,
        chunks: outcome.result.chunks,
        ocr_used: outcome.extraction.ocr_used,
      },
      ctx.requestId,
    );
    await deps.extracts.del(extractCacheKey(job.document_id, job.upload_rev)).catch(() => undefined);
    ctx.logger.info({ documentId: job.document_id, uploadRev: job.upload_rev, chunks: outcome.result.chunks }, 'document indexed');
    return { chunks: outcome.result.chunks };
  };
}


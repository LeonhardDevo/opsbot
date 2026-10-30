import { z } from 'zod';
import type { JobDeps } from './deps.js';
import { errorMessage, parseOutboxJob, withGiveUp, type JobContext, type JobHandler } from './types.js';

export const documentDeletedSchema = z.object({
  document_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
});

export const documentMetadataChangedSchema = z.object({
  document_id: z.number().int().positive(),
  workspace_id: z.number().int().positive(),
  title: z.string().min(1).max(500),
  department_ids: z.array(z.number().int().positive()).default([]),
  roles: z.array(z.string()).default([]),
  tags: z.array(z.string()).default([]),
  version: z.string().nullish(),
  effective_date: z.string().nullish(),
  approval_status: z.enum(['draft', 'approved', 'archived']),
  archived: z.boolean().default(false),
});

export const workspaceReembedSchema = z.object({
  workspace_id: z.number().int().positive(),
  ai_external_allowed: z.boolean().default(false),
});

export function createDocumentDeletedHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, documentDeletedSchema);
    await withGiveUp(
      ctx,
      () => deps.ai.deleteDocument(payload.document_id, payload.workspace_id, ctx.requestId),
      async (error) => {
        ctx.logger.error({ documentId: payload.document_id, error: errorMessage(error) }, 'document chunks could not be deleted');
      },
    );
    ctx.logger.info({ documentId: payload.document_id }, 'document chunks deleted');
    return { deleted: true };
  };
}

export function createDocumentMetadataChangedHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, documentMetadataChangedSchema);
    const updated = await withGiveUp(
      ctx,
      () =>
        deps.ai.patchMetadata(
          payload.document_id,
          {
            workspace_id: payload.workspace_id,
            title: payload.title,
            department_ids: payload.department_ids,
            roles: payload.roles,
            tags: payload.tags,
            version: payload.version ?? null,
            effective_date: payload.effective_date ?? null,
            approval_status: payload.approval_status,
            archived: payload.archived,
          },
          ctx.requestId,
        ),
      async (error) => {
        ctx.logger.error({ documentId: payload.document_id, error: errorMessage(error) }, 'document metadata sync gave up');
      },
    );
    if (updated === null) {
      ctx.logger.info({ documentId: payload.document_id }, 'document metadata skipped: not indexed yet');
      return { skipped: 'not_indexed' };
    }
    ctx.logger.info({ documentId: payload.document_id, updated }, 'document metadata synced');
    return { updated_chunks: updated };
  };
}

export function createWorkspaceReembedHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const { payload } = parseOutboxJob(data, workspaceReembedSchema);
    let cursor = 0;
    let chunks = 0;
    let documents = 0;
    await withGiveUp(
      ctx,
      async () => {
        for (;;) {
          const batch = await deps.ai.reembedBatch(
            payload.workspace_id,
            cursor,
            deps.settings.reembedBatchDocuments,
            payload.ai_external_allowed,
            ctx.requestId,
          );
          chunks += batch.chunks;
          documents += batch.documents;
          await ctx.progress({ cursor, chunks, documents });
          if (batch.next_cursor === null || batch.next_cursor === undefined || batch.next_cursor <= cursor) break;
          cursor = batch.next_cursor;
        }
      },
      async (error) => {
        ctx.logger.error({ workspaceId: payload.workspace_id, cursor, error: errorMessage(error) }, 'workspace re-embed gave up');
      },
    );
    ctx.logger.info({ workspaceId: payload.workspace_id, chunks, documents }, 'workspace re-embedded');
    return { chunks, documents };
  };
}

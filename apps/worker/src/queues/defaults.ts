import type { JobsOptions } from 'bullmq';

export const DEFAULT_JOB_OPTIONS: JobsOptions = Object.freeze({
  attempts: 5,
  backoff: { type: 'exponential', delay: 2000 },
  removeOnComplete: 1000,
  removeOnFail: 5000,
});

export const EXTRACT_JOB_OPTIONS: JobsOptions = Object.freeze({
  attempts: 3,
  backoff: { type: 'exponential', delay: 5000 },
  removeOnComplete: 1000,
  removeOnFail: 5000,
});

export function telegramUpdateJobId(botKey: string, updateId: number): string {
  return `tg-update-${botKey}-${updateId}`;
}

export function extractJobId(documentId: number, uploadRev: number): string {
  return `extract:${documentId}:${uploadRev}`;
}

export function indexJobId(documentId: number, uploadRev: number): string {
  return `index:${documentId}:${uploadRev}`;
}

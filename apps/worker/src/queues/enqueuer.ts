import { Queue, type ConnectionOptions, type JobsOptions } from 'bullmq';
import { ALL_QUEUES, QUEUES, type QueueName } from './names.js';
import { DEFAULT_JOB_OPTIONS } from './defaults.js';
import { assertNoSecrets } from './secrets.js';

export interface JobEnqueuer {
  add(queue: QueueName, name: string, data: unknown, jobId: string, options?: AddOptions): Promise<{ jobId: string; created: boolean }>;
}

export type AddOptions = JobsOptions & { retryFailed?: boolean };

const EXTERNAL_INGRESS: ReadonlySet<QueueName> = new Set([QUEUES.telegramUpdates, QUEUES.stripeEvents, QUEUES.billing]);

export function guardJobData(queue: QueueName, data: unknown): void {
  if (!EXTERNAL_INGRESS.has(queue)) assertNoSecrets(data);
}

function withoutRetryFlag(options: AddOptions | undefined): JobsOptions | undefined {
  if (!options) return undefined;
  const { retryFailed: _retryFailed, ...jobOptions } = options;
  return Object.keys(jobOptions).length > 0 ? jobOptions : undefined;
}

export class QueueRegistry implements JobEnqueuer {
  readonly queues: ReadonlyMap<QueueName, Queue>;

  constructor(connection: ConnectionOptions, prefix: string, names: readonly QueueName[] = ALL_QUEUES) {
    const map = new Map<QueueName, Queue>();
    for (const name of names) {
      map.set(name, new Queue(name, { connection, prefix, defaultJobOptions: DEFAULT_JOB_OPTIONS }));
    }
    this.queues = map;
  }

  get(name: QueueName): Queue {
    const queue = this.queues.get(name);
    if (!queue) throw new Error(`Unknown queue: ${name}`);
    return queue;
  }

  async add(queue: QueueName, name: string, data: unknown, jobId: string, options: AddOptions = {}): Promise<{ jobId: string; created: boolean }> {
    guardJobData(queue, data);
    const { retryFailed, ...jobOptions } = options;
    const target = this.get(queue);
    const existing = await target.getJob(jobId);
    if (existing) {
      if (retryFailed === true && (await existing.isFailed())) {
        await existing.retry('failed');
        return { jobId, created: true };
      }
      return { jobId, created: false };
    }
    const job = await target.add(name, data, { ...jobOptions, jobId });
    return { jobId: job.id ?? jobId, created: true };
  }

  async close(): Promise<void> {
    await Promise.allSettled([...this.queues.values()].map((queue) => queue.close()));
  }
}

export class MemoryEnqueuer implements JobEnqueuer {
  readonly jobs: Array<{ queue: QueueName; name: string; data: unknown; jobId: string; options?: JobsOptions }> = [];
  readonly failed = new Set<string>();
  readonly retried: string[] = [];
  failNext: Error | null = null;

  async add(queue: QueueName, name: string, data: unknown, jobId: string, options?: AddOptions): Promise<{ jobId: string; created: boolean }> {
    if (this.failNext) {
      const error = this.failNext;
      this.failNext = null;
      throw error;
    }
    guardJobData(queue, data);
    if (this.jobs.some((job) => job.queue === queue && job.jobId === jobId)) {
      if (options?.retryFailed === true && this.failed.delete(jobId)) {
        this.retried.push(jobId);
        return { jobId, created: true };
      }
      return { jobId, created: false };
    }
    const jobOptions = withoutRetryFlag(options);
    this.jobs.push({ queue, name, data, jobId, ...(jobOptions ? { options: jobOptions } : {}) });
    return { jobId, created: true };
  }
}

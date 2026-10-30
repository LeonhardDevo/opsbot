import { Worker, type ConnectionOptions } from 'bullmq';
import type { HandlerMap } from '../jobs/types.js';
import { errorFields, type Logger } from '../logger.js';
import type { Metrics } from '../metrics.js';
import { QUEUE_SETTINGS, type QueueName } from './names.js';
import { createProcessor } from './processor.js';

export function startWorkers(
  handlers: Partial<Record<QueueName, HandlerMap>>,
  options: { connection: ConnectionOptions; prefix: string; logger: Logger; metrics?: Metrics },
): Worker[] {
  const workers: Worker[] = [];
  for (const [queue, map] of Object.entries(handlers) as Array<[QueueName, HandlerMap | undefined]>) {
    if (!map || Object.keys(map).length === 0) continue;
    const settings = QUEUE_SETTINGS[queue];
    const worker = new Worker(queue, createProcessor(queue, map, options), {
      connection: options.connection,
      prefix: options.prefix,
      concurrency: settings.concurrency,
      ...(settings.limiter ? { limiter: settings.limiter } : {}),
    });
    worker.on('error', (error) => options.logger.error({ queue, ...errorFields(error) }, 'queue worker error'));
    workers.push(worker);
  }
  options.logger.info({ queues: workers.map((worker) => worker.name) }, 'queue workers started');
  return workers;
}

export async function closeWorkers(workers: Worker[]): Promise<void> {
  await Promise.allSettled(workers.map((worker) => worker.close()));
}

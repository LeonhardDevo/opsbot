import type { JobsOptions, RepeatOptions } from 'bullmq';
import type { WebApi } from '../clients/web.js';
import { JOB_NAMES } from '../queues/names.js';
import type { JobDeps } from './deps.js';
import type { JobContext, JobHandler } from './types.js';

export interface ScheduleDefinition {
  id: string;
  name: string;
  repeat: Omit<RepeatOptions, 'key'>;
  endpoint: Parameters<WebApi['tick']>[0];
}

export const SCHEDULES: readonly ScheduleDefinition[] = [
  { id: JOB_NAMES.remindersTick, name: JOB_NAMES.remindersTick, repeat: { every: 60_000 }, endpoint: 'reminders/dispatch' },
  { id: JOB_NAMES.documentsTick, name: JOB_NAMES.documentsTick, repeat: { every: 3_600_000 }, endpoint: 'documents/tick' },
  { id: JOB_NAMES.limitsTick, name: JOB_NAMES.limitsTick, repeat: { every: 3_600_000 }, endpoint: 'limits/tick' },
  { id: JOB_NAMES.botsReconcile, name: JOB_NAMES.botsReconcile, repeat: { pattern: '0 3 * * *', tz: 'UTC' }, endpoint: 'bots/reconcile' },
];

export const SCHEDULE_JOB_OPTIONS: JobsOptions = Object.freeze({ attempts: 1, removeOnComplete: 100, removeOnFail: 500 });

export interface SchedulerQueue {
  upsertJobScheduler(
    id: string,
    repeat: Omit<RepeatOptions, 'key'>,
    template: { name: string; data: Record<string, unknown>; opts: JobsOptions },
  ): Promise<unknown>;
}

export async function registerSchedules(queue: SchedulerQueue): Promise<string[]> {
  const ids: string[] = [];
  for (const schedule of SCHEDULES) {
    await queue.upsertJobScheduler(schedule.id, schedule.repeat, { name: schedule.name, data: {}, opts: SCHEDULE_JOB_OPTIONS });
    ids.push(schedule.id);
  }
  return ids;
}

export function createScheduleHandlers(deps: JobDeps): Record<string, JobHandler> {
  return Object.fromEntries(
    SCHEDULES.map((schedule) => [
      schedule.name,
      async (_data: unknown, ctx: JobContext) => {
        const result = await deps.web.tick(schedule.endpoint, ctx.requestId);
        ctx.logger.info({ tick: schedule.name, result }, 'scheduled tick done');
        return result ?? {};
      },
    ]),
  );
}

import { setTimeout as sleep } from 'node:timers/promises';
import type { Update } from 'grammy/types';
import { ALLOWED_UPDATES, PLATFORM_BOT_KEY, type BotEntry } from '../bot/botRegistry.js';
import { errorFields, type Logger } from '../logger.js';
import type { JobEnqueuer } from '../queues/enqueuer.js';
import { telegramUpdateJobId } from '../queues/defaults.js';
import { JOB_NAMES, QUEUES } from '../queues/names.js';
import { LeaderLock, type LockStore } from './leaderLock.js';

const POLL_TIMEOUT_S = 25;

export async function enqueueUpdate(enqueuer: JobEnqueuer, botKey: string, update: Update): Promise<{ jobId: string; created: boolean }> {
  return enqueuer.add(QUEUES.telegramUpdates, JOB_NAMES.telegramUpdate, { bot_key: botKey, update }, telegramUpdateJobId(botKey, update.update_id));
}

export interface PollingDeps {
  bots: { get(botKey: string): Promise<BotEntry | null> };
  activeBots: () => Promise<string[]>;
  enqueuer: JobEnqueuer;
  locks: LockStore;
  replicaId: string;
  logger: Logger;
  lockTtlMs?: number;
  renewMs?: number;
  resyncMs?: number;
  pollTimeoutS?: number;
}

interface Loop {
  lock: LeaderLock;
  abort: AbortController;
  done: Promise<void>;
}

export class PollingSupervisor {
  private readonly loops = new Map<string, Loop>();
  private readonly starting = new Set<string>();
  private resyncTimer: NodeJS.Timeout | null = null;
  private stopped = true;

  constructor(private readonly deps: PollingDeps) {}

  get running(): string[] {
    return [...this.loops.keys()];
  }

  async start(): Promise<void> {
    this.stopped = false;
    await this.sync();
    this.resyncTimer = setInterval(() => void this.sync(), this.deps.resyncMs ?? 60_000);
    this.resyncTimer.unref();
  }

  async sync(): Promise<void> {
    if (this.stopped) return;
    let keys: string[];
    try {
      keys = [PLATFORM_BOT_KEY, ...(await this.deps.activeBots())];
    } catch (error) {
      this.deps.logger.warn(errorFields(error), 'listing active bots failed');
      keys = [PLATFORM_BOT_KEY];
    }
    for (const key of keys) await this.ensure(key);
  }

  async ensure(botKey: string): Promise<boolean> {
    if (this.stopped || this.loops.has(botKey) || this.starting.has(botKey)) return this.loops.has(botKey);
    this.starting.add(botKey);
    try {
      const entry = await this.deps.bots.get(botKey).catch(() => null);
      if (!entry || entry.transport === 'fake') return false;
      const abort = new AbortController();
      const lock = new LeaderLock(this.deps.locks, `poll-lock:${botKey}`, this.deps.replicaId, {
        ttlMs: this.deps.lockTtlMs ?? 30_000,
        renewMs: this.deps.renewMs ?? 10_000,
        onLost: () => {
          this.deps.logger.warn({ botKey }, 'polling lock lost; stopping the loop');
          abort.abort();
          this.loops.delete(botKey);
        },
      });
      if (!(await lock.acquire())) return false;
      await entry.bot.api.deleteWebhook({ drop_pending_updates: false }).catch((error: unknown) => {
        this.deps.logger.warn({ botKey, ...errorFields(error) }, 'telegram deleteWebhook failed');
      });
      const done = this.poll(botKey, entry, abort.signal);
      this.loops.set(botKey, { lock, abort, done });
      this.deps.logger.info({ botKey }, 'telegram polling started');
      return true;
    } finally {
      this.starting.delete(botKey);
    }
  }

  async stopBot(botKey: string): Promise<void> {
    const loop = this.loops.get(botKey);
    if (!loop) return;
    this.loops.delete(botKey);
    loop.abort.abort();
    await loop.done.catch(() => undefined);
    await loop.lock.release();
  }

  async stop(): Promise<void> {
    this.stopped = true;
    if (this.resyncTimer) clearInterval(this.resyncTimer);
    this.resyncTimer = null;
    await Promise.all(this.running.map((key) => this.stopBot(key)));
  }

  private async poll(botKey: string, entry: BotEntry, signal: AbortSignal): Promise<void> {
    let offset: number | undefined;
    let backoffMs = 1000;
    while (!signal.aborted) {
      try {
        const updates = await entry.bot.api.getUpdates(
          { offset, timeout: this.deps.pollTimeoutS ?? POLL_TIMEOUT_S, allowed_updates: [...ALLOWED_UPDATES] },
          signal as unknown as Parameters<typeof entry.bot.api.getUpdates>[1],
        );
        for (const update of updates) {
          await enqueueUpdate(this.deps.enqueuer, botKey, update);
          offset = update.update_id + 1;
        }
        backoffMs = 1000;
      } catch (error) {
        if (signal.aborted) break;
        this.deps.logger.warn({ botKey, backoffMs, ...errorFields(error) }, 'telegram polling failed; backing off');
        await sleep(backoffMs, undefined, { signal }).catch(() => undefined);
        backoffMs = Math.min(backoffMs * 2, 30_000);
      }
    }
  }
}

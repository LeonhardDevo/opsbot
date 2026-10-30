import type { Redis } from 'ioredis';

export interface LockStore {
  acquire(key: string, owner: string, ttlMs: number): Promise<boolean>;
  renew(key: string, owner: string, ttlMs: number): Promise<boolean>;
  release(key: string, owner: string): Promise<void>;
}

const RENEW_SCRIPT = `
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('PEXPIRE', KEYS[1], ARGV[2])
end
return 0
`;

const RELEASE_SCRIPT = `
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
end
return 0
`;

export class RedisLockStore implements LockStore {
  constructor(
    private readonly redis: Redis,
    private readonly prefix: string,
  ) {}

  async acquire(key: string, owner: string, ttlMs: number): Promise<boolean> {
    const result = await this.redis.set(`${this.prefix}${key}`, owner, 'PX', ttlMs, 'NX');
    return result === 'OK';
  }

  async renew(key: string, owner: string, ttlMs: number): Promise<boolean> {
    const result = await this.redis.eval(RENEW_SCRIPT, 1, `${this.prefix}${key}`, owner, String(ttlMs));
    return Number(result) === 1;
  }

  async release(key: string, owner: string): Promise<void> {
    await this.redis.eval(RELEASE_SCRIPT, 1, `${this.prefix}${key}`, owner);
  }
}

export class MemoryLockStore implements LockStore {
  private readonly locks = new Map<string, { owner: string; expiresAt: number }>();

  constructor(private readonly now: () => number = Date.now) {}

  async acquire(key: string, owner: string, ttlMs: number): Promise<boolean> {
    const current = this.locks.get(key);
    if (current && current.expiresAt > this.now() && current.owner !== owner) return false;
    this.locks.set(key, { owner, expiresAt: this.now() + ttlMs });
    return true;
  }

  async renew(key: string, owner: string, ttlMs: number): Promise<boolean> {
    const current = this.locks.get(key);
    if (!current || current.owner !== owner || current.expiresAt <= this.now()) return false;
    current.expiresAt = this.now() + ttlMs;
    return true;
  }

  async release(key: string, owner: string): Promise<void> {
    if (this.locks.get(key)?.owner === owner) this.locks.delete(key);
  }

  holder(key: string): string | null {
    const current = this.locks.get(key);
    return current && current.expiresAt > this.now() ? current.owner : null;
  }
}

export class LeaderLock {
  private timer: NodeJS.Timeout | null = null;
  private held = false;

  constructor(
    private readonly store: LockStore,
    private readonly key: string,
    private readonly owner: string,
    private readonly options: { ttlMs: number; renewMs: number; onLost: () => void },
  ) {}

  get isHeld(): boolean {
    return this.held;
  }

  async acquire(): Promise<boolean> {
    this.held = await this.store.acquire(this.key, this.owner, this.options.ttlMs);
    if (this.held) {
      this.timer = setInterval(() => void this.renew(), this.options.renewMs);
      this.timer.unref();
    }
    return this.held;
  }

  async renew(): Promise<boolean> {
    if (!this.held) return false;
    const ok = await this.store.renew(this.key, this.owner, this.options.ttlMs).catch(() => false);
    if (!ok) {
      this.stopTimer();
      this.held = false;
      this.options.onLost();
    }
    return ok;
  }

  async release(): Promise<void> {
    this.stopTimer();
    if (this.held) await this.store.release(this.key, this.owner).catch(() => undefined);
    this.held = false;
  }

  private stopTimer(): void {
    if (this.timer) clearInterval(this.timer);
    this.timer = null;
  }
}

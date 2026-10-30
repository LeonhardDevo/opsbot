import type { Redis } from 'ioredis';

export interface BucketSpec {
  key: string;
  ratePerS: number;
  burst: number;
}

export interface RateBuckets {
  take(buckets: readonly BucketSpec[]): Promise<number>;
}

const TAKE_SCRIPT = `
local now = tonumber(ARGV[1])
local count = #KEYS
local tokens = {}
local wait = 0
for i = 1, count do
  local rate = tonumber(ARGV[2 + (i - 1) * 2])
  local burst = tonumber(ARGV[3 + (i - 1) * 2])
  local data = redis.call('HMGET', KEYS[i], 'tokens', 'ts')
  local current = tonumber(data[1]) or burst
  local ts = tonumber(data[2]) or now
  current = math.min(burst, current + math.max(0, now - ts) * rate / 1000)
  tokens[i] = current
  if current < 1 then
    local need = math.ceil((1 - current) * 1000 / rate)
    if need > wait then wait = need end
  end
end
for i = 1, count do
  local rate = tonumber(ARGV[2 + (i - 1) * 2])
  local burst = tonumber(ARGV[3 + (i - 1) * 2])
  local value = tokens[i]
  if wait == 0 then value = value - 1 end
  redis.call('HSET', KEYS[i], 'tokens', tostring(value), 'ts', tostring(now))
  redis.call('PEXPIRE', KEYS[i], math.ceil(burst * 1000 / rate) + 1000)
end
return wait
`;

export class RedisRateBuckets implements RateBuckets {
  constructor(
    private readonly redis: Redis,
    private readonly prefix: string,
    private readonly now: () => number = Date.now,
  ) {}

  async take(buckets: readonly BucketSpec[]): Promise<number> {
    const args: string[] = [String(this.now())];
    for (const bucket of buckets) args.push(String(bucket.ratePerS), String(bucket.burst));
    const result = await this.redis.eval(
      TAKE_SCRIPT,
      buckets.length,
      ...buckets.map((bucket) => `${this.prefix}${bucket.key}`),
      ...args,
    );
    return Number(result) || 0;
  }
}

export class MemoryRateBuckets implements RateBuckets {
  private readonly state = new Map<string, { tokens: number; ts: number }>();

  constructor(private readonly now: () => number = Date.now) {}

  async take(buckets: readonly BucketSpec[]): Promise<number> {
    const now = this.now();
    let wait = 0;
    const levels = buckets.map((bucket) => {
      const current = this.state.get(bucket.key) ?? { tokens: bucket.burst, ts: now };
      const tokens = Math.min(bucket.burst, current.tokens + (Math.max(0, now - current.ts) * bucket.ratePerS) / 1000);
      if (tokens < 1) wait = Math.max(wait, Math.ceil(((1 - tokens) * 1000) / bucket.ratePerS));
      return tokens;
    });
    buckets.forEach((bucket, index) => {
      const tokens = levels[index] ?? bucket.burst;
      this.state.set(bucket.key, { tokens: wait === 0 ? tokens - 1 : tokens, ts: now });
    });
    return wait;
  }
}

export function telegramBuckets(botKey: string, chatId: number | string, rates: { botPerS: number; chatPerS: number }): BucketSpec[] {
  return [
    { key: `tg-rate:bot:${botKey}`, ratePerS: rates.botPerS, burst: Math.max(1, Math.floor(rates.botPerS)) },
    { key: `tg-rate:chat:${botKey}:${chatId}`, ratePerS: rates.chatPerS, burst: Math.max(1, Math.floor(rates.chatPerS)) },
  ];
}

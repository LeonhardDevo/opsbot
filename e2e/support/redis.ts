import { createConnection } from 'node:net';

export const DELETE_BY_PATTERN = "local n = 0 for _, key in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', key) n = n + 1 end return n";

export function encodeCommand(args: readonly string[]): Buffer {
  const parts = [`*${args.length}\r\n`];
  for (const arg of args) parts.push(`$${Buffer.byteLength(arg)}\r\n${arg}\r\n`);
  return Buffer.from(parts.join(''), 'utf8');
}

export function parseReply(raw: string): number | string | null {
  const line = raw.split('\r\n')[0] ?? '';
  const type = line.charAt(0);
  const body = line.slice(1);
  if (type === ':') return Number(body);
  if (type === '+') return body;
  if (type === '-') throw new Error(`redis error: ${body}`);
  if (type === '$' && body === '-1') return null;
  if (type === '$') return raw.split('\r\n')[1] ?? '';
  throw new Error(`unexpected redis reply: ${line}`);
}

export function deleteKeys(host: string, port: number, pattern: string, timeoutMs = 10_000): Promise<number> {
  if (!/^[A-Za-z0-9_:.-]+\*$/.test(pattern)) return Promise.reject(new Error(`refusing to delete redis keys by pattern "${pattern}"`));
  return new Promise((resolve, reject) => {
    const socket = createConnection({ host, port });
    let buffer = '';
    const timer = setTimeout(() => {
      socket.destroy();
      reject(new Error(`redis ${host}:${port} did not answer in ${timeoutMs} ms`));
    }, timeoutMs);
    socket.once('error', (error) => {
      clearTimeout(timer);
      reject(error);
    });
    socket.on('data', (chunk) => {
      buffer += chunk.toString('utf8');
      if (!buffer.includes('\r\n')) return;
      clearTimeout(timer);
      socket.end();
      try {
        resolve(Number(parseReply(buffer)));
      } catch (error) {
        reject(error);
      }
    });
    socket.once('connect', () => socket.write(encodeCommand(['EVAL', DELETE_BY_PATTERN, '0', pattern])));
  });
}

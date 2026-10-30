import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { deleteKeys, encodeCommand, parseReply } from './redis.ts';

describe('redis wire helpers', () => {
  it('encodes commands as RESP arrays with byte lengths', () => {
    assert.equal(encodeCommand(['DEL', 'kлюч']).toString('utf8'), '*2\r\n$3\r\nDEL\r\n$7\r\nkлюч\r\n');
  });

  it('parses integer, status, bulk and error replies', () => {
    assert.equal(parseReply(':12\r\n'), 12);
    assert.equal(parseReply('+OK\r\n'), 'OK');
    assert.equal(parseReply('$3\r\nabc\r\n'), 'abc');
    assert.equal(parseReply('$-1\r\n'), null);
    assert.throws(() => parseReply('-ERR wrong\r\n'), /wrong/);
  });

  it('refuses patterns that could wipe unrelated keys', async () => {
    await assert.rejects(() => deleteKeys('127.0.0.1', 1, '*'), /refusing/);
    await assert.rejects(() => deleteKeys('127.0.0.1', 1, 'opsbot e2e*'), /refusing/);
  });
});

import { describe, expect, it } from 'vitest';
import { buildKeyboard, type ActionOwner } from '../src/bot/helpers.js';
import { MemoryActionStore } from '../src/bot/stores.js';

const owner: ActionOwner = { botKey: '1', telegramUserId: 42, chatId: 42, workspaceId: 1, userId: 7, locale: 'en' };

describe('buildKeyboard', () => {
  it('never leaves an empty trailing row, one button per row', async () => {
    const keyboard = await buildKeyboard(
      [
        { text: 'Create incident', callback: 'opsbot.action:abc:preview' },
        { text: 'Pass to manager', callback: 'escalate:1' },
      ],
      owner,
      new MemoryActionStore(),
      60,
    );
    expect(keyboard!.inline_keyboard.map((row) => row.length)).toEqual([1, 1]);
  });

  it('fills rows up to perRow and skips buttons without a target', async () => {
    const keyboard = await buildKeyboard(
      [
        { text: 'A', callback: 'x:1' },
        { text: 'B', callback: 'x:2' },
        { text: 'Broken' },
        { text: 'C', url: 'https://example.com/c' },
      ],
      owner,
      new MemoryActionStore(),
      60,
      { perRow: 2 },
    );
    expect(keyboard!.inline_keyboard.map((row) => row.map((button) => button.text))).toEqual([['A', 'B'], ['C']]);
  });

  it('returns nothing when no button is usable', async () => {
    expect(await buildKeyboard([{ text: 'Broken' }], owner, new MemoryActionStore(), 60)).toBeUndefined();
  });
});

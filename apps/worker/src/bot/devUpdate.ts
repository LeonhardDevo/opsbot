import type { Update } from 'grammy/types';
import { z } from 'zod';

export const devUpdateShorthandSchema = z
  .object({
    from: z.object({
      id: z.number().int().positive(),
      username: z.string().min(1).max(64).optional(),
      first_name: z.string().min(1).max(64).optional(),
      last_name: z.string().max(64).optional(),
      language_code: z.string().min(2).max(10).optional(),
    }),
    chat_id: z.number().int().optional(),
    text: z.string().min(1).max(4096).optional(),
    callback_data: z.string().min(1).max(64).optional(),
    chat_member_status: z.enum(['member', 'administrator', 'left', 'kicked']).optional(),
    reply_to_message_id: z.number().int().positive().optional(),
    message_id: z.number().int().positive().optional(),
    update_id: z.number().int().nonnegative().optional(),
  })
  .refine(
    (value) => [value.text, value.callback_data, value.chat_member_status].filter((item) => item !== undefined).length === 1,
    { message: 'exactly one of text, callback_data or chat_member_status is required' },
  );
export type DevUpdateShorthand = z.infer<typeof devUpdateShorthandSchema>;

let sequence = 0;

function nextUpdateId(): number {
  sequence = (sequence + 1) % 1000;
  return Math.floor(Date.now() / 10) * 1000 + sequence;
}

export function buildDevUpdate(input: DevUpdateShorthand, bot: { id: number; username: string } = { id: 1, username: 'bot' }): Update {
  const date = Math.floor(Date.now() / 1000);
  const user = {
    id: input.from.id,
    is_bot: false,
    first_name: input.from.first_name ?? 'Dev',
    ...(input.from.last_name ? { last_name: input.from.last_name } : {}),
    ...(input.from.username ? { username: input.from.username } : {}),
    ...(input.from.language_code ? { language_code: input.from.language_code } : {}),
  };
  const botUser = { id: bot.id, is_bot: true, first_name: 'Bot', username: bot.username };
  const chatId = input.chat_id ?? input.from.id;
  const chat =
    chatId === input.from.id
      ? { id: chatId, type: 'private' as const, first_name: user.first_name, ...(user.username ? { username: user.username } : {}) }
      : { id: chatId, type: 'group' as const, title: 'Dev group' };
  const updateId = input.update_id ?? nextUpdateId();
  const messageId = input.message_id ?? (updateId % 1_000_000) + 1;

  if (input.chat_member_status !== undefined) {
    return {
      update_id: updateId,
      my_chat_member: {
        chat,
        from: user,
        date,
        old_chat_member: { status: 'left', user: botUser },
        new_chat_member:
          input.chat_member_status === 'kicked'
            ? { status: 'kicked', user: botUser, until_date: 0 }
            : input.chat_member_status === 'administrator'
              ? ({ status: 'administrator', user: botUser } as never)
              : { status: input.chat_member_status, user: botUser },
      },
    };
  }

  if (input.callback_data !== undefined) {
    return {
      update_id: updateId,
      callback_query: {
        id: `dev-${updateId}`,
        from: user,
        chat_instance: `dev-${chatId}`,
        data: input.callback_data,
        message: { message_id: messageId, date, chat, from: botUser, text: '…' },
      },
    };
  }

  const text = input.text ?? '';
  const command = /^\/[A-Za-z0-9_]+(@\w+)?/.exec(text);
  return {
    update_id: updateId,
    message: {
      message_id: messageId,
      date,
      chat,
      from: user,
      text,
      ...(input.reply_to_message_id
        ? { reply_to_message: { message_id: input.reply_to_message_id, date, chat, from: botUser, text: '…' } as never }
        : {}),
      ...(command ? { entities: [{ type: 'bot_command', offset: 0, length: command[0].length }] } : {}),
    },
  };
}

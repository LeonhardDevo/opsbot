import { QUEUES, type QueueName } from './names.js';

export const OPSBOT_NOTIFICATION_TOPICS = [
  'opsbot.incident.created',
  'opsbot.incident.status_changed',
  'opsbot.task.assigned',
  'opsbot.task.reminder_due',
  'opsbot.task.escalated',
  'opsbot.question.escalated',
  'opsbot.question.answered',
  'opsbot.document.review_due',
  'opsbot.limits.notice',
  'opsbot.bot.revoked',
] as const;

export type OpsbotNotificationTopic = (typeof OPSBOT_NOTIFICATION_TOPICS)[number];

export const TOPIC_ROUTES: Readonly<Record<string, QueueName>> = Object.freeze({
  'email.send': QUEUES.emails,
  'telegram.notify': QUEUES.telegram,
  ...Object.fromEntries(OPSBOT_NOTIFICATION_TOPICS.map((topic) => [topic, QUEUES.telegram])),
  'document.uploaded': QUEUES.documents,
  'document.deleted': QUEUES.documents,
  'document.metadata_changed': QUEUES.documents,
  'workspace.reembed': QUEUES.documents,
  'telegram.bot.disconnect': QUEUES.telegramAdmin,
  'telegram.context.invalidate': QUEUES.telegramAdmin,
  'workspace.purge': QUEUES.documents,
  'privacy.export.requested': QUEUES.privacy,
  'privacy.purge.due': QUEUES.privacy,
  'user.purge': QUEUES.privacy,
});

export function resolveQueue(topic: string): QueueName | null {
  return Object.hasOwn(TOPIC_ROUTES, topic) ? (TOPIC_ROUTES[topic] ?? null) : null;
}

export function topicsForQueue(queue: QueueName): string[] {
  return Object.entries(TOPIC_ROUTES)
    .filter(([, target]) => target === queue)
    .map(([topic]) => topic);
}

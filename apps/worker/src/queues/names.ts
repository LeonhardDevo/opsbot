export const QUEUES = {
  emails: 'emails',
  telegram: 'telegram',
  documents: 'documents',
  stripeEvents: 'stripe-events',
  telegramUpdates: 'telegram-updates',
  telegramAdmin: 'telegram-admin',
  opsbotSchedules: 'opsbot-schedules',
  privacy: 'privacy',
  billing: 'billing',
} as const;

export type QueueName = (typeof QUEUES)[keyof typeof QUEUES];

export const ALL_QUEUES: readonly QueueName[] = Object.values(QUEUES);

export const JOB_NAMES = {
  stripeEvent: 'stripe.event',
  telegramUpdate: 'telegram.update',
  documentExtract: 'document.extract',
  documentIndex: 'document.index',
  remindersTick: 'opsbot.reminders.tick',
  documentsTick: 'opsbot.documents.tick',
  limitsTick: 'opsbot.limits.tick',
  botsReconcile: 'opsbot.bots.reconcile',
  privacyPurge: 'privacy.purge.due',
  yookassaNotification: 'yookassa.notification',
  billingRenewals: 'billing.renewals.due',
} as const;

export const QUEUE_SETTINGS: Record<QueueName, { concurrency: number; limiter?: { max: number; duration: number } }> = {
  emails: { concurrency: 5 },
  telegram: { concurrency: 10 },
  documents: { concurrency: 4 },
  'stripe-events': { concurrency: 5 },
  'telegram-updates': { concurrency: 10 },
  'telegram-admin': { concurrency: 2 },
  'opsbot-schedules': { concurrency: 1 },
  privacy: { concurrency: 1 },
  billing: { concurrency: 2 },
};

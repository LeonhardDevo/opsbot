import { JOB_NAMES, QUEUES, type QueueName } from '../queues/names.js';
import { OPSBOT_NOTIFICATION_TOPICS } from '../queues/routes.js';
import { createBillingRenewalsHandler, createYooKassaNotificationHandler } from './billing.js';
import { createBotDisconnectHandler, createContextInvalidateHandler } from './botAdmin.js';
import type { JobDeps } from './deps.js';
import { createDocumentExtractHandler, createDocumentUploadedHandler } from './documentExtract.js';
import { createDocumentIndexHandler } from './documentIndex.js';
import { createDocumentDeletedHandler, createDocumentMetadataChangedHandler, createWorkspaceReembedHandler } from './documents.js';
import { createEmailHandler } from './email.js';
import { createOpsbotNotifyHandler } from './opsbotNotify.js';
import { createExportRequestedHandler, createPrivacyPurgeHandler, createUserPurgeHandler, createWorkspacePurgeHandler } from './privacy.js';
import { createScheduleHandlers } from './schedules.js';
import { createStripeEventHandler } from './stripeEvents.js';
import { createTelegramNotifyHandler } from './telegramNotify.js';
import { createTelegramUpdateHandler } from './telegramUpdates.js';
import type { HandlerMap } from './types.js';

export function createHandlers(deps: JobDeps): Record<QueueName, HandlerMap> {
  return {
    [QUEUES.emails]: { 'email.send': createEmailHandler(deps) },
    [QUEUES.telegram]: {
      'telegram.notify': createTelegramNotifyHandler(deps),
      ...Object.fromEntries(OPSBOT_NOTIFICATION_TOPICS.map((topic) => [topic, createOpsbotNotifyHandler(deps, topic)])),
    },
    [QUEUES.documents]: {
      'document.uploaded': createDocumentUploadedHandler(deps),
      [JOB_NAMES.documentExtract]: createDocumentExtractHandler(deps),
      [JOB_NAMES.documentIndex]: createDocumentIndexHandler(deps),
      'document.deleted': createDocumentDeletedHandler(deps),
      'document.metadata_changed': createDocumentMetadataChangedHandler(deps),
      'workspace.reembed': createWorkspaceReembedHandler(deps),
      'workspace.purge': createWorkspacePurgeHandler(deps),
    },
    [QUEUES.stripeEvents]: { [JOB_NAMES.stripeEvent]: createStripeEventHandler(deps) },
    [QUEUES.telegramUpdates]: { [JOB_NAMES.telegramUpdate]: createTelegramUpdateHandler(deps) },
    [QUEUES.telegramAdmin]: {
      'telegram.bot.disconnect': createBotDisconnectHandler(deps),
      'telegram.context.invalidate': createContextInvalidateHandler(deps),
    },
    [QUEUES.opsbotSchedules]: createScheduleHandlers(deps),
    [QUEUES.privacy]: {
      'privacy.export.requested': createExportRequestedHandler(deps),
      'user.purge': createUserPurgeHandler(deps),
      [JOB_NAMES.privacyPurge]: createPrivacyPurgeHandler(deps),
    },
    [QUEUES.billing]: {
      [JOB_NAMES.yookassaNotification]: createYooKassaNotificationHandler(deps),
      [JOB_NAMES.billingRenewals]: createBillingRenewalsHandler(deps),
    },
  };
}

export type { JobDeps } from './deps.js';
export type { HandlerMap, JobContext, JobHandler } from './types.js';

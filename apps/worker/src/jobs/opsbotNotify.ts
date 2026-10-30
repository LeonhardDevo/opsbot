import { z } from 'zod';
import { escapeHtml } from '../bot/format.js';
import { OPSBOT_NOTIFICATION_TOPICS, type OpsbotNotificationTopic } from '../queues/routes.js';
import type { JobDeps } from './deps.js';
import { deliverNotification } from './telegramNotify.js';
import { parseOutboxJob, permanent, type JobContext, type JobHandler } from './types.js';

const id = z.number().int().positive();
const text = z.string().max(5000);
const optionalText = z.string().max(5000).nullish();
const url = z.string().max(2000).nullish();
const recipient = z.object({ user_id: id, locale: z.enum(['ru', 'en']).nullish() });
const base = { workspace_id: id, recipient, bot_key: z.string().regex(/^(platform|[1-9][0-9]{0,18})$/).nullish() };
const incidentCategory = z.enum(['damage', 'complaint', 'no_access', 'injury', 'lost_item', 'other']);
const taskRef = z.object({
  id,
  display_number: text,
  title: text,
  due_local: optionalText,
  overdue_hours: z.number().nonnegative().nullish(),
});

export const OPSBOT_TOPIC_SCHEMAS: Record<OpsbotNotificationTopic, z.ZodType> = {
  'opsbot.incident.created': z.object({
    ...base,
    incident: z.object({
      id,
      display_number: text,
      category: incidentCategory,
      severity: z.enum(['low', 'medium', 'high']),
      description_excerpt: z.string().max(300),
      client_ref: optionalText,
      department_name: optionalText,
      reporter_name: optionalText,
      created_local: optionalText,
    }),
    web_url: url,
  }),
  'opsbot.incident.status_changed': z.object({
    ...base,
    incident: z.object({
      id,
      display_number: text,
      status: z.enum(['open', 'in_progress', 'resolved', 'closed']),
      assignee_name: optionalText,
    }),
    note_excerpt: optionalText,
  }),
  'opsbot.task.assigned': z.object({
    ...base,
    task: z.object({ id, display_number: text, title: text, due_local: optionalText, incident_display_number: optionalText }),
    assigned_by_name: optionalText,
    web_url: url,
  }),
  'opsbot.task.reminder_due': z.object({
    ...base,
    reminder_id: id,
    kind: z.enum(['pre_due', 'due', 'overdue_1', 'overdue_2', 'overdue_3']),
    task: taskRef,
  }),
  'opsbot.task.escalated': z.object({ ...base, task: taskRef, assignee_name: optionalText, web_url: url }),
  'opsbot.question.escalated': z.object({
    ...base,
    question: z.object({
      id,
      display_number: text,
      question_excerpt: z.string().max(500),
      employee_name: optionalText,
      department_name: optionalText,
      reason: z.enum(['low_confidence', 'not_found', 'conflict', 'user_request', 'limit_reached']),
      asked_local: optionalText,
    }),
    web_url: url,
  }),
  'opsbot.question.answered': z.object({
    ...base,
    query_id: id.nullish(),
    question_id: id,
    question_excerpt: z.string().max(1000),
    text_html: z.string().min(1).max(8000),
    manager_name: optionalText,
  }),
  'opsbot.document.review_due': z.object({
    ...base,
    document: z.object({ id, title: text, version: optionalText, review_due_local: optionalText }),
    web_url: url,
  }),
  'opsbot.limits.notice': z.object({
    ...base,
    limit_key: z.enum(['seats', 'documents', 'storage_mb', 'ai_queries_monthly', 'max_pages_per_document', 'max_file_mb', 'bots']),
    used: z.number().nonnegative(),
    limit: z.number().nonnegative(),
    web_url: url,
  }),
  'opsbot.bot.revoked': z.object({ ...base, bot_username: optionalText, web_url: url }),
};

export function isOpsbotTopic(topic: string): topic is OpsbotNotificationTopic {
  return (OPSBOT_NOTIFICATION_TOPICS as readonly string[]).includes(topic);
}

export function createOpsbotNotifyHandler(deps: JobDeps, topic: OpsbotNotificationTopic): JobHandler {
  const schema = OPSBOT_TOPIC_SCHEMAS[topic];
  return async (data: unknown, ctx: JobContext) => {
    const job = parseOutboxJob(data, schema);
    const payload = job.payload as Record<string, unknown> & {
      workspace_id: number;
      recipient: { user_id: number; locale?: 'ru' | 'en' | null };
      bot_key?: string | null;
    };
    const template = deps.modules.notification(topic);
    if (!template) throw permanent(`No template for topic "${topic}"`);
    return deliverNotification(
      deps,
      {
        outboxId: job.outbox_id,
        workspaceId: payload.workspace_id,
        userId: payload.recipient.user_id,
        chatId: null,
        botKey: payload.bot_key ?? null,
        locale: payload.recipient.locale ?? null,
        reference: { type: topic, id: job.outbox_id },
        render: (locale) => {
          const rendered = template(payload, {
            locale,
            t: deps.translator.forLocale(locale),
            plural: (key, count, params) => deps.translator.plural(locale, key, count, params),
            escape: escapeHtml,
          });
          return { html: rendered.html, buttons: rendered.buttons ?? [] };
        },
      },
      ctx,
    );
  };
}

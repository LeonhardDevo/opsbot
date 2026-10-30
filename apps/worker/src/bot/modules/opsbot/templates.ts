import type { NotificationHelpers, NotificationTemplate, NotifyButton } from '../../types.js';

type Params = Record<string, unknown>;

function record(value: unknown): Params {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? (value as Params) : {};
}

function text(value: unknown): string | null {
  if (typeof value === 'string' && value.trim() !== '') return value;
  if (typeof value === 'number') return String(value);
  return null;
}

function count(value: unknown): number {
  const numeric = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(numeric) ? Math.max(0, Math.floor(numeric)) : 0;
}

function join(lines: Array<string | null>): string {
  return lines.filter((line): line is string => line !== null && line !== '').join('\n');
}

function line(h: NotificationHelpers, key: string, name: string, value: unknown): string | null {
  const raw = text(value);
  return raw === null ? null : h.t(key, { [name]: h.escape(raw) });
}

function quote(h: NotificationHelpers, value: unknown): string | null {
  const raw = text(value);
  return raw === null ? null : `<blockquote>${h.escape(raw)}</blockquote>`;
}

function openButton(h: NotificationHelpers, url: unknown, key = 'opsbot.notify.open'): NotifyButton[] {
  const target = text(url);
  return target ? [{ text: h.t(key), url: target }] : [];
}

function label(h: NotificationHelpers, key: string, value: unknown): string {
  const raw = text(value) ?? '';
  const translated = h.t(`${key}.${raw}`);
  return translated === `${key}.${raw}` ? h.escape(raw) : translated;
}

export const incidentCreated: NotificationTemplate = (params, h) => {
  const incident = record(params.incident);
  const id = count(incident.id);
  const html = join([
    h.t('opsbot.notify.incident_created.title', {
      number: h.escape(text(incident.display_number) ?? `#${id}`),
      category: label(h, 'opsbot.incident.categories', incident.category),
    }),
    h.t('opsbot.notify.incident_created.severity', { severity: label(h, 'opsbot.notify.severity', incident.severity) }),
    line(h, 'opsbot.notify.incident_created.department', 'department', incident.department_name),
    text(incident.reporter_name)
      ? h.t('opsbot.notify.incident_created.reporter', {
          reporter: h.escape(text(incident.reporter_name) ?? ''),
          created: h.escape(text(incident.created_local) ?? ''),
        })
      : null,
    line(h, 'opsbot.notify.incident_created.client', 'client', incident.client_ref),
    quote(h, incident.description_excerpt),
  ]);
  return {
    html,
    buttons: [
      { text: h.t('opsbot.notify.take'), callback: { type: 'incident_take', payload: { id } } },
      ...openButton(h, params.web_url),
    ],
  };
};

export const incidentStatusChanged: NotificationTemplate = (params, h) => {
  const incident = record(params.incident);
  return {
    html: join([
      h.t('opsbot.notify.incident_status_changed.title', {
        number: h.escape(text(incident.display_number) ?? `#${count(incident.id)}`),
        status: label(h, 'opsbot.notify.incident_status', incident.status),
      }),
      line(h, 'opsbot.notify.incident_status_changed.assignee', 'assignee', incident.assignee_name),
      quote(h, params.note_excerpt),
    ]),
  };
};

export const taskAssigned: NotificationTemplate = (params, h) => {
  const task = record(params.task);
  const id = count(task.id);
  return {
    html: join([
      h.t('opsbot.notify.task_assigned.title', { number: h.escape(text(task.display_number) ?? `#${id}`) }),
      text(task.title) ? h.escape(text(task.title) ?? '') : null,
      line(h, 'opsbot.notify.task_assigned.due', 'due', task.due_local),
      line(h, 'opsbot.notify.task_assigned.incident', 'incident', task.incident_display_number),
      line(h, 'opsbot.notify.task_assigned.assigned_by', 'name', params.assigned_by_name),
    ]),
    buttons: [
      { text: h.t('opsbot.notify.start'), callback: { type: 'task_start', payload: { id } } },
      { text: h.t('opsbot.notify.done'), callback: { type: 'task_done', payload: { id } } },
      ...openButton(h, params.web_url),
    ],
  };
};

export const taskReminderDue: NotificationTemplate = (params, h) => {
  const task = record(params.task);
  const id = count(task.id);
  const kind = text(params.kind) ?? 'due';
  const number = h.escape(text(task.display_number) ?? `#${id}`);
  const title =
    kind === 'pre_due' || kind === 'due'
      ? h.t(`opsbot.notify.task_reminder.${kind}`, { number })
      : h.t('opsbot.notify.task_reminder.overdue', { number, hours: h.plural('opsbot.hours', count(task.overdue_hours)) });
  return {
    html: join([
      title,
      text(task.title) ? h.escape(text(task.title) ?? '') : null,
      line(h, 'opsbot.notify.task_reminder.due_at', 'due', task.due_local),
    ]),
    buttons: [{ text: h.t('opsbot.notify.done'), callback: { type: 'task_done', payload: { id } } }],
  };
};

export const taskEscalated: NotificationTemplate = (params, h) => {
  const task = record(params.task);
  const id = count(task.id);
  return {
    html: join([
      h.t('opsbot.notify.task_escalated.title', {
        number: h.escape(text(task.display_number) ?? `#${id}`),
        hours: h.plural('opsbot.hours', count(task.overdue_hours)),
      }),
      text(task.title) ? h.escape(text(task.title) ?? '') : null,
      line(h, 'opsbot.notify.task_escalated.assignee', 'assignee', params.assignee_name),
      line(h, 'opsbot.notify.task_escalated.due', 'due', task.due_local),
    ]),
    buttons: openButton(h, params.web_url),
  };
};

export const questionEscalated: NotificationTemplate = (params, h) => {
  const question = record(params.question);
  const id = count(question.id);
  const display = text(question.display_number) ?? `#${id}`;
  const department = text(question.department_name);
  return {
    html: join([
      h.t('opsbot.notify.question_escalated.title', { number: h.escape(display) }),
      quote(h, question.question_excerpt),
      h.t('opsbot.notify.question_escalated.employee', {
        employee: h.escape(text(question.employee_name) ?? '—'),
        department: department ? ` (${h.escape(department)})` : '',
        asked: h.escape(text(question.asked_local) ?? ''),
      }),
      h.t('opsbot.notify.question_escalated.reason', { reason: label(h, 'opsbot.notify.question_reason', question.reason) }),
    ]),
    buttons: [
      { text: h.t('opsbot.notify.answer'), callback: { type: 'q_answer', payload: { id, display_number: display } } },
      ...openButton(h, params.web_url, 'opsbot.notify.open_web'),
      { text: h.t('opsbot.notify.dismiss'), callback: { type: 'q_dismiss', payload: { id } } },
    ],
  };
};

export const questionAnswered: NotificationTemplate = (params, h) => ({
  html: join([
    h.t('opsbot.notify.question_answered.title'),
    quote(h, params.question_excerpt),
    text(params.text_html) ?? '',
    text(params.manager_name) ? `— ${h.escape(text(params.manager_name) ?? '')}` : null,
  ]),
});

export const documentReviewDue: NotificationTemplate = (params, h) => {
  const document = record(params.document);
  const version = text(document.version);
  return {
    html: join([
      h.t('opsbot.notify.review_due.title'),
      h.t('opsbot.notify.review_due.document', {
        title: h.escape(text(document.title) ?? ''),
        version: version ? h.t('opsbot.notify.review_due.version', { version: h.escape(version) }) : '',
      }),
      line(h, 'opsbot.notify.review_due.due', 'due', document.review_due_local),
    ]),
    buttons: openButton(h, params.web_url),
  };
};

export const limitsNotice: NotificationTemplate = (params, h) => ({
  html: join([
    h.t('opsbot.notify.limits_notice.title'),
    h.t('opsbot.notify.limits_notice.usage', {
      limit_name: label(h, 'opsbot.notify.limit_key', params.limit_key),
      used: count(params.used),
      limit: count(params.limit),
    }),
  ]),
  buttons: openButton(h, params.web_url),
});

export const botRevoked: NotificationTemplate = (params, h) => ({
  html: join([
    h.t('opsbot.notify.bot_revoked.title'),
    h.t('opsbot.notify.bot_revoked.text', { username: h.escape(text(params.bot_username) ?? '') }),
  ]),
  buttons: openButton(h, params.web_url),
});

export const testMessage: NotificationTemplate = (_params, h) => ({ html: h.t('opsbot.notify.test_message') });

export const OPSBOT_TEMPLATES: Record<string, NotificationTemplate> = {
  'opsbot.incident.created': incidentCreated,
  'opsbot.incident.status_changed': incidentStatusChanged,
  'opsbot.task.assigned': taskAssigned,
  'opsbot.task.reminder_due': taskReminderDue,
  'opsbot.task.escalated': taskEscalated,
  'opsbot.question.escalated': questionEscalated,
  'opsbot.question.answered': questionAnswered,
  'opsbot.document.review_due': documentReviewDue,
  'opsbot.limits.notice': limitsNotice,
  'opsbot.bot.revoked': botRevoked,
  'opsbot.test_message': testMessage,
};

import { z } from 'zod';
import { isPermanentSmtpError, type MailAddress, type MailAttachment } from '../mail/mailer.js';
import { SENT_MARKER_TTL_S, type JobDeps } from './deps.js';
import { errorMessage, parseOutboxJob, permanent, referenceSchema, withGiveUp, type JobContext, type JobHandler } from './types.js';

const address = z.string().trim().min(3).max(320).refine((value) => value.includes('@'), 'must be an email address');
const displayName = z.string().trim().max(200).nullish();
const mailbox = z.union([address, z.object({ address, name: displayName })]);

export const ALLOWED_EXTRA_HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post', 'Auto-Submitted'] as const;
const headerValue = z.string().max(2000).regex(/^[^\r\n]*$/, 'must be a single line');

export const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

const attachmentSchema = z.object({
  filename: z.string().trim().max(255).nullish(),
  content_type: z.string().trim().min(3).max(255).default('application/octet-stream'),
  content_base64: z.string().regex(/^[A-Za-z0-9+/=\r\n]*$/, 'must be base64'),
});

export const emailPayloadSchema = z
  .object({
    to: z.union([address, z.array(address).min(1).max(50)]),
    to_name: displayName,
    subject: z.string().trim().min(1).max(998),
    html: z.string().nullish(),
    text: z.string().nullish(),
    locale: z.enum(['ru', 'en']).nullish(),
    from: mailbox.nullish(),
    reply_to: mailbox.nullish(),
    attachments: z.array(attachmentSchema).max(20).nullish(),
    reference: referenceSchema,
    workspace_id: z.number().nullish(),
    headers: z.record(z.string(), headerValue).nullish(),
  })
  .refine((payload) => Boolean(payload.html || payload.text), { message: 'html or text is required' });
export type EmailPayload = z.infer<typeof emailPayloadSchema>;

function toMailbox(value: z.infer<typeof mailbox>): MailAddress {
  return typeof value === 'string' ? value : { address: value.address, name: value.name ?? '' };
}

function recipients(payload: EmailPayload): MailAddress | MailAddress[] {
  if (Array.isArray(payload.to)) return payload.to;
  return payload.to_name ? { address: payload.to, name: payload.to_name } : payload.to;
}

function extraHeaders(payload: EmailPayload): Record<string, string> {
  const allowed: readonly string[] = ALLOWED_EXTRA_HEADERS;
  return Object.fromEntries(Object.entries(payload.headers ?? {}).filter(([name]) => allowed.includes(name)));
}

function decodeAttachments(payload: EmailPayload): MailAttachment[] {
  let total = 0;
  return (payload.attachments ?? []).map((attachment, index) => {
    const content = Buffer.from(attachment.content_base64, 'base64');
    total += content.byteLength;
    if (total > MAX_ATTACHMENT_BYTES) throw permanent(`attachments exceed ${MAX_ATTACHMENT_BYTES} bytes`);
    return { filename: attachment.filename ?? `attachment-${index + 1}`, contentType: attachment.content_type, content };
  });
}

export function createEmailHandler(deps: JobDeps): JobHandler {
  return async (data: unknown, ctx: JobContext) => {
    const job = parseOutboxJob(data, emailPayloadSchema);
    const { payload } = job;
    const markerKey = `sent:email:${job.outbox_id}`;

    const providerMessageId = await withGiveUp(
      ctx,
      async () => {
        const already = await deps.sentMarkers.get(markerKey);
        if (already !== null) {
          ctx.logger.info({ providerMessageId: already }, 'email already sent; skipping SMTP');
          return already;
        }
        const attachments = decodeAttachments(payload);
        try {
          const result = await deps.mailer.send({
            from: payload.from ? toMailbox(payload.from) : deps.settings.mailFrom,
            to: recipients(payload),
            subject: payload.subject,
            ...(payload.html ? { html: payload.html } : {}),
            ...(payload.text ? { text: payload.text } : {}),
            ...(payload.reply_to ? { replyTo: toMailbox(payload.reply_to) } : {}),
            ...(attachments.length > 0 ? { attachments } : {}),
            messageId: `<${job.outbox_id}@${deps.settings.productSlug}.worker>`,
            headers: {
              'X-Entity-Ref-ID': job.outbox_id,
              'X-Reference': `${payload.reference.type}:${payload.reference.id}`,
              ...(payload.locale ? { 'Content-Language': payload.locale } : {}),
              ...extraHeaders(payload),
            },
          });
          await deps.sentMarkers.set(markerKey, result.messageId, SENT_MARKER_TTL_S);
          ctx.logger.info({ providerMessageId: result.messageId, reference: payload.reference }, 'email sent');
          return result.messageId;
        } catch (error) {
          if (isPermanentSmtpError(error)) throw permanent(`SMTP rejected the message: ${errorMessage(error)}`);
          throw error;
        }
      },
      async (error) => {
        await deps.web.reportDelivery(
          { reference: payload.reference, channel: 'email', status: 'failed', error: errorMessage(error) },
          ctx.requestId,
        );
      },
    );

    await deps.web.reportDelivery(
      { reference: payload.reference, channel: 'email', status: 'sent', provider_message_id: providerMessageId },
      ctx.requestId,
    );
    return { providerMessageId };
  };
}

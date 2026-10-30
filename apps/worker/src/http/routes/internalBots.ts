import type { FastifyPluginAsync, FastifyRequest } from 'fastify';
import { SERVICE_HEADER, SIGNATURE_HEADER, verifySignature } from '../../clients/signing.js';
import { errorFields } from '../../logger.js';
import type { BotVerifier } from '../../telegram/verify.js';

export interface InternalBotsDeps {
  secret: string;
  verifier: BotVerifier;
}

function pathWithQuery(request: FastifyRequest): string {
  return request.raw.url ?? request.url;
}

export function internalBotRoutes(deps: InternalBotsDeps): FastifyPluginAsync {
  return async (app) => {
    app.removeAllContentTypeParsers();
    app.addContentTypeParser('*', { parseAs: 'string', bodyLimit: 64 * 1024 }, (_request, body, done) => done(null, body));

    app.post<{ Params: { id: string } }>('/internal/v1/telegram/bots/:id/verify', async (request, reply) => {
      const raw = typeof request.body === 'string' ? request.body : '';
      const service = String(request.headers[SERVICE_HEADER.toLowerCase()] ?? '').trim().toLowerCase();
      const signature = request.headers[SIGNATURE_HEADER.toLowerCase()];
      const check = verifySignature({
        secret: deps.secret,
        header: typeof signature === 'string' ? signature : undefined,
        method: request.method,
        pathWithQuery: pathWithQuery(request),
        body: raw,
      });
      if (!check.ok) return reply.code(401).send({ error: 'invalid_signature' });
      if (service !== 'web') return reply.code(403).send({ error: 'caller_not_allowed' });
      const botId = Number(request.params.id);
      if (!Number.isSafeInteger(botId) || botId <= 0) return reply.code(404).send({ error: 'not_found' });
      try {
        const result = await deps.verifier.verify(botId, typeof request.headers['x-request-id'] === 'string' ? request.headers['x-request-id'] : undefined);
        return reply.send(result);
      } catch (error) {
        request.log.warn(errorFields(error), 'bot verification failed unexpectedly');
        return reply.send({ ok: false, error_code: 'network' });
      }
    });
  };
}

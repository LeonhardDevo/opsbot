import { Composer } from 'grammy';
import { contextSetup, errorBoundary, localeResolver, rateLimit } from './middleware.js';
import type { BotContext, BotServices } from './types.js';

export function createBotComposer(services: BotServices): Composer<BotContext> {
  const composer = new Composer<BotContext>();
  composer.use(contextSetup(services));
  composer.use(errorBoundary());
  composer.use(localeResolver());
  composer.use(rateLimit());
  for (const module of services.modules.modules) {
    const moduleComposer = new Composer<BotContext>();
    module.register?.(moduleComposer);
    composer.use(moduleComposer);
  }
  return composer;
}

import type { BotModule } from '../types.js';
import { assistantModule } from './assistant.js';
import { coreModule } from './core.js';
import { gateModule, opsbotModule } from './opsbot/index.js';

export const domainModules: BotModule[] = [opsbotModule];

export function defaultModules(): BotModule[] {
  return [gateModule, coreModule, ...domainModules, assistantModule];
}

export { assistantModule, coreModule, gateModule, opsbotModule };

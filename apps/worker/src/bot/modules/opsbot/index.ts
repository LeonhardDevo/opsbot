import type { Composer } from 'grammy';
import type { BotContext, BotModule } from '../../types.js';
import { isPrivateChat } from '../../helpers.js';
import { captureAnswer, promptAnswer } from './answerFlow.js';
import {
  confirmUnlink,
  handleAskManager,
  handleHistory,
  handlePrivacy,
  handleQueue,
  handleTasks,
  handleUnlink,
  isManager,
} from './commands.js';
import { privateChatsOnly } from './gates.js';
import { cancelFlowOnCommand, cancelIncident, captureDescription, chooseCategory, confirmIncident, startIncidentFlow } from './incidentFlow.js';
import { acknowledgeNotice, noticeGate } from './notice.js';
import { OPSBOT_TEMPLATES } from './templates.js';

export const gateModule: BotModule = {
  name: 'gates',
  register(composer: Composer<BotContext>) {
    composer.use(privateChatsOnly());
    composer.filter((ctx) => isPrivateChat(ctx)).on('message:text', cancelFlowOnCommand);
    composer.use(noticeGate());
  },
};

export const opsbotModule: BotModule = {
  name: 'opsbot',
  commands: [
    { command: 'history', descriptionKey: 'commands.history', visible: (account) => account !== null },
    { command: 'tasks', descriptionKey: 'commands.tasks', visible: (account) => account !== null },
    {
      command: 'incident',
      descriptionKey: 'commands.incident',
      visible: (account) => account?.permissions.includes('incidents.create') ?? false,
    },
    { command: 'ask_manager', descriptionKey: 'commands.ask_manager', visible: (account) => account !== null },
    { command: 'queue', descriptionKey: 'commands.queue', visible: (account) => isManager(account) },
    { command: 'privacy', descriptionKey: 'commands.privacy' },
    { command: 'unlink', descriptionKey: 'commands.unlink', visible: (account) => account !== null },
  ],
  actions: {
    notice_ack: acknowledgeNotice,
    q_answer: promptAnswer,
    flow_incident_start: async (ctx) => startIncidentFlow(ctx),
    flow_incident_cat: chooseCategory,
    flow_incident_confirm: async (ctx) => confirmIncident(ctx),
    flow_incident_cancel: async (ctx) => cancelIncident(ctx),
    unlink_confirm: async (ctx) => confirmUnlink(ctx),
  },
  notifications: OPSBOT_TEMPLATES,
  register(composer: Composer<BotContext>) {
    const privateOnly = composer.filter((ctx) => isPrivateChat(ctx));
    privateOnly.on('message:text', captureAnswer);
    privateOnly.on('message:text', captureDescription);
    privateOnly.command('history', handleHistory);
    privateOnly.command('tasks', handleTasks);
    privateOnly.command('incident', startIncidentFlow);
    privateOnly.command('ask_manager', handleAskManager);
    privateOnly.command('queue', handleQueue);
    privateOnly.command('privacy', handlePrivacy);
    privateOnly.command('unlink', handleUnlink);
  },
};

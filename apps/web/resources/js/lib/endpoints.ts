import type { UrlMethodPair } from '@inertiajs/core';
import {
    assistant as assistantIndex,
    dashboard,
    home,
    legal,
    pricing,
    security,
} from '@/routes';
import { ai as adminAiIndex } from '@/routes/admin';
import {
    keys as adminAiKeys,
    models as adminAiModels,
    settings as adminAiSettings,
    test as adminAiTest,
} from '@/routes/admin/ai';
import {
    actions as assistantActions,
    ask as assistantAsk,
    history as assistantHistory,
} from '@/routes/assistant';
import { index as analyticsIndex } from '@/routes/analytics';
import {
    exportMethod as auditExport,
    index as auditIndex,
} from '@/routes/audit';
import {
    destroy as botDestroy,
    index as botIndex,
    store as botStore,
    testMessage as botTestMessage,
    token as botToken,
} from '@/routes/bot';
import {
    destroy as departmentDestroy,
    index as departmentsIndex,
    routing as departmentsRouting,
    store as departmentStore,
    update as departmentUpdate,
} from '@/routes/departments';
import { feed as deskFeed } from '@/routes/desk';
import {
    destroy as employeeDestroy,
    erase as employeeErase,
    exportMethod as employeeExport,
    index as employeesIndex,
    link as employeeLink,
    reactivate as employeeReactivate,
    store as employeeStore,
    update as employeeUpdate,
} from '@/routes/employees';
import { bulk as employeeLinksBulk } from '@/routes/employees/links';
import {
    comments as incidentComments,
    index as incidentsIndex,
    show as incidentShow,
    store as incidentStore,
    tasks as incidentTasks,
    update as incidentUpdate,
} from '@/routes/incidents';
import { fakeCheckout } from '@/routes/dev';
import { pay as fakeCheckoutPay } from '@/routes/dev/fake-checkout';
import {
    accept as invitationAccept,
    show as invitationShow,
} from '@/routes/invitations';
import {
    approve as knowledgeApprove,
    approveBulk as knowledgeApproveBulk,
    archive as knowledgeArchive,
    destroy as knowledgeDestroy,
    download as knowledgeDownload,
    index as knowledgeIndex,
    retry as knowledgeRetry,
    show as knowledgeShow,
    starterPack as knowledgeStarterPack,
    store as knowledgeStore,
    test as knowledgeTest,
    update as knowledgeUpdate,
} from '@/routes/knowledge';
import { update as localeUpdate } from '@/routes/locale';
import { store as passwordConfirmStore } from '@/routes/password/confirm';
import { read as notificationsRead } from '@/routes/notifications';
import {
    setup as onboardingSetup,
    workspace as onboardingWorkspace,
} from '@/routes/onboarding';
import {
    finish as onboardingFinish,
    skip as onboardingSkip,
} from '@/routes/onboarding/setup';
import { preview as routingPreview } from '@/routes/routing';
import { store as onboardingStore } from '@/routes/onboarding/workspace';
import {
    assistant as settingsAssistant,
    billing as settingsBilling,
    integrations as settingsIntegrations,
    members as settingsMembers,
    workspace as settingsWorkspace,
} from '@/routes/settings';
import { update as settingsAssistantUpdate } from '@/routes/settings/assistant';
import {
    cancel as billingCancel,
    changePlan as billingChangePlan,
    checkout as billingCheckout,
    portal as billingPortal,
    resume as billingResume,
} from '@/routes/settings/billing';
import {
    link as telegramLink,
    unlink as telegramUnlink,
} from '@/routes/settings/integrations/telegram';
import {
    destroy as memberDestroy,
    update as memberUpdate,
} from '@/routes/settings/members';
import {
    destroy as invitationDestroy,
    store as invitationStore,
} from '@/routes/settings/members/invitations';
import {
    destroy as workspaceDestroy,
    update as workspaceUpdate,
} from '@/routes/settings/workspace';
import {
    index as tasksIndex,
    store as taskStore,
    update as taskUpdate,
} from '@/routes/tasks';
import {
    answer as questionAnswer,
    convert as questionConvert,
    dismiss as questionDismiss,
    index as unansweredIndex,
    update as questionUpdate,
} from '@/routes/unanswered';
import { switchMethod as workspaceSwitch } from '@/routes/workspaces';
import type { Locale } from '@/types/shared';

export type Endpoint = UrlMethodPair;

export type QueryValue =
    | string
    | number
    | boolean
    | null
    | undefined
    | Array<string | number>;

export function withQuery(
    url: string,
    query: Record<string, QueryValue>,
): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        if (Array.isArray(value)) {
            value.forEach((item) => params.append(`${key}[]`, String(item)));
            continue;
        }

        params.set(key, String(value));
    }

    const qs = params.toString();

    if (!qs) {
        return url;
    }

    return `${url}${url.includes('?') ? '&' : '?'}${qs}`;
}

function raw(url: string, method: Endpoint['method'] = 'get'): Endpoint {
    return { url, method };
}

export const endpoints = {
    home: () => home(),
    pricing: () => pricing(),
    security: () => security(),
    legal: (page: string) => legal(page),
    legalVersion: (doc: string, version?: string | null) =>
        version ? legal(doc, { query: { version } }) : legal(doc),

    legalPages: {
        index: () => raw('/legal'),
        accept: () => raw('/legal/accept'),
        acceptStore: () => raw('/legal/accept', 'post'),
    },
    privacy: {
        show: () => raw('/settings/privacy'),
        export: () => raw('/settings/privacy/export', 'post'),
        scheduleDeletion: () => raw('/settings/privacy/delete-account', 'post'),
        cancelDeletion: () => raw('/settings/privacy/delete-account', 'delete'),
        updateWorkspace: () => raw('/settings/privacy/workspace', 'patch'),
        exportWorkspace: () => raw('/settings/workspace/export', 'post'),
        scheduleWorkspaceDeletion: () =>
            raw('/settings/workspace/delete', 'post'),
        cancelWorkspaceDeletion: () =>
            raw('/settings/workspace/delete', 'delete'),
    },
    locale: (locale: Locale) => localeUpdate(locale),
    confirmPassword: () => passwordConfirmStore(),

    dashboard: () => dashboard(),

    onboarding: {
        show: () => onboardingWorkspace(),
        create: () => onboardingWorkspace({ query: { new: 1 } }),
        store: () => onboardingStore(),
        setup: () => onboardingSetup(),
        skip: () => onboardingSkip(),
        finish: () => onboardingFinish(),
    },

    desk: {
        feed: () => deskFeed(),
    },

    bot: {
        index: () => botIndex(),
        connect: () => botStore(),
        rotate: () => botToken(),
        disconnect: () => botDestroy(),
        testMessage: () => botTestMessage(),
    },

    employees: {
        index: () => employeesIndex(),
        store: () => employeeStore(),
        update: (userId: number) => employeeUpdate(userId),
        offboard: (userId: number) => employeeDestroy(userId),
        link: (userId: number) => employeeLink(userId),
        bulkLinks: () => employeeLinksBulk(),
        reactivate: (userId: number) => employeeReactivate(userId),
        export: (userId: number) => employeeExport(userId),
        erase: (userId: number) => employeeErase(userId),
    },

    departments: {
        index: () => departmentsIndex(),
        store: () => departmentStore(),
        update: (id: number) => departmentUpdate(id),
        archive: (id: number) => departmentDestroy(id),
        routing: () => departmentsRouting(),
        preview: () => routingPreview(),
    },

    incidents: {
        index: () => incidentsIndex(),
        store: () => incidentStore(),
        show: (id: number) => incidentShow(id),
        update: (id: number) => incidentUpdate(id),
        comment: (id: number) => incidentComments(id),
        createTask: (id: number) => incidentTasks(id),
    },

    tasks: {
        index: () => tasksIndex(),
        store: () => taskStore(),
        update: (id: number) => taskUpdate(id),
    },

    unanswered: {
        index: () => unansweredIndex(),
        answer: (id: number) => questionAnswer(id),
        dismiss: (id: number) => questionDismiss(id),
        convert: (id: number) => questionConvert(id),
        reassign: (id: number) => questionUpdate(id),
    },

    analytics: {
        index: () => analyticsIndex(),
    },

    workspaces: {
        switch: (workspaceId: number) => workspaceSwitch(workspaceId),
    },

    settings: {
        workspace: {
            show: () => settingsWorkspace(),
            update: () => workspaceUpdate(),
            destroy: () => workspaceDestroy(),
        },
        members: {
            index: () => settingsMembers(),
            invite: () => invitationStore(),
            revokeInvitation: (invitationId: number) =>
                invitationDestroy(invitationId),
            updateRole: (userId: number) => memberUpdate(userId),
            remove: (userId: number) => memberDestroy(userId),
        },
        billing: {
            show: () => settingsBilling(),
            checkout: () => billingCheckout(),
            portal: () => billingPortal(),
            changePlan: () => billingChangePlan(),
            cancel: () => billingCancel(),
            resume: () => billingResume(),
        },
        assistant: {
            show: () => settingsAssistant(),
            update: () => settingsAssistantUpdate(),
        },
        integrations: {
            show: () => settingsIntegrations(),
            telegramLink: () => telegramLink(),
            telegramUnlink: () => telegramUnlink(),
        },
    },

    invitations: {
        show: (token: string) => invitationShow(token),
        accept: (token: string) => invitationAccept(token),
    },

    assistant: {
        index: () => assistantIndex(),
        ask: () => assistantAsk(),
        action: (actionId: string) => assistantActions(actionId),
        history: () => assistantHistory(),
    },

    knowledge: {
        index: () => knowledgeIndex(),
        store: () => knowledgeStore(),
        show: (documentId: number) => knowledgeShow(documentId),
        update: (documentId: number) => knowledgeUpdate(documentId),
        destroy: (documentId: number) => knowledgeDestroy(documentId),
        download: (documentId: number) => knowledgeDownload(documentId),
        starterPack: () => knowledgeStarterPack(),
        approve: (documentId: number) => knowledgeApprove(documentId),
        approveBulk: () => knowledgeApproveBulk(),
        archive: (documentId: number) => knowledgeArchive(documentId),
        retry: (documentId: number) => knowledgeRetry(documentId),
        test: (documentId: number) => knowledgeTest(documentId),
    },

    audit: {
        index: () => auditIndex(),
        export: () => auditExport(),
    },

    notifications: {
        read: () => notificationsRead(),
    },

    admin: {
        ai: {
            index: () => adminAiIndex(),
            settings: () => adminAiSettings(),
            key: (provider: string) => adminAiKeys(provider),
            models: (provider: string) => adminAiModels(provider),
            test: () => adminAiTest(),
        },
    },

    dev: {
        fakeCheckout: (session: string) => fakeCheckout(session),
        fakeCheckoutPay: (session: string) => fakeCheckoutPay(session),
    },
} as const;

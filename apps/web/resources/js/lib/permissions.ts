import type { WorkspaceRole } from '@/types/opsbot';

const MANAGER: readonly string[] = [
    'employees.manage',
    'knowledge.view',
    'knowledge.manage',
    'assistant.use',
    'assistant.history',
    'ai_queries.view_department',
    'incidents.create',
    'incidents.view_own',
    'incidents.view_department',
    'incidents.manage',
    'tasks.create',
    'tasks.view_own',
    'tasks.view_department',
    'tasks.manage',
    'questions.answer',
    'analytics.view',
    'integrations.manage',
];

const ADMIN: readonly string[] = [
    'workspace.manage',
    'members.manage',
    'billing.view',
    'audit.view',
    'settings.assistant',
    'bot.manage',
    'departments.manage',
    'employees.manage',
    'knowledge.view',
    'knowledge.view_all',
    'knowledge.manage',
    'knowledge.approve',
    'assistant.use',
    'assistant.history',
    'ai_queries.view_all',
    'incidents.create',
    'incidents.view_own',
    'incidents.view_all',
    'incidents.manage',
    'tasks.create',
    'tasks.view_own',
    'tasks.view_all',
    'tasks.manage',
    'questions.answer',
    'analytics.view',
    'integrations.manage',
];

export const ROLE_ABILITIES: Readonly<
    Record<WorkspaceRole, readonly string[]>
> = {
    owner: [...ADMIN, 'workspace.delete', 'billing.manage'],
    admin: ADMIN,
    manager: MANAGER,
    employee: [
        'assistant.use',
        'assistant.history',
        'incidents.create',
        'incidents.view_own',
        'tasks.create',
        'tasks.view_own',
        'integrations.manage',
    ],
};

export function isAbilityMap(
    map: Record<string, unknown> | null | undefined,
): boolean {
    return Object.keys(map ?? {}).some((key) => key.includes('.'));
}

export function roleAllows(
    role: string | null | undefined,
    ability: string,
): boolean {
    if (!role || !(role in ROLE_ABILITIES)) {
        return false;
    }

    return ROLE_ABILITIES[role as WorkspaceRole].includes(ability);
}

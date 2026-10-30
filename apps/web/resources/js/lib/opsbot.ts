import type {
    Department,
    DeskItem,
    IncidentCategory,
    IncidentStatus,
    LimitEntry,
    RoutingCategory,
    RoutingRule,
    RoutingSource,
    Severity,
    TaskStatus,
    UserRef,
} from '@/types/opsbot';

export const INCIDENT_CATEGORIES: readonly IncidentCategory[] = [
    'damage',
    'complaint',
    'no_access',
    'injury',
    'lost_item',
    'other',
];

export const ROUTING_CATEGORIES: readonly RoutingCategory[] = [
    ...INCIDENT_CATEGORIES,
    'question',
    'task',
];

export const SEVERITIES: readonly Severity[] = ['high', 'medium', 'low'];

export const INCIDENT_STATUSES: readonly IncidentStatus[] = [
    'open',
    'in_progress',
    'resolved',
    'closed',
];

export const TASK_STATUSES: readonly TaskStatus[] = [
    'todo',
    'in_progress',
    'done',
    'cancelled',
];

export function defaultSeverity(category: IncidentCategory): Severity {
    switch (category) {
        case 'injury':
            return 'high';
        case 'damage':
        case 'complaint':
        case 'lost_item':
            return 'medium';
        default:
            return 'low';
    }
}

export type IncidentActorContext = { manage: boolean; assignee: boolean };

export function incidentTransitions(
    from: IncidentStatus,
    actor: IncidentActorContext,
): IncidentStatus[] {
    const allowed: IncidentStatus[] = [];

    if (from === 'open' && (actor.manage || actor.assignee)) {
        allowed.push('in_progress');
    }

    if (from === 'resolved' && actor.manage) {
        allowed.push('in_progress');
    }

    if (
        (from === 'open' || from === 'in_progress') &&
        (actor.manage || actor.assignee)
    ) {
        allowed.push('resolved');
    }

    if (from !== 'closed' && actor.manage) {
        allowed.push('closed');
    }

    return allowed;
}

export function incidentCommentRequired(
    from: IncidentStatus,
    to: IncidentStatus,
): boolean {
    return (
        (from === 'resolved' && to === 'in_progress') ||
        ((from === 'open' || from === 'in_progress') && to === 'closed')
    );
}

export type TaskActorContext = {
    manage: boolean;
    assignee: boolean;
    creator: boolean;
};

export function taskTransitions(
    from: TaskStatus,
    actor: TaskActorContext,
): TaskStatus[] {
    const allowed: TaskStatus[] = [];
    const open = from === 'todo' || from === 'in_progress';

    if (from === 'todo' && (actor.assignee || actor.manage)) {
        allowed.push('in_progress');
    }

    if (open && (actor.assignee || actor.creator || actor.manage)) {
        allowed.push('done');
    }

    if (open && actor.manage) {
        allowed.push('cancelled');
    }

    if (from === 'done' && actor.manage) {
        allowed.push('todo');
    }

    return allowed;
}

export type MeterLevel = 'ok' | 'warn' | 'full' | 'unlimited';

export function meterLevel(entry: LimitEntry | null | undefined): MeterLevel {
    if (!entry || entry.limit === null) {
        return 'unlimited';
    }

    if (entry.limit <= 0 || entry.used >= entry.limit) {
        return 'full';
    }

    return entry.used / entry.limit >= 0.8 ? 'warn' : 'ok';
}

export function meterRatio(entry: LimitEntry | null | undefined): number {
    if (!entry || entry.limit === null) {
        return 0;
    }

    if (entry.limit <= 0) {
        return 1;
    }

    return Math.min(1, Math.max(0, entry.used / entry.limit));
}

export type ResolvedRoute = { user: UserRef | null; source: RoutingSource };

export function resolveRoute(
    rules: ReadonlyArray<
        Pick<RoutingRule, 'department_id' | 'category' | 'manager'>
    >,
    departments: ReadonlyArray<Pick<Department, 'id' | 'manager'>>,
    fallbackOwner: UserRef | null,
    departmentId: number | null,
    category: RoutingCategory,
): ResolvedRoute {
    const specific =
        departmentId !== null
            ? rules.find(
                  (rule) =>
                      rule.category === category &&
                      rule.department_id === departmentId,
              )
            : undefined;

    if (specific) {
        return { user: specific.manager, source: 'rule' };
    }

    const any = rules.find(
        (rule) => rule.category === category && rule.department_id === null,
    );

    if (any) {
        return { user: any.manager, source: 'rule' };
    }

    const department =
        departmentId !== null
            ? departments.find((item) => item.id === departmentId)
            : undefined;

    if (department?.manager) {
        return { user: department.manager, source: 'department_manager' };
    }

    return { user: fallbackOwner, source: 'workspace_owner' };
}

export type RuleCell = {
    department_id: number | null;
    category: RoutingCategory;
    manager_user_id: number;
};

export function cellKey(
    departmentId: number | null,
    category: RoutingCategory,
): string {
    return `${departmentId ?? 'any'}:${category}`;
}

export function rulesToCells(
    rules: ReadonlyArray<
        Pick<RoutingRule, 'department_id' | 'category' | 'manager'>
    >,
): Record<string, number> {
    const cells: Record<string, number> = {};

    for (const rule of rules) {
        cells[cellKey(rule.department_id, rule.category)] = rule.manager.id;
    }

    return cells;
}

export function cellsToRules(cells: Record<string, number | null>): RuleCell[] {
    const result: RuleCell[] = [];

    for (const [key, manager] of Object.entries(cells)) {
        if (manager === null || manager === undefined) {
            continue;
        }

        const [department, category] = key.split(':');

        result.push({
            department_id: department === 'any' ? null : Number(department),
            category: category as RoutingCategory,
            manager_user_id: manager,
        });
    }

    return result.sort(
        (a, b) =>
            (a.department_id ?? 0) - (b.department_id ?? 0) ||
            ROUTING_CATEGORIES.indexOf(a.category) -
                ROUTING_CATEGORIES.indexOf(b.category),
    );
}

export function hoursBetween(from: Date, to: Date): number {
    return Math.floor(Math.abs(to.getTime() - from.getTime()) / 3_600_000);
}

export type DueState = {
    overdue: boolean;
    hours: number;
    days: number;
};

export function dueState(
    dueAt: string | null,
    now: Date = new Date(),
): DueState | null {
    if (!dueAt) {
        return null;
    }

    const due = new Date(dueAt);

    if (Number.isNaN(due.getTime())) {
        return null;
    }

    const overdue = due.getTime() < now.getTime();
    const hours = hoursBetween(due, now);

    return { overdue, hours, days: Math.floor(hours / 24) };
}

export function greetingKey(
    hour: number,
): 'morning' | 'afternoon' | 'evening' | 'night' {
    if (hour >= 5 && hour < 12) {
        return 'morning';
    }

    if (hour >= 12 && hour < 18) {
        return 'afternoon';
    }

    if (hour >= 18 && hour < 23) {
        return 'evening';
    }

    return 'night';
}

export function hourIn(
    timeZone: string | null | undefined,
    now: Date = new Date(),
): number {
    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            hour: 'numeric',
            hourCycle: 'h23',
            timeZone: timeZone ?? undefined,
        }).formatToParts(now);
        const hour = parts.find((part) => part.type === 'hour')?.value;

        return hour ? Number(hour) % 24 : now.getHours();
    } catch {
        return now.getHours();
    }
}

export function mergeDesk(
    current: readonly DeskItem[],
    incoming: readonly DeskItem[],
    limit = 60,
): DeskItem[] {
    const byId = new Map<string, DeskItem>();

    for (const item of [...current, ...incoming]) {
        byId.set(item.id, item);
    }

    return [...byId.values()]
        .sort((a, b) => a.at.localeCompare(b.at) || a.id.localeCompare(b.id))
        .slice(-limit);
}

export function deskTarget(
    item: Pick<DeskItem, 'id'>,
): { kind: string; id: number } | null {
    const [kind, raw] = item.id.split(':');
    const id = Number(raw);

    return kind && Number.isInteger(id) && id > 0 ? { kind, id } : null;
}

export function quietMinutes(
    start: string,
    end: string,
): Array<[number, number]> {
    const toMinutes = (value: string): number => {
        const [h, m] = value.split(':').map(Number);

        return (h % 24) * 60 + (m % 60);
    };
    const from = toMinutes(start);
    const to = toMinutes(end);

    if (from === to) {
        return [];
    }

    return from < to
        ? [[from, to]]
        : [
              [from, 1440],
              [0, to],
          ];
}

export function isValidTime(value: string): boolean {
    return /^([01]\d|2[0-3]):[0-5]\d$/.test(value);
}

export function minorToMajor(amount: number, currency: string): number {
    return currency === 'JPY' ? amount : amount / 100;
}

export function percentDelta(delta: number | null): number | null {
    return delta === null ? null : Math.round(delta * 100);
}

export function displayLatency(ms: number | null, locale: 'ru' | 'en'): string {
    if (ms === null || Number.isNaN(ms)) {
        return '—';
    }

    const intl = locale === 'ru' ? 'ru-RU' : 'en-US';

    if (ms < 1000) {
        return `${new Intl.NumberFormat(intl, { maximumFractionDigits: 0 }).format(ms)} ${locale === 'ru' ? 'мс' : 'ms'}`;
    }

    return `${new Intl.NumberFormat(intl, { maximumFractionDigits: 1 }).format(ms / 1000)} ${locale === 'ru' ? 'с' : 's'}`;
}

export function bucketToday(items: readonly DeskItem[]): {
    attention: DeskItem[];
    today: DeskItem[];
} {
    return {
        attention: items.filter((item) => item.kind !== 'task_overdue'),
        today: items.filter((item) => item.kind === 'task_overdue'),
    };
}

export function toLocalInput(
    iso: string | null,
    timeZone: string | null | undefined,
): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    try {
        const parts = Object.fromEntries(
            new Intl.DateTimeFormat('en-CA', {
                timeZone: timeZone ?? undefined,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
            })
                .formatToParts(date)
                .map((part) => [part.type, part.value]),
        );

        return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
    } catch {
        return iso.slice(0, 16);
    }
}

export function zoneOffsetLabel(
    timeZone: string | null | undefined,
    now: Date = new Date(),
): string {
    try {
        const part = new Intl.DateTimeFormat('en-US', {
            timeZone: timeZone ?? undefined,
            timeZoneName: 'shortOffset',
        })
            .formatToParts(now)
            .find((item) => item.type === 'timeZoneName')?.value;

        return part === 'GMT' ? 'UTC' : (part ?? 'UTC').replace('GMT', 'UTC');
    } catch {
        return 'UTC';
    }
}

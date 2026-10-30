import { describe, expect, it } from 'vitest';
import {
    activeNavItem,
    appNavigation,
    isNavItemActive,
    railItems,
    settingsNavigation,
    tabItems,
} from '@/lib/navigation';
import { pickActiveScene } from '@/components/landing/story';
import { toUrl } from '@/lib/utils';
import type { Ability } from '@/types';

const canWith =
    (granted: Ability[]) =>
    (ability: Ability, ...fallbacks: Ability[]) =>
        [ability, ...fallbacks].some((key) => granted.includes(key));

const urls = (groups: ReturnType<typeof appNavigation>) =>
    groups.flatMap((group) => group.items.map((item) => toUrl(item.href)));

const manager: Ability[] = [
    'assistant.use',
    'assistant.history',
    'knowledge.view',
    'incidents.view_own',
    'tasks.view_own',
    'questions.answer',
    'employees.manage',
    'analytics.view',
];

const owner: Ability[] = [
    ...manager,
    'departments.manage',
    'bot.manage',
    'audit.view',
    'admin.ai',
];

describe('appNavigation', () => {
    it('shows an employee only their own work', () => {
        const employee = urls(
            appNavigation(
                canWith([
                    'assistant.use',
                    'incidents.view_own',
                    'tasks.view_own',
                ]),
                true,
            ),
        );

        expect(employee).toEqual([
            '/dashboard',
            '/incidents',
            '/tasks',
            '/assistant',
            '/settings/workspace',
        ]);
    });

    it('adds team, bot, analytics and audit sections for owners', () => {
        expect(urls(appNavigation(canWith(owner), true))).toEqual([
            '/dashboard',
            '/incidents',
            '/tasks',
            '/unanswered',
            '/assistant',
            '/knowledge',
            '/employees',
            '/departments',
            '/bot',
            '/analytics',
            '/assistant/history',
            '/audit',
            '/settings/workspace',
            '/admin/ai',
        ]);
    });

    it('puts scoped badge counts on incidents, tasks and questions', () => {
        const items = railItems(
            appNavigation(canWith(manager), true, {
                incidents_open: 3,
                tasks_overdue: 0,
                unanswered_open: 7,
            }),
        );
        const badges = Object.fromEntries(
            items.map((item) => [toUrl(item.href), item.badge ?? null]),
        );

        expect(badges['/incidents']).toBe(3);
        expect(badges['/tasks']).toBeNull();
        expect(badges['/unanswered']).toBe(7);
    });

    it('only offers account settings without a workspace', () => {
        const groups = appNavigation(canWith([]), false);

        expect(groups).toHaveLength(1);
        expect(urls(groups)).toEqual(['/settings/profile']);
    });
});

describe('settingsNavigation', () => {
    it('includes billing only for users who can see it', () => {
        const without = settingsNavigation(canWith([]), true).flatMap((group) =>
            group.items.map((item) => toUrl(item.href)),
        );
        const withBilling = settingsNavigation(
            canWith(['billing.view']),
            true,
        ).flatMap((group) => group.items.map((item) => toUrl(item.href)));

        expect(without).not.toContain('/settings/billing');
        expect(withBilling).toContain('/settings/billing');
        expect(withBilling).toContain('/settings/profile');
    });

    it('hides workspace settings without a workspace', () => {
        const groups = settingsNavigation(canWith(['billing.view']), false);

        expect(groups).toHaveLength(1);
        expect(groups[0].label).toBe('ui.settings.nav.group_account');
    });
});

describe('shell navigation', () => {
    const everything = canWith(owner);

    it('flattens the groups into rail tiles with short captions', () => {
        const items = railItems(appNavigation(everything, true));

        expect(
            items.every((item) => item.short?.startsWith('ui.nav.short.')),
        ).toBe(true);
        expect(items[0].short).toBe('ui.nav.short.dashboard');
    });

    it('puts desk, incidents, tasks and questions in the phone tab bar', () => {
        const { tabs, more } = tabItems(
            railItems(appNavigation(everything, true)),
        );

        expect(tabs.map((item) => toUrl(item.href))).toEqual([
            '/dashboard',
            '/incidents',
            '/tasks',
            '/unanswered',
        ]);
        expect(more.map((item) => toUrl(item.href))).toContain('/assistant');
        expect(more.map((item) => toUrl(item.href))).toContain('/bot');
    });

    it('marks the section that owns the current path', () => {
        const items = railItems(appNavigation(everything, true));

        expect(activeNavItem(items, '/knowledge/12')?.short).toBe(
            'ui.nav.short.knowledge',
        );
        expect(activeNavItem(items, '/incidents/4')?.short).toBe(
            'ui.nav.short.incidents',
        );
        expect(activeNavItem(items, '/settings/billing')?.short).toBe(
            'ui.nav.short.settings',
        );
        expect(activeNavItem(items, '/assistant/history')?.short).toBe(
            'ui.nav.short.history',
        );
        expect(activeNavItem(items, '/nowhere')).toBeNull();

        const dashboard = items.find(
            (item) => item.short === 'ui.nav.short.dashboard',
        );

        expect(dashboard).toBeDefined();

        if (dashboard) {
            expect(isNavItemActive(dashboard, '/dashboard')).toBe(true);
            expect(isNavItemActive(dashboard, '/incidents')).toBe(false);
        }
    });
});

describe('landing chat story', () => {
    it('shows the most visible scene and keeps the last one when none is visible', () => {
        expect(
            pickActiveScene(
                [
                    { key: 'answer', ratio: 0.2, top: 10 },
                    { key: 'source', ratio: 0.9, top: 400 },
                ],
                'answer',
            ),
        ).toBe('source');
        expect(
            pickActiveScene(
                [
                    { key: 'refusal', ratio: 0.5, top: 300 },
                    { key: 'manager', ratio: 0.5, top: 100 },
                ],
                'answer',
            ),
        ).toBe('manager');
        expect(
            pickActiveScene([{ key: 'answer', ratio: 0, top: 0 }], 'incident'),
        ).toBe('incident');
    });
});

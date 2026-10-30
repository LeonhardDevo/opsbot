import {
    BookOpen,
    Bot,
    Building,
    ChartColumn,
    Cpu,
    CreditCard,
    History,
    Gauge,
    LayoutDashboard,
    ListChecks,
    MessageCircleQuestionMark,
    MessagesSquare,
    Palette,
    PlugZap,
    ScrollText,
    Settings,
    ShieldCheck,
    Siren,
    ShieldUser,
    SlidersHorizontal,
    UserRound,
    Users,
} from '@lucide/vue';
import { endpoints } from '@/lib/endpoints';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavGroup, NavItem } from '@/types/navigation';
import type { Ability } from '@/types/shared';

export type CanFn = (ability: Ability, ...fallbacks: Ability[]) => boolean;

export type NavBadges = {
    unanswered_open?: number;
    incidents_open?: number;
    tasks_overdue?: number;
};

function badge(value: number | undefined): number | null {
    return value && value > 0 ? value : null;
}

export function appNavigation(
    can: CanFn,
    hasWorkspace: boolean,
    badges: NavBadges = {},
): NavGroup[] {
    const groups: NavGroup[] = [];

    if (hasWorkspace) {
        const work: NavItem[] = [
            {
                title: 'ui.nav.dashboard',
                short: 'ui.nav.short.dashboard',
                href: endpoints.dashboard(),
                icon: LayoutDashboard,
                tab: true,
            },
        ];

        if (can('incidents.view_own')) {
            work.push({
                title: 'ui.nav.incidents',
                short: 'ui.nav.short.incidents',
                href: endpoints.incidents.index(),
                icon: Siren,
                tab: true,
                matchPrefix: true,
                badge: badge(badges.incidents_open),
            });
        }

        if (can('tasks.view_own')) {
            work.push({
                title: 'ui.nav.tasks',
                short: 'ui.nav.short.tasks',
                href: endpoints.tasks.index(),
                icon: ListChecks,
                tab: true,
                matchPrefix: true,
                badge: badge(badges.tasks_overdue),
            });
        }

        if (can('questions.answer')) {
            work.push({
                title: 'ui.nav.unanswered',
                short: 'ui.nav.short.unanswered',
                href: endpoints.unanswered.index(),
                icon: MessageCircleQuestionMark,
                tab: true,
                matchPrefix: true,
                badge: badge(badges.unanswered_open),
            });
        }

        if (can('assistant.use')) {
            work.push({
                title: 'ui.nav.assistant',
                short: 'ui.nav.short.assistant',
                href: endpoints.assistant.index(),
                icon: MessagesSquare,
                tab: true,
            });
        }

        if (can('knowledge.view')) {
            work.push({
                title: 'ui.nav.knowledge',
                short: 'ui.nav.short.knowledge',
                href: endpoints.knowledge.index(),
                icon: BookOpen,
                matchPrefix: true,
            });
        }

        groups.push({ label: 'ui.nav.group_workspace', items: work });

        const team: NavItem[] = [];

        if (can('employees.manage')) {
            team.push({
                title: 'ui.nav.employees',
                short: 'ui.nav.short.employees',
                href: endpoints.employees.index(),
                icon: Users,
                matchPrefix: true,
            });
        }

        if (can('departments.manage')) {
            team.push({
                title: 'ui.nav.departments',
                short: 'ui.nav.short.departments',
                href: endpoints.departments.index(),
                icon: Building,
                matchPrefix: true,
            });
        }

        if (can('bot.manage')) {
            team.push({
                title: 'ui.nav.bot',
                short: 'ui.nav.short.bot',
                href: endpoints.bot.index(),
                icon: Bot,
            });
        }

        if (can('analytics.view')) {
            team.push({
                title: 'ui.nav.analytics',
                short: 'ui.nav.short.analytics',
                href: endpoints.analytics.index(),
                icon: ChartColumn,
            });
        }

        if (can('assistant.history')) {
            team.push({
                title: 'ui.nav.history',
                short: 'ui.nav.short.history',
                href: endpoints.assistant.history(),
                icon: History,
            });
        }

        if (can('audit.view')) {
            team.push({
                title: 'ui.nav.audit',
                short: 'ui.nav.short.audit',
                href: endpoints.audit.index(),
                icon: ScrollText,
            });
        }

        if (team.length) {
            groups.push({ label: 'ui.nav.group_team', items: team });
        }
    }

    groups.push({
        label: 'ui.nav.group_manage',
        items: [
            {
                title: 'ui.nav.settings',
                short: 'ui.nav.short.settings',
                activePrefix: '/settings',
                href: hasWorkspace
                    ? endpoints.settings.workspace.show()
                    : editProfile(),
                icon: Settings,
                matchPrefix: true,
            },
        ],
    });

    if (can('admin.ai')) {
        groups.push({
            label: 'ui.nav.group_admin',
            items: [
                {
                    title: 'ui.nav.admin_ai',
                    short: 'ui.nav.short.admin_ai',
                    href: endpoints.admin.ai.index(),
                    icon: Cpu,
                    matchPrefix: true,
                },
            ],
        });
    }

    return groups;
}

export function settingsNavigation(
    can: CanFn,
    hasWorkspace: boolean,
): NavGroup[] {
    const account: NavItem[] = [
        {
            title: 'ui.settings.nav.profile',
            href: editProfile(),
            icon: UserRound,
        },
        {
            title: 'ui.settings.nav.security',
            href: editSecurity(),
            icon: ShieldCheck,
        },
        {
            title: 'ui.settings.nav.appearance',
            href: editAppearance(),
            icon: Palette,
        },
        {
            title: 'ui.settings.nav.privacy',
            href: endpoints.privacy.show(),
            icon: ShieldUser,
        },
    ];

    const workspace: NavItem[] = hasWorkspace
        ? [
              {
                  title: 'ui.settings.nav.workspace',
                  href: endpoints.settings.workspace.show(),
                  icon: SlidersHorizontal,
              },
              {
                  title: 'ui.settings.nav.members',
                  href: endpoints.settings.members.index(),
                  icon: Users,
              },
              ...(can('settings.assistant')
                  ? [
                        {
                            title: 'ui.settings.nav.assistant',
                            href: endpoints.settings.assistant.show(),
                            icon: Gauge,
                        },
                    ]
                  : []),
              ...(can('billing.view', 'billing.manage')
                  ? [
                        {
                            title: 'ui.settings.nav.billing',
                            href: endpoints.settings.billing.show(),
                            icon: CreditCard,
                        },
                    ]
                  : []),
              {
                  title: 'ui.settings.nav.integrations',
                  href: endpoints.settings.integrations.show(),
                  icon: PlugZap,
              },
          ]
        : [];

    return [
        ...(workspace.length
            ? [{ label: 'ui.settings.nav.group_workspace', items: workspace }]
            : []),
        { label: 'ui.settings.nav.group_account', items: account },
    ];
}

export function railItems(groups: NavGroup[]): NavItem[] {
    return groups.flatMap((group) => group.items);
}

export function tabItems(
    items: NavItem[],
    limit = 4,
): {
    tabs: NavItem[];
    more: NavItem[];
} {
    const tabs = items.filter((item) => item.tab).slice(0, limit);

    return { tabs, more: items.filter((item) => !tabs.includes(item)) };
}

function pathOf(href: NavItem['href']): string {
    const url = toUrl(href);

    if (!url.startsWith('http')) {
        return url.split(/[?#]/)[0] ?? url;
    }

    try {
        return new URL(url).pathname;
    } catch {
        return url;
    }
}

export function isNavItemActive(item: NavItem, currentPath: string): boolean {
    if (item.activePrefix && currentPath.startsWith(item.activePrefix)) {
        return true;
    }

    const path = pathOf(item.href);

    return item.matchPrefix
        ? currentPath.startsWith(path)
        : currentPath === path;
}

export function activeNavItem(
    items: NavItem[],
    currentPath: string,
): NavItem | null {
    return items.find((item) => isNavItemActive(item, currentPath)) ?? null;
}

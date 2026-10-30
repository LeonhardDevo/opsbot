import type {
    LimitKey,
    SharedBot,
    SubscriptionState,
    WorkspaceRole,
} from '@/types/opsbot';

export type Locale = 'en' | 'ru';

export type LocaleOption = {
    code: Locale;
    label: string;
};

export type ProductInfo = {
    slug: string;
    tagline: string;
    telegramBotUsername: string | null;
    supportEmail?: string | null;
};

export type AuthUser = {
    id: number;
    name: string;
    email: string;
    locale: Locale;
    avatar: string | null;
    is_platform_admin: boolean;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
};

export type CurrentWorkspace = {
    id: number;
    name: string;
    slug: string;
    role: WorkspaceRole;
    plan: string;
    timezone: string;
    currency: string;
    country?: string | null;
    subscription_state: SubscriptionState;
    bot: SharedBot | null;
    onboarding_pending: boolean;
};

export type Badges = {
    unanswered_open: number;
    incidents_open: number;
    tasks_overdue: number;
};

export type WorkspaceSummary = {
    id: number;
    name: string;
    slug: string;
    role: string;
};

export type Flash = {
    success: string | null;
    error: string | null;
    limit?: LimitKey | null;
};

export type NotificationKind = 'info' | 'success' | 'warning' | 'danger' | 'ai';

export type AppNotification = {
    id: string;
    title: string;
    body?: string | null;
    kind?: NotificationKind;
    href?: string | null;
    read: boolean;
    created_at: string;
};

export type NotificationsProp = {
    unread: number;
    items: AppNotification[];
};

export type Ability =
    | 'workspace.manage'
    | 'workspace.delete'
    | 'members.manage'
    | 'billing.view'
    | 'billing.manage'
    | 'audit.view'
    | 'knowledge.view'
    | 'knowledge.manage'
    | 'assistant.use'
    | 'assistant.history'
    | 'integrations.manage'
    | 'admin.ai'
    | (string & {});

export type SharedProps = {
    name: string;
    product: ProductInfo;
    locale: Locale;
    availableLocales: LocaleOption[];
    auth: { user: AuthUser | null };
    workspace: CurrentWorkspace | null;
    badges?: Badges;
    workspaces: WorkspaceSummary[];
    can: Partial<Record<Ability, boolean>>;
    flash: Flash;
    billing: BillingShared;
    sidebarOpen: boolean;
    notifications?: NotificationsProp | null;
};

export type BillingDriver = 'fake' | 'stripe' | 'yookassa';

export type BillingAlert = {
    status: 'past_due' | 'trial_ending';
    at: string | null;
};

export type BillingShared = {
    driver: BillingDriver;
    alert: BillingAlert | null;
};

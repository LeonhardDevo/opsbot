import type { Paginated } from '@/types/models';

export type WorkspaceRole = 'owner' | 'admin' | 'manager' | 'employee';
export type MemberKind = 'web' | 'telegram_only';
export type MemberStatus = 'invited' | 'active' | 'offboarded';

export type BotStatus =
    | 'connecting'
    | 'active'
    | 'invalid_token'
    | 'revoked'
    | 'disconnecting'
    | 'disconnected';
export type BotErrorCode =
    | 'invalid_token'
    | 'token_mismatch'
    | 'already_connected'
    | 'network'
    | 'webhook_failed'
    | 'revoked';
export type ApprovalStatus = 'draft' | 'approved' | 'archived';
export type ProcessingStatus = 'queued' | 'processing' | 'indexed' | 'failed';
export type IncidentCategory =
    | 'damage'
    | 'complaint'
    | 'no_access'
    | 'injury'
    | 'lost_item'
    | 'other';
export type Severity = 'low' | 'medium' | 'high';
export type IncidentStatus = 'open' | 'in_progress' | 'resolved' | 'closed';
export type TaskStatus = 'todo' | 'in_progress' | 'done' | 'cancelled';
export type QuestionStatus =
    | 'open'
    | 'answered'
    | 'converted_to_doc'
    | 'dismissed';
export type RoutingCategory = IncidentCategory | 'question' | 'task';
export type SubscriptionState = 'ok' | 'grace' | 'paused';
export type RefusalReason =
    | 'low_confidence'
    | 'not_found'
    | 'model_refusal'
    | 'unavailable'
    | 'limit_reached'
    | 'paused';
export type LimitKey =
    | 'seats'
    | 'documents'
    | 'storage_mb'
    | 'ai_queries_monthly'
    | 'max_pages_per_document'
    | 'max_file_mb'
    | 'bots';
export type QuestionReason =
    | 'low_confidence'
    | 'not_found'
    | 'conflict'
    | 'user_request'
    | 'limit_reached';
export type DismissReason = 'duplicate' | 'out_of_scope' | 'spam' | 'other';

export type UserRef = { id: number; name: string };
export type DepartmentRef = { id: number; name: string; code: string };
export type LimitEntry = { used: number; limit: number | null };
export type LimitUsage = Partial<Record<LimitKey, LimitEntry>>;

export type TelegramBot = {
    id: number;
    key: string;
    status: BotStatus;
    username: string | null;
    first_name: string | null;
    tg_bot_id: number | null;
    mode: 'webhook' | 'polling' | 'fake' | null;
    can_join_groups: boolean | null;
    last_error: BotErrorCode | null;
    last_seen_at: string | null;
    connected_at: string | null;
    connected_by: UserRef | null;
    token_hint: string | null;
    linked_count: number;
    pending_relink_count: number;
};

export type SharedBot = {
    key: string;
    username: string | null;
    status: BotStatus;
};

export type Department = DepartmentRef & {
    manager: UserRef | null;
    members_count: number;
    documents_count: number;
    archived_at: string | null;
};

export type RoutingRule = {
    id: number;
    department_id: number | null;
    category: RoutingCategory;
    manager: UserRef;
};

export type RoutingSource = 'rule' | 'department_manager' | 'workspace_owner';

export type RoutingPreview = {
    user: UserRef | null;
    source: RoutingSource;
};

export type TelegramLinkState = 'not_linked' | 'linked' | 'blocked';

export type Employee = {
    id: number;
    name: string;
    email: string | null;
    phone_masked: string | null;
    position: string | null;
    role: WorkspaceRole;
    kind: MemberKind;
    status: MemberStatus;
    departments: DepartmentRef[];
    primary_department_id: number | null;
    telegram: {
        state: TelegramLinkState;
        username: string | null;
        linked_at: string | null;
        bot_key: string | null;
    };
    link: { active: boolean; expires_at: string | null };
    needs_relink: boolean;
    last_question_at: string | null;
    open_tasks: number;
    created_at: string;
    is_you: boolean;
};

export type EmployeeLinkOut = {
    user_id: number;
    url: string;
    expires_at: string;
    bot_username: string;
};

export type DocumentRef = {
    id: number;
    title: string;
    version: string | null;
};

export type KbDocument = {
    id: number;
    title: string;
    original_name: string;
    mime: string;
    size_bytes: number;
    department_ids: number[];
    departments: DepartmentRef[];
    roles: WorkspaceRole[];
    tags: string[];
    version: string | null;
    language: 'ru' | 'en' | null;
    status: ProcessingStatus;
    pages: number | null;
    pages_done: number | null;
    pages_total: number | null;
    chunks: number | null;
    ocr_used: boolean;
    error: string | null;
    error_code: string | null;
    approval_status: ApprovalStatus;
    approved_by: UserRef | null;
    approved_at: string | null;
    effective_date: string | null;
    review_due_at: string | null;
    review_overdue: boolean;
    supersedes: DocumentRef | null;
    superseded_by: (DocumentRef & { effective_date: string | null }) | null;
    archived_at: string | null;
    archive_reason: 'manual' | 'superseded' | null;
    source: 'upload' | 'starter_pack' | 'faq';
    cited_30d: number;
    uploaded_by: UserRef | null;
    created_at: string;
    updated_at: string;
};

export type StarterPack = {
    key: string;
    title: string;
    description: string;
    documents_count: number;
    languages: Array<'ru' | 'en'>;
    departments: Array<{ code: string; name: string }>;
    imported: boolean;
};

export type IncidentSource = 'bot' | 'web' | 'ai_proposal';

export type Incident = {
    id: number;
    number: number;
    display_number: string;
    category: IncidentCategory;
    severity: Severity;
    status: IncidentStatus;
    title: string;
    description: string;
    client_ref: string | null;
    department: DepartmentRef | null;
    reporter: UserRef | null;
    assignee: UserRef | null;
    source: IncidentSource;
    ai_query_id: number | null;
    tasks_count: number;
    age_hours: number;
    created_at: string;
    updated_at: string;
    resolved_at: string | null;
    closed_at: string | null;
};

export type IncidentEventType =
    | 'created'
    | 'status_changed'
    | 'assigned'
    | 'severity_changed'
    | 'category_changed'
    | 'comment'
    | 'task_linked';

export type IncidentEvent = {
    id: number;
    type: IncidentEventType;
    actor: UserRef | null;
    from: string | null;
    to: string | null;
    body: string | null;
    created_at: string;
};

export type ReminderStatus = 'planned' | 'sent' | 'skipped' | 'cancelled';

export type TaskReminder = {
    kind: string;
    planned_at: string;
    status: ReminderStatus;
};

export type TaskSource = 'web' | 'bot' | 'incident' | 'ai_proposal';

export type Task = {
    id: number;
    number: number;
    display_number: string;
    title: string;
    description: string | null;
    status: TaskStatus;
    due_at: string | null;
    overdue: boolean;
    assignee: UserRef | null;
    created_by: UserRef | null;
    department: DepartmentRef | null;
    incident: { id: number; display_number: string } | null;
    source: TaskSource;
    reminders: TaskReminder[];
    created_at: string;
    completed_at: string | null;
};

export type UnansweredQuestion = {
    id: number;
    number: number;
    display_number: string;
    question: string;
    reason: QuestionReason;
    status: QuestionStatus;
    employee: UserRef;
    department: DepartmentRef | null;
    assignee: UserRef | null;
    asked_at: string;
    age_hours: number;
    answer_text: string | null;
    answered_by: UserRef | null;
    answered_at: string | null;
    document: { id: number; title: string } | null;
    similar_count: number;
    ai_query_id: number | null;
};

export type Citation = {
    index: number;
    document_id: number;
    title: string;
    page: number | null;
    chunk_id: number;
    snippet: string;
    version: string | null;
    url: string | null;
};

export type ProposedActionType =
    | 'create_incident'
    | 'create_task'
    | 'escalate'
    | 'open_source'
    | 'ask';

export type ProposedActionStatus =
    | 'proposed'
    | 'executed'
    | 'cancelled'
    | 'expired'
    | 'failed';

export type ProposedAction = {
    id: string;
    type: ProposedActionType;
    label: string;
    url: string | null;
    status: ProposedActionStatus;
};

export type ConfidenceBand = 'high' | 'medium' | 'low';

export type ChatAnswer = {
    query_id: number;
    answer_html: string;
    answer_markdown: string;
    citations: Citation[];
    confidence: number;
    confidence_band: ConfidenceBand;
    answer_kind: 'document' | 'tool_data';
    refused: boolean;
    refusal_reason: RefusalReason | null;
    disclaimers: string[];
    actions: ProposedAction[];
    latency_ms: number;
    provider: string;
    model: string;
};

export type ActionStep = 'preview' | 'confirm' | 'cancel';

export type ActionResult = {
    ok: boolean;
    message: string;
    card?: { title: string; fields: Array<{ label: string; value: string }> };
    result?: {
        type: 'incident' | 'task' | 'question';
        id: number;
        display_number: string;
        url: string;
    };
};

export type AiChannel = 'telegram' | 'web';

export type AiQuery = {
    id: number;
    channel: AiChannel;
    user: UserRef | null;
    question: string;
    answer: string | null;
    refused: boolean;
    confidence: number | null;
    citations: Array<{
        document_id: number;
        title: string;
        page: number | null;
    }>;
    tools_used: string[];
    latency_ms: number | null;
    provider: string | null;
    model: string | null;
    created_at: string;
    bot_key: string | null;
    role_at_time: WorkspaceRole | null;
    departments_at_time: DepartmentRef[];
    cited_document_versions: Array<{
        document_id: number;
        title: string;
        version: string | null;
        effective_date: string | null;
        archived_now: boolean;
    }>;
    refusal_reason: RefusalReason | null;
    escalated_question_id: number | null;
    disclaimers: string[];
    actions: Array<{ type: string; status: string }>;
    answer_kind?: 'document' | 'tool_data';
    excerpt?: string;
};

export type DeskItemKind = 'incident' | 'question' | 'task_overdue' | 'bot';
export type DeskAction = 'take' | 'answer' | 'done' | 'open';

export type DeskItem = {
    id: string;
    kind: DeskItemKind;
    title: string;
    excerpt: string | null;
    actor: UserRef | null;
    at: string;
    url: string;
    severity?: Severity;
    actions: DeskAction[];
};

export type DeskFeedResponse = {
    items: DeskItem[];
    cursor: string;
};

export type OpsSettings = {
    min_confidence: number;
    quiet_hours_start: string;
    quiet_hours_end: string;
    reminders_enabled: boolean;
};

export type OnboardingStepKey = 'workspace' | 'bot' | 'knowledge' | 'team';
export type OnboardingStepState = 'done' | 'current' | 'todo' | 'skipped';

export type OnboardingStep = {
    key: OnboardingStepKey;
    state: OnboardingStepState;
};

export type Currency = 'USD' | 'EUR' | 'RUB';
export type PlanKey = 'free' | 'starter' | 'pro' | 'business';
export type BillingInterval = 'month' | 'year';
export type Gateway = 'stripe' | 'yookassa' | 'fake';
export type SubscriptionStatus =
    | 'trialing'
    | 'active'
    | 'past_due'
    | 'canceled'
    | 'incomplete';

export type Plan = {
    key: PlanKey;
    name: string;
    description: string;
    prices: Record<Currency, { monthly_minor: number; yearly_minor: number }>;
    currency: Currency;
    price_monthly_minor: number;
    price_yearly_minor: number;
    features: string[];
    limits: Record<LimitKey, number | null>;
    highlighted?: boolean;
};

export type Invoice = {
    id: string;
    number: string | null;
    date: string;
    amount_minor: number;
    currency: Currency;
    status: 'paid' | 'open' | 'void' | 'uncollectible';
    url: string | null;
};

export type BillingProps = {
    plan: Plan;
    plans: Plan[];
    interval: BillingInterval;
    gateway: Gateway;
    status: SubscriptionStatus | null;
    subscriptionState: SubscriptionState;
    trial_ends_at: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    past_due_since: string | null;
    paused_at: string | null;
    usage: Partial<Record<LimitKey, LimitEntry>>;
    seats_breakdown: {
        web_members: number;
        employees_active: number;
        employees_invited: number;
    };
    ai_month: { starts_on: string; resets_on: string; timezone: string };
    invoices: Invoice[];
    can: { manage: boolean; portal: boolean };
    checkout?: 'success' | 'cancelled' | 'updated' | null;
    subscription?: BillingSubscription | null;
    market?: BillingMarketInfo | null;
    capabilities?: { portal: boolean; manage: boolean };
};

export type BillingSubscription = {
    plan: PlanKey;
    status: SubscriptionStatus;
    interval: BillingInterval;
    currency: Currency | null;
    gateway: Gateway | (string & {});
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    trial_ends_at: string | null;
    payment_failed_at: string | null;
    scheduled_plan: PlanKey | null;
    scheduled_interval: BillingInterval | null;
};

export type BillingMarketInfo = {
    country: string;
    currency: Currency;
    gateway: Gateway;
    country_name: string;
    gateway_name: string;
    available: boolean;
};

export type DashboardProps = {
    today: DeskItem[];
    kpis: {
        questions_7d: { value: number; delta: number | null };
        answered_rate_7d: number | null;
        open_incidents: number;
        overdue_tasks: number;
        unanswered_open: number;
    };
    incidentsBySeverity: Record<Severity, { open: number; unassigned: number }>;
    questionsSeries: Array<{
        date: string;
        answered: number;
        refused: number;
        escalated: number;
    }>;
    recentIncidents: Incident[];
    myTasks: Task[];
    unanswered: UnansweredQuestion[];
    bot: TelegramBot | null;
    reviewDue: {
        count: number;
        top: Array<{ id: number; title: string; review_due_at: string }>;
    };
    onboarding: { steps: OnboardingStep[] } | null;
    limitsWarning: Array<{ key: LimitKey; used: number; limit: number }>;
};

export type AnalyticsProps = {
    locked: boolean;
    period: 7 | 30 | 90;
    departmentId: number | null;
    timezone: string;
    departments: DepartmentRef[];
    kpis: {
        questions: number;
        answered_pct: number | null;
        refused_pct: number | null;
        escalated_pct: number | null;
        median_latency_ms: number | null;
        p90_latency_ms: number | null;
        active_askers: number;
    };
    series: Array<{
        date: string;
        total: number;
        answered: number;
        refused: number;
        escalated: number;
    }>;
    topDocuments: Array<{
        document_id: number;
        title: string;
        citations: number;
    }>;
    topUnanswered: Array<{
        representative: string;
        count: number;
        last_asked_at: string;
    }>;
    neverCited: Array<{
        document_id: number;
        title: string;
        approved_at: string;
    }>;
    incidents: {
        by_category: Record<IncidentCategory, number>;
        by_week: Array<{
            week_start: string;
            opened: number;
            resolved: number;
        }>;
        median_resolution_hours: number | null;
    };
    tasks: {
        created: number;
        completed: number;
        overdue_now: number;
        on_time_pct: number | null;
    };
};

export type IncidentFilters = {
    q?: string;
    status?: IncidentStatus;
    category?: IncidentCategory;
    severity?: Severity;
    department_id?: number | string;
    assignee?: string;
    from?: string;
    to?: string;
};

export type TaskFilters = {
    q?: string;
    status?: TaskStatus;
    assignee?: string;
    department_id?: number | string;
    due?: 'overdue' | 'today' | 'week';
};

export type EmployeeFilters = {
    q?: string;
    department_id?: number | string;
    status?: MemberStatus;
    telegram?: TelegramLinkState;
    needs_relink?: boolean | string;
};

export type QuestionFilters = {
    status?: QuestionStatus | 'all';
    department_id?: number | string;
    assignee?: string;
    focus?: number | string;
};

export type KnowledgeFilters = {
    q?: string;
    status?: ProcessingStatus;
    approval?: ApprovalStatus;
    department_id?: number | string;
    review?: 'overdue';
};

export type HistoryFilters = {
    status?: 'answered' | 'refused' | 'escalated';
    channel?: AiChannel;
    q?: string;
    department_id?: number | string;
    refusal_reason?: RefusalReason;
};

export type PaginatedIncidents = Paginated<Incident>;

import {
    AlarmClock,
    Archive,
    Armchair,
    Ban,
    Bandage,
    Bot,
    Circle,
    CircleCheck,
    CircleCheckBig,
    CircleDashed,
    CircleDot,
    CircleEllipsis,
    CircleSlash,
    DoorClosed,
    FileArchive,
    FileCheck,
    FileClock,
    FilePen,
    FileX,
    KeyRound,
    Link,
    Link2Off,
    ListChecks,
    LoaderCircle,
    MessageCircleQuestionMark,
    MessageSquareWarning,
    PackageSearch,
    SignalHigh,
    SignalLow,
    SignalMedium,
    Timer,
    Unplug,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type {
    ApprovalStatus,
    BotStatus,
    DeskItemKind,
    IncidentStatus,
    ProcessingStatus,
    RoutingCategory,
    Severity,
    TaskStatus,
    TelegramLinkState,
} from '@/types/opsbot';

export const categoryIcon: Record<RoutingCategory, LucideIcon> = {
    damage: Armchair,
    complaint: MessageSquareWarning,
    no_access: DoorClosed,
    injury: Bandage,
    lost_item: PackageSearch,
    other: CircleEllipsis,
    question: MessageCircleQuestionMark,
    task: ListChecks,
};

export const severityIcon: Record<Severity, LucideIcon> = {
    low: SignalLow,
    medium: SignalMedium,
    high: SignalHigh,
};

export const incidentStatusIcon: Record<IncidentStatus, LucideIcon> = {
    open: CircleDot,
    in_progress: CircleDashed,
    resolved: CircleCheck,
    closed: Archive,
};

export const taskStatusIcon: Record<TaskStatus, LucideIcon> = {
    todo: Circle,
    in_progress: Timer,
    done: CircleCheckBig,
    cancelled: CircleSlash,
};

export const approvalIcon: Record<ApprovalStatus, LucideIcon> = {
    draft: FilePen,
    approved: FileCheck,
    archived: FileArchive,
};

export const processingIcon: Record<ProcessingStatus, LucideIcon> = {
    queued: FileClock,
    processing: FileClock,
    indexed: FileCheck,
    failed: FileX,
};

export const botStatusIcon: Record<BotStatus, LucideIcon> = {
    active: Bot,
    connecting: LoaderCircle,
    disconnecting: LoaderCircle,
    invalid_token: KeyRound,
    revoked: KeyRound,
    disconnected: Unplug,
};

export const telegramStateIcon: Record<TelegramLinkState, LucideIcon> = {
    linked: Link,
    not_linked: Link2Off,
    blocked: Ban,
};

export const deskKindIcon: Record<DeskItemKind, LucideIcon> = {
    incident: Armchair,
    question: MessageCircleQuestionMark,
    task_overdue: AlarmClock,
    bot: Bot,
};

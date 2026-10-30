<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Bot,
    Building2,
    CalendarClock,
    CircleCheck,
    Cpu,
    Download,
    FileArchive,
    FileCheck,
    FileClock,
    FileX,
    HardDriveDownload,
    Hourglass,
    ShieldCheck,
    ShieldUser,
    TriangleAlert,
    Undo2,
    UserX,
} from '@lucide/vue';
import type { Component } from 'vue';
import { useNow } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import InputError from '@/components/InputError.vue';
import Callout from '@/components/opsbot/Callout.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import type { OpsTone } from '@/components/opsbot/OpsBadge.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import type { Endpoint } from '@/lib/endpoints';
import { humanize, t, tc, tOr } from '@/lib/i18n';
import {
    legalDocIcon,
    legalDocLabelKey,
    retentionChoices,
    timeLeft,
} from '@/lib/legal';
import type {
    DataExport,
    PrivacyPageProps,
    PrivacyPermissions,
    PrivacyWorkspace,
} from '@/types/legal';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.settings.title', href: endpoints.privacy.show() },
            {
                title: 'ui.settings.nav.privacy',
                href: endpoints.privacy.show(),
            },
        ],
    },
});

const props = withDefaults(defineProps<Partial<PrivacyPageProps>>(), {
    exports: () => [],
    deletion: () => ({ scheduled_for: null }),
    acceptances: () => [],
    privacy_workspace: null,
    privacy_can: () => ({ manage_workspace_privacy: false }),
});

const page = usePage();
const { can } = useCan();

const ws = computed<PrivacyWorkspace | null>(() => props.privacy_workspace);
const perms = computed<PrivacyPermissions>(() => props.privacy_can);
const fmt = useFormat();
const now = useNow({ interval: 60_000 });

const workspaceName = computed(
    () => ws.value?.name ?? page.props.workspace?.name ?? '',
);
const accountGrace = computed(() => props.deletion.grace_days ?? 14);
const workspaceGrace = computed(() => ws.value?.deletion?.grace_days ?? 30);
const linkHours = computed(() => props.export_link_hours ?? 24);
const blockedBy = computed(() => props.deletion.blocked_by_workspace ?? null);
const canExportWorkspace = computed(
    () => perms.value.export_workspace ?? perms.value.manage_workspace_privacy,
);
const canDeleteWorkspace = computed(
    () => perms.value.delete_workspace ?? perms.value.manage_workspace_privacy,
);

const busy = ref<string | null>(null);

function send(
    action: string,
    endpoint: Endpoint,
    data: Record<string, string | number | boolean | null> = {},
    callbacks: {
        onSuccess?: () => void;
        onError?: (errors: Record<string, string>) => void;
    } = {},
): void {
    router.visit(endpoint.url, {
        method: endpoint.method,
        data,
        preserveScroll: true,
        preserveState: true,
        onStart: () => (busy.value = action),
        onFinish: () => (busy.value = null),
        onSuccess: () => callbacks.onSuccess?.(),
        onError: (errors) => callbacks.onError?.(errors),
    });
}

function firstError(errors: Record<string, string>): string {
    return (
        errors.password ?? Object.values(errors)[0] ?? t('ui.errors.generic')
    );
}

const userExports = computed(() =>
    props.exports.filter((item) => (item.scope ?? 'user') === 'user'),
);
const workspaceExports = computed(() =>
    props.exports.filter((item) => item.scope === 'workspace'),
);
const exportInFlight = (items: DataExport[]): boolean =>
    items.some(
        (item) => item.status === 'pending' || item.status === 'processing',
    );

const STATUS_TONE: Record<string, { tone: OpsTone; icon: Component }> = {
    pending: { tone: 'neutral', icon: FileClock },
    processing: { tone: 'primary', icon: FileClock },
    ready: { tone: 'success', icon: CircleCheck },
    failed: { tone: 'coral', icon: FileX },
    expired: { tone: 'neutral', icon: FileArchive },
};

function effectiveStatus(item: DataExport): string {
    const lapsed =
        item.status === 'ready' &&
        item.expires_at !== null &&
        new Date(item.expires_at).getTime() <= now.value.getTime();

    return lapsed ? 'expired' : item.status;
}

function downloadable(item: DataExport): boolean {
    return Boolean(item.download_url) && effectiveStatus(item) === 'ready';
}

function exportStatus(item: DataExport): {
    tone: OpsTone;
    icon: Component;
    label: string;
} {
    const status = effectiveStatus(item);
    const look = STATUS_TONE[status] ?? { tone: 'neutral', icon: Archive };

    return {
        ...look,
        label: tOr(
            `ui.settings.privacy.export_status.${status}`,
            humanize(status),
        ),
    };
}

function requestExport(): void {
    send('export', endpoints.privacy.export());
}

const accountLeft = computed(() =>
    timeLeft(props.deletion.scheduled_for, now.value),
);
const deleteOpen = ref(false);
const deletePassword = ref('');
const deleteError = ref<string | null>(null);

watch(deleteOpen, (open) => {
    if (!open) {
        deletePassword.value = '';
        deleteError.value = null;
    }
});

function scheduleAccountDeletion(): void {
    deleteError.value = null;
    send(
        'delete-account',
        endpoints.privacy.scheduleDeletion(),
        { password: deletePassword.value },
        {
            onSuccess: () => (deleteOpen.value = false),
            onError: (errors) => (deleteError.value = firstError(errors)),
        },
    );
}

function cancelAccountDeletion(): void {
    send('cancel-account', endpoints.privacy.cancelDeletion());
}

const acceptances = computed(() =>
    [...props.acceptances].sort((a, b) =>
        b.accepted_at.localeCompare(a.accepted_at),
    ),
);

const canManage = computed(
    () => perms.value.manage_workspace_privacy && ws.value !== null,
);

const settingsForm = useForm({
    ai_external_allowed: ws.value?.ai_external_allowed ?? false,
    retention: { ...ws.value?.retention } as Record<string, number>,
});

watch(
    () => ws.value,
    (workspace) => {
        if (workspace && !settingsForm.isDirty) {
            settingsForm.defaults({
                ai_external_allowed: workspace.ai_external_allowed,
                retention: { ...workspace.retention },
            });
            settingsForm.reset();
        }
    },
);

const retentionRows = computed(() =>
    Object.entries(ws.value?.retention_bounds ?? {}).flatMap(([key, range]) =>
        range
            ? [
                  {
                      key,
                      range,
                      label: tOr(
                          `ui.settings.privacy.retention_keys.${key}`,
                          humanize(key),
                      ),
                      hint: tOr(
                          `ui.settings.privacy.retention_hints.${key}`,
                          '',
                      ),
                      choices: retentionChoices(
                          settingsForm.retention[key] ??
                              ws.value?.retention[key] ??
                              range.min,
                          range,
                      ),
                  },
              ]
            : [],
    ),
);

function setRetention(key: string, value: unknown): void {
    const days = Number(value);

    if (Number.isFinite(days) && days > 0) {
        settingsForm.retention = { ...settingsForm.retention, [key]: days };
    }
}

function saveWorkspaceSettings(): void {
    const endpoint = endpoints.privacy.updateWorkspace();

    settingsForm.submit(endpoint.method, endpoint.url, {
        preserveScroll: true,
        onSuccess: () => settingsForm.defaults(),
    });
}

function requestWorkspaceExport(): void {
    send('workspace-export', endpoints.privacy.exportWorkspace());
}

const workspaceLeft = computed(() =>
    timeLeft(ws.value?.deletion?.scheduled_for ?? null, now.value),
);
const wsDeleteOpen = ref(false);
const wsDeletePassword = ref('');
const wsDeleteError = ref<string | null>(null);

watch(wsDeleteOpen, (open) => {
    if (!open) {
        wsDeletePassword.value = '';
        wsDeleteError.value = null;
    }
});

function scheduleWorkspaceDeletion(): void {
    wsDeleteError.value = null;
    send(
        'delete-workspace',
        endpoints.privacy.scheduleWorkspaceDeletion(),
        { password: wsDeletePassword.value },
        {
            onSuccess: () => (wsDeleteOpen.value = false),
            onError: (errors) => (wsDeleteError.value = firstError(errors)),
        },
    );
}

function cancelWorkspaceDeletion(): void {
    send('cancel-workspace', endpoints.privacy.cancelWorkspaceDeletion());
}

const externalAllowed = computed(() =>
    canManage.value
        ? settingsForm.ai_external_allowed
        : (ws.value?.ai_external_allowed ?? false),
);

function docTitle(document: string, title?: string | null): string {
    return title || tOr(legalDocLabelKey(document), humanize(document));
}

function countdown(left: { days: number; hours: number } | null): string {
    if (!left) {
        return '';
    }

    return left.days > 0
        ? tc('ui.settings.privacy.days_left', left.days)
        : tc('ui.settings.privacy.hours_left', Math.max(1, left.hours));
}
</script>

<template>
    <Head :title="t('ui.settings.privacy.title')" />

    <header class="space-y-1">
        <h1 class="text-h1">{{ t('ui.settings.privacy.title') }}</h1>
        <p class="text-sm text-pretty text-muted-foreground">
            {{ t('ui.settings.privacy.subtitle') }}
        </p>
    </header>

    <ul v-if="ws" class="grid gap-3 sm:grid-cols-2" data-test="privacy-overview">
        <li
            class="flex items-start gap-3 rounded-card border bg-surface p-4 shadow-clay-sm"
        >
            <span
                :class="[
                    'inline-flex size-9 shrink-0 items-center justify-center rounded-xl',
                    externalAllowed
                        ? 'bg-sun-soft text-sun-strong'
                        : 'bg-mint-soft text-mint-strong',
                ]"
                aria-hidden="true"
            >
                <Bot class="size-[1.125rem]" />
            </span>
            <div class="min-w-0">
                <p class="text-caption">
                    {{ t('ui.settings.privacy.overview_ai') }}
                </p>
                <p class="font-semibold" data-test="privacy-overview-ai">
                    {{
                        externalAllowed
                            ? t('ui.settings.privacy.overview_ai_external')
                            : t('ui.settings.privacy.overview_ai_local')
                    }}
                </p>
            </div>
        </li>
        <li
            class="flex items-start gap-3 rounded-card border bg-surface p-4 shadow-clay-sm"
        >
            <span
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-strong"
                aria-hidden="true"
            >
                <Hourglass class="size-[1.125rem]" />
            </span>
            <div class="min-w-0">
                <p class="text-caption">
                    {{ t('ui.settings.privacy.retention_keys.ai_queries') }}
                </p>
                <p class="font-semibold">
                    {{
                        tc(
                            'ui.settings.privacy.days',
                            settingsForm.retention.ai_queries ??
                                ws.retention.ai_queries ??
                                0,
                        )
                    }}
                </p>
            </div>
        </li>
    </ul>

    <SectionCard
        v-if="ws && canManage"
        :icon="Bot"
        :title="t('ui.settings.privacy.ai_title')"
        :description="t('ui.settings.privacy.ai_description')"
        data-test="privacy-workspace-settings"
    >
        <form class="grid gap-5" @submit.prevent="saveWorkspaceSettings">
            <div
                class="flex items-start justify-between gap-4 rounded-2xl border px-4 py-3.5"
            >
                <div class="min-w-0">
                    <Label for="ai-external" class="font-semibold">{{
                        t('ui.settings.privacy.ai_external')
                    }}</Label>
                    <p
                        id="ai-external-hint"
                        class="mt-1 text-sm text-pretty text-muted-foreground"
                    >
                        {{
                            settingsForm.ai_external_allowed
                                ? t('ui.settings.privacy.ai_external_on')
                                : t('ui.settings.privacy.ai_external_off')
                        }}
                    </p>
                </div>
                <Switch
                    id="ai-external"
                    v-model="settingsForm.ai_external_allowed"
                    aria-describedby="ai-external-hint"
                    data-test="ai-external-switch"
                />
            </div>
            <InputError :message="settingsForm.errors.ai_external_allowed" />
            <Callout
                :tone="settingsForm.ai_external_allowed ? 'sun' : 'mint'"
                :icon="settingsForm.ai_external_allowed ? Cpu : ShieldCheck"
                data-test="privacy-ai-scope"
            >
                {{
                    settingsForm.ai_external_allowed
                        ? t('ui.settings.privacy.ai_scope_external')
                        : t('ui.settings.privacy.ai_scope_local')
                }}
                <Link
                    :href="endpoints.legal('ai')"
                    class="font-semibold underline underline-offset-2"
                    >{{ t('ui.settings.privacy.ai_notice_link') }}</Link
                >
            </Callout>

            <fieldset v-if="retentionRows.length" class="grid gap-3">
                <legend class="mb-1 flex items-center gap-2 font-semibold">
                    <Hourglass
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ t('ui.settings.privacy.retention_title') }}
                </legend>
                <p class="text-sm text-pretty text-muted-foreground">
                    {{ t('ui.settings.privacy.retention_description') }}
                </p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div
                        v-for="row in retentionRows"
                        :key="row.key"
                        class="grid content-start gap-2"
                    >
                        <Label :for="`retention-${row.key}`">{{
                            row.label
                        }}</Label>
                        <Select
                            :model-value="
                                String(settingsForm.retention[row.key])
                            "
                            @update:model-value="
                                (value) => setRetention(row.key, value)
                            "
                        >
                            <SelectTrigger
                                :id="`retention-${row.key}`"
                                class="w-full"
                                :aria-describedby="
                                    row.hint
                                        ? `retention-${row.key}-hint`
                                        : undefined
                                "
                                :data-test="`retention-${row.key}`"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="days in row.choices"
                                    :key="days"
                                    :value="String(days)"
                                >
                                    {{ tc('ui.settings.privacy.days', days) }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p
                            v-if="row.hint"
                            :id="`retention-${row.key}-hint`"
                            class="text-caption"
                        >
                            {{ row.hint }}
                        </p>
                        <InputError
                            :message="
                                settingsForm.errors[
                                    `retention.${row.key}` as 'retention'
                                ]
                            "
                        />
                    </div>
                </div>
            </fieldset>

            <div
                class="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <Link
                    v-if="can('admin.ai')"
                    :href="endpoints.admin.ai.index()"
                    class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-primary hover:text-primary-strong"
                    data-test="privacy-admin-ai"
                >
                    <Cpu class="size-4" aria-hidden="true" />
                    {{ t('ui.settings.privacy.admin_ai_link') }}
                </Link>
                <span v-else />
                <Button
                    type="submit"
                    :disabled="settingsForm.processing || !settingsForm.isDirty"
                    data-test="privacy-workspace-save"
                >
                    <Spinner v-if="settingsForm.processing" />
                    {{ t('ui.common.save') }}
                </Button>
            </div>
        </form>
    </SectionCard>

    <SectionCard
        :icon="HardDriveDownload"
        :title="t('ui.settings.privacy.export_title')"
        :description="t('ui.settings.privacy.export_description')"
        data-test="privacy-export"
    >
        <template #actions>
            <Button
                variant="secondary"
                :disabled="busy !== null || exportInFlight(userExports)"
                data-test="privacy-export-request"
                @click="requestExport"
            >
                <Spinner v-if="busy === 'export'" />
                <Download v-else class="size-4" aria-hidden="true" />
                {{ t('ui.settings.privacy.export_request') }}
            </Button>
        </template>

        <p
            v-if="userExports.length === 0"
            class="rounded-2xl bg-surface-2 px-4 py-3 text-sm text-muted-foreground"
        >
            {{ t('ui.settings.privacy.export_empty') }}
        </p>
        <ul
            v-else
            class="divide-y overflow-hidden rounded-2xl border"
            data-test="export-list"
        >
            <li
                v-for="item in userExports"
                :key="item.id"
                class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                :data-test="`export-row-${item.id}`"
                :data-status="item.status"
            >
                <div class="min-w-0 space-y-1">
                    <OpsBadge v-bind="exportStatus(item)" />
                    <p class="text-caption">
                        {{
                            t('ui.settings.privacy.export_requested_at', {
                                date: fmt.dateTime(item.created_at),
                            })
                        }}
                        <template
                            v-if="item.expires_at && item.status === 'ready'"
                        >
                            ·
                            {{
                                t('ui.settings.privacy.export_expires_at', {
                                    date: fmt.dateTime(item.expires_at),
                                })
                            }}
                        </template>
                    </p>
                </div>
                <Button
                    v-if="downloadable(item)"
                    as="a"
                    :href="item.download_url ?? undefined"
                    size="sm"
                    variant="secondary"
                    data-test="export-download"
                >
                    <Download class="size-4" aria-hidden="true" />
                    {{ t('ui.settings.privacy.export_download') }}
                </Button>
            </li>
        </ul>
        <p class="text-caption mt-3">
            {{ t('ui.settings.privacy.export_note', { hours: linkHours }) }}
        </p>
    </SectionCard>

    <SectionCard
        v-if="ws && canExportWorkspace"
        :icon="Building2"
        :title="t('ui.settings.privacy.workspace_data_title')"
        :description="
            t('ui.settings.privacy.workspace_data_description', {
                name: workspaceName,
            })
        "
        data-test="privacy-workspace-data"
    >
        <template #actions>
            <Button
                variant="secondary"
                :disabled="busy !== null || exportInFlight(workspaceExports)"
                data-test="workspace-export-request"
                @click="requestWorkspaceExport"
            >
                <Spinner v-if="busy === 'workspace-export'" />
                <Download v-else class="size-4" aria-hidden="true" />
                {{ t('ui.settings.privacy.workspace_export') }}
            </Button>
        </template>
        <p class="text-sm text-pretty text-muted-foreground">
            {{ t('ui.settings.privacy.workspace_export_hint') }}
        </p>
        <ul
            v-if="workspaceExports.length"
            class="mt-3 divide-y overflow-hidden rounded-2xl border"
            data-test="workspace-export-list"
        >
            <li
                v-for="item in workspaceExports"
                :key="item.id"
                class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                :data-status="item.status"
            >
                <div class="min-w-0 space-y-1">
                    <OpsBadge v-bind="exportStatus(item)" />
                    <p class="text-caption">
                        {{
                            t('ui.settings.privacy.export_requested_at', {
                                date: fmt.dateTime(item.created_at),
                            })
                        }}
                    </p>
                </div>
                <Button
                    v-if="downloadable(item)"
                    as="a"
                    :href="item.download_url ?? undefined"
                    size="sm"
                    variant="secondary"
                >
                    <Download class="size-4" aria-hidden="true" />
                    {{ t('ui.settings.privacy.export_download') }}
                </Button>
            </li>
        </ul>
    </SectionCard>

    <SectionCard
        :icon="FileCheck"
        :title="t('ui.settings.privacy.acceptances_title')"
        :description="t('ui.settings.privacy.acceptances_description')"
        data-test="privacy-acceptances"
    >
        <p
            v-if="acceptances.length === 0"
            class="rounded-2xl bg-surface-2 px-4 py-3 text-sm text-muted-foreground"
        >
            {{ t('ui.settings.privacy.acceptances_empty') }}
        </p>
        <ul v-else class="divide-y overflow-hidden rounded-2xl border">
            <li
                v-for="row in acceptances"
                :key="`${row.document}-${row.version}-${row.accepted_at}`"
                class="flex items-center gap-3 px-4 py-3"
            >
                <span
                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-surface-2 text-primary-strong"
                    aria-hidden="true"
                >
                    <component :is="legalDocIcon(row.document)" class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <Link
                        :href="endpoints.legalVersion(row.document, row.version)"
                        class="font-medium text-foreground underline-offset-2 hover:text-primary-strong hover:underline"
                    >
                        {{ docTitle(row.document, row.title) }}
                    </Link>
                    <p class="text-caption">
                        <time :datetime="row.accepted_at">{{
                            t('ui.settings.privacy.accepted_on', {
                                date: fmt.dateTime(row.accepted_at),
                            })
                        }}</time>
                    </p>
                </div>
                <OpsBadge
                    :label="t('ui.legal.accept.version', { version: row.version })"
                    mono
                />
            </li>
        </ul>
    </SectionCard>

    <template v-if="ws && canDeleteWorkspace">
        <SectionCard
            tone="danger"
            :icon="TriangleAlert"
            :title="t('ui.settings.privacy.workspace_delete_title')"
            :description="t('ui.settings.privacy.workspace_delete_description')"
            data-test="privacy-workspace-delete"
        >
            <Callout
                v-if="workspaceLeft"
                tone="coral"
                :icon="CalendarClock"
                :title="countdown(workspaceLeft)"
                role="status"
                data-test="workspace-deletion-scheduled"
            >
                {{
                    t('ui.settings.privacy.workspace_scheduled', {
                        date: fmt.dateTime(ws.deletion?.scheduled_for),
                    })
                }}
                <template #actions>
                    <Button
                        variant="secondary"
                        size="sm"
                        :disabled="busy !== null"
                        data-test="workspace-deletion-cancel"
                        @click="cancelWorkspaceDeletion"
                    >
                        <Spinner v-if="busy === 'cancel-workspace'" />
                        <Undo2 v-else class="size-4" aria-hidden="true" />
                        {{ t('ui.settings.privacy.cancel_deletion') }}
                    </Button>
                </template>
            </Callout>
            <div
                v-else
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="text-sm text-pretty text-muted-foreground">
                    {{
                        t('ui.settings.privacy.workspace_delete_warning', {
                            days: workspaceGrace,
                        })
                    }}
                </p>
                <Button
                    variant="destructive"
                    class="shrink-0"
                    data-test="workspace-delete-open"
                    @click="wsDeleteOpen = true"
                >
                    {{ t('ui.settings.privacy.workspace_delete') }}
                </Button>
            </div>
        </SectionCard>

        <ConfirmDialog
            v-model:open="wsDeleteOpen"
            destructive
            :processing="busy === 'delete-workspace'"
            :confirm-text="workspaceName"
            :title="
                t('ui.settings.privacy.workspace_delete_confirm', {
                    name: workspaceName,
                })
            "
            :description="
                t('ui.settings.privacy.workspace_delete_body', {
                    days: workspaceGrace,
                })
            "
            :confirm-label="t('ui.settings.privacy.workspace_delete')"
            @confirm="scheduleWorkspaceDeletion"
        >
            <div class="grid gap-2">
                <Label for="ws-privacy-delete-password">{{
                    t('ui.fields.password')
                }}</Label>
                <PasswordInput
                    id="ws-privacy-delete-password"
                    v-model="wsDeletePassword"
                    autocomplete="current-password"
                    :aria-invalid="Boolean(wsDeleteError)"
                    data-test="workspace-delete-password"
                />
                <InputError :message="wsDeleteError" />
            </div>
        </ConfirmDialog>
    </template>

    <SectionCard
        tone="danger"
        :icon="UserX"
        :title="t('ui.settings.privacy.account_delete_title')"
        :description="t('ui.settings.privacy.account_delete_description')"
        data-test="privacy-account-delete"
    >
        <Callout
            v-if="accountLeft"
            tone="coral"
            :icon="CalendarClock"
            role="status"
            data-test="account-deletion-scheduled"
        >
            <p class="font-semibold" data-test="account-deletion-countdown">
                {{ countdown(accountLeft) }}
            </p>
            <p>
                {{
                    t('ui.settings.privacy.account_scheduled', {
                        date: fmt.dateTime(deletion.scheduled_for),
                    })
                }}
            </p>
            <template #actions>
                <Button
                    variant="secondary"
                    size="sm"
                    :disabled="busy !== null"
                    data-test="account-deletion-cancel"
                    @click="cancelAccountDeletion"
                >
                    <Spinner v-if="busy === 'cancel-account'" />
                    <Undo2 v-else class="size-4" aria-hidden="true" />
                    {{ t('ui.settings.privacy.cancel_deletion') }}
                </Button>
            </template>
        </Callout>
        <div
            v-else
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <Callout
                v-if="blockedBy"
                tone="sun"
                :icon="TriangleAlert"
                class="flex-1"
                data-test="account-delete-blocked"
            >
                {{
                    t('ui.settings.privacy.account_delete_blocked', {
                        name: blockedBy,
                    })
                }}
            </Callout>
            <p v-else class="text-sm text-pretty text-muted-foreground">
                {{
                    t('ui.settings.privacy.account_delete_warning', {
                        days: accountGrace,
                    })
                }}
            </p>
            <Button
                variant="destructive"
                class="shrink-0"
                :disabled="Boolean(blockedBy)"
                data-test="account-delete-open"
                @click="deleteOpen = true"
            >
                {{ t('ui.settings.privacy.account_delete') }}
            </Button>
        </div>
    </SectionCard>

    <ConfirmDialog
        v-model:open="deleteOpen"
        destructive
        :processing="busy === 'delete-account'"
        :title="t('ui.settings.privacy.account_delete_confirm')"
        :description="
            t('ui.settings.privacy.account_delete_body', {
                days: accountGrace,
            })
        "
        :confirm-label="t('ui.settings.privacy.account_delete')"
        @confirm="scheduleAccountDeletion"
    >
        <div class="grid gap-2">
            <Label for="privacy-delete-password">{{
                t('ui.fields.password')
            }}</Label>
            <PasswordInput
                id="privacy-delete-password"
                v-model="deletePassword"
                autocomplete="current-password"
                :aria-invalid="Boolean(deleteError)"
                data-test="account-delete-password"
            />
            <InputError :message="deleteError" />
        </div>
    </ConfirmDialog>

    <p
        class="flex items-start gap-2.5 rounded-card bg-surface-2 px-4 py-3 text-sm text-pretty text-muted-foreground"
    >
        <ShieldUser
            class="mt-0.5 size-4 shrink-0 text-primary-strong"
            aria-hidden="true"
        />
        <span>
            {{ t('ui.settings.privacy.rights_note') }}
            <Link
                :href="endpoints.legal('privacy')"
                class="font-semibold text-primary-strong underline underline-offset-2 hover:text-primary"
                >{{ t('ui.legal.privacy') }}</Link
            >
            ·
            <Link
                :href="endpoints.legal('employee-notice')"
                class="font-semibold text-primary-strong underline underline-offset-2 hover:text-primary"
                >{{ t('ui.legal.docs.employee_notice') }}</Link
            >
        </span>
    </p>
</template>

<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CircleCheck,
    Eye,
    FileText,
    FilterX,
    Forward,
    Gauge,
    Globe,
    History,
    MessageSquareText,
    MessagesSquare,
    SearchX,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfidenceMeter from '@/components/assistant/ConfidenceMeter.vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import KpiCard from '@/components/common/KpiCard.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import StatusBadge from '@/components/common/StatusBadge.vue';
import TelegramIcon from '@/components/integrations/TelegramIcon.vue';
import DepartmentChips from '@/components/opsbot/DepartmentChips.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useCan } from '@/composables/useCan';
import { useFilters } from '@/composables/useFilters';
import { useFormat } from '@/composables/useFormat';
import { endpoints, withQuery } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';
import type { Paginated } from '@/types';
import type {
    AiChannel,
    AiQuery,
    DepartmentRef,
    RefusalReason,
} from '@/types/opsbot';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.nav.assistant', href: endpoints.assistant.index() },
            { title: 'ui.nav.history', href: endpoints.assistant.history() },
        ],
    },
});

type HistoryStatus = 'answered' | 'refused' | 'escalated';

const props = withDefaults(
    defineProps<{
        queries: Paginated<AiQuery>;
        filters: {
            status?: HistoryStatus;
            channel?: AiChannel;
            q?: string;
            department_id?: number | string;
            refusal_reason?: RefusalReason;
        };
        stats: {
            total: number;
            answered: number;
            refused: number;
            escalated: number;
            avg_confidence: number;
        };
        scope?: 'all' | 'department' | 'self';
    }>(),
    { scope: 'self' },
);

const fmt = useFormat();
const { can } = useCan();

const REASONS: RefusalReason[] = [
    'low_confidence',
    'not_found',
    'model_refusal',
    'unavailable',
    'limit_reached',
    'paused',
];

const { filters, reset, active } = useFilters(
    () => endpoints.assistant.history().url,
    {
        q: props.filters?.q ?? null,
        status: props.filters?.status ?? null,
        channel: props.filters?.channel ?? null,
        department_id: props.filters?.department_id ?? null,
        refusal_reason: props.filters?.refusal_reason ?? null,
    },
    ['queries', 'filters', 'stats'],
);

const statusModel = computed({
    get: () => (filters.status ?? 'all') as 'all' | HistoryStatus,
    set: (value: 'all' | HistoryStatus) => {
        filters.status = value === 'all' ? null : value;
    },
});

const statusOptions = computed(() => [
    { value: 'all' as const, label: t('ui.common.all') },
    { value: 'answered' as const, label: t('ui.history.answered') },
    { value: 'refused' as const, label: t('ui.history.refused') },
    { value: 'escalated' as const, label: t('ui.history.escalated') },
]);

const reasonOptions = computed(() =>
    REASONS.map((reason) => ({
        value: reason,
        label: tOr(`ui.history.reasons.${reason}`, reason),
    })),
);

const channelOptions = computed(() => [
    { value: 'telegram', label: t('ui.history.channels.telegram') },
    { value: 'web', label: t('ui.history.channels.web') },
]);

const departmentOptions = computed(() => {
    const map = new Map<number, DepartmentRef>();

    for (const row of props.queries.data) {
        for (const department of row.departments_at_time) {
            map.set(department.id, department);
        }
    }

    return [...map.values()]
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((department) => ({
            value: department.id,
            label: department.name,
        }));
});

const columns = computed<DataColumn<AiQuery>[]>(() => [
    {
        id: 'created_at',
        header: t('ui.history.when'),
        sortable: true,
        hideBelow: 'sm',
    },
    {
        id: 'user',
        header: t('ui.history.user'),
        accessor: (row) => row.user?.name ?? '',
        hideBelow: 'md',
    },
    {
        id: 'departments',
        header: t('ui.history.departments'),
        hideBelow: 'xl',
    },
    { id: 'question', header: t('ui.history.question') },
    { id: 'refused', header: t('ui.history.result') },
    {
        id: 'confidence',
        header: t('ui.assistant.confidence'),
        sortable: true,
        hideBelow: 'lg',
    },
    {
        id: 'cited',
        header: t('ui.history.cited'),
        accessor: (row) => row.cited_document_versions.length,
        align: 'right',
        hideBelow: 'xl',
    },
    { id: 'channel', header: t('ui.history.channel'), hideBelow: 'md' },
]);

const selected = ref<AiQuery | null>(null);
const detailOpen = computed({
    get: () => selected.value !== null,
    set: (value: boolean) => {
        if (!value) {
            selected.value = null;
        }
    },
});

const answeredRate = computed(() =>
    props.stats.total ? props.stats.answered / props.stats.total : 0,
);

function resultTone(row: AiQuery): 'success' | 'warning' | 'info' {
    if (row.escalated_question_id) {
        return 'info';
    }

    return row.refused ? 'warning' : 'success';
}

function resultLabel(row: AiQuery): string {
    if (row.escalated_question_id) {
        return t('ui.history.escalated');
    }

    if (row.refused) {
        return row.refusal_reason
            ? t('ui.history.refused_with', {
                  reason: tOr(
                      `ui.history.reasons.${row.refusal_reason}`,
                      row.refusal_reason,
                  ),
              })
            : t('ui.history.refused');
    }

    return t('ui.history.answered');
}

function questionUrl(id: number): string {
    return withQuery(endpoints.unanswered.index().url, {
        status: 'all',
        focus: id,
    });
}
</script>

<template>
    <Head :title="t('ui.history.title')" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6 lg:py-8"
    >
        <PageHeader
            :icon="History"
            :title="t('ui.history.title')"
            :description="t('ui.history.description')"
        >
            <template #actions>
                <OpsBadge
                    tone="neutral"
                    :label="t(`ui.history.scope.${scope}`)"
                    data-test="history-scope"
                    :data-scope="scope"
                />
                <Button as-child>
                    <Link :href="endpoints.assistant.index()">
                        <MessageSquareText class="size-4" aria-hidden="true" />
                        {{ t('ui.history.open_assistant') }}
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <p
            class="-mt-3 flex items-start gap-2 text-sm text-pretty text-muted-foreground"
        >
            <Eye class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ t('ui.history.privacy') }}
        </p>

        <section
            class="grid grid-cols-2 gap-4 xl:grid-cols-5"
            :aria-label="t('ui.history.stats')"
            data-test="history-stats"
        >
            <KpiCard
                :label="t('ui.history.total')"
                :value="stats.total"
                :icon="MessagesSquare"
                tone="info"
            />
            <KpiCard
                :label="t('ui.history.answered')"
                :value="stats.answered"
                :icon="CircleCheck"
                :hint="
                    t('ui.history.answered_rate', {
                        rate: fmt.percent(answeredRate),
                    })
                "
            />
            <KpiCard
                :label="t('ui.history.refused')"
                :value="stats.refused"
                :icon="SearchX"
                tone="warning"
                :hint="t('ui.history.refused_hint')"
            />
            <KpiCard
                :label="t('ui.history.escalated')"
                :value="stats.escalated"
                :icon="Forward"
                tone="info"
            />
            <KpiCard
                :label="t('ui.history.avg_confidence')"
                :value="stats.avg_confidence * 100"
                :format="(n: number) => fmt.percent(n / 100)"
                :icon="Gauge"
                tone="ai"
            />
        </section>

        <section class="space-y-4" :aria-label="t('ui.history.log')">
            <div class="flex flex-wrap items-center gap-2">
                <SearchInput
                    v-model="filters.q"
                    :placeholder="t('ui.history.search')"
                    class="w-full sm:w-64"
                />
                <SegmentedControl
                    v-model="statusModel"
                    :options="statusOptions"
                    :label="t('ui.common.status')"
                    data-test="history-filter-status"
                />
                <FilterSelect
                    v-model="filters.refusal_reason"
                    :label="t('ui.history.reason')"
                    :all-label="t('ui.history.all_reasons')"
                    :options="reasonOptions"
                    test-id="history-filter-reason"
                />
                <FilterSelect
                    v-model="filters.channel"
                    :label="t('ui.history.channel')"
                    :all-label="t('ui.history.all_channels')"
                    :options="channelOptions"
                />
                <FilterSelect
                    v-if="scope !== 'self' && departmentOptions.length"
                    v-model="filters.department_id"
                    :label="t('ui.common.department')"
                    :all-label="t('ui.common.all_departments')"
                    :options="departmentOptions"
                />
                <Button
                    v-if="active()"
                    variant="ghost"
                    size="sm"
                    @click="reset"
                >
                    <FilterX class="size-4" aria-hidden="true" />
                    {{ t('ui.common.clear_filters') }}
                </Button>
            </div>

            <DataTable
                :columns="columns"
                :rows="queries.data"
                :row-key="(row) => row.id"
                :row-attrs="
                    (row) => ({
                        'data-test': 'history-row',
                        'data-query-id': String(row.id),
                        'data-refused': row.refused ? 'true' : 'false',
                    })
                "
                :caption="t('ui.history.log')"
                clickable
                @row-click="(row) => (selected = row)"
            >
                <template #cell-created_at="{ row }">
                    <span
                        class="whitespace-nowrap text-muted-foreground tabular-nums"
                        >{{ fmt.dateTime(row.created_at, 'short') }}</span
                    >
                </template>
                <template #cell-user="{ row }">
                    <span class="flex flex-col">
                        <span class="font-medium">{{
                            row.user?.name ?? t('ui.history.anonymous')
                        }}</span>
                        <span
                            v-if="row.role_at_time"
                            class="text-xs text-muted-foreground"
                            >{{ t(`roles.${row.role_at_time}`) }}</span
                        >
                    </span>
                </template>
                <template #cell-departments="{ row }">
                    <DepartmentChips
                        :departments="row.departments_at_time"
                        :max="2"
                    />
                </template>
                <template #cell-question="{ row }">
                    <p class="line-clamp-2 max-w-md min-w-48 font-medium">
                        {{ row.question }}
                    </p>
                </template>
                <template #cell-refused="{ row }">
                    <StatusBadge
                        :tone="resultTone(row)"
                        :label="resultLabel(row)"
                    />
                </template>
                <template #cell-confidence="{ row }">
                    <ConfidenceMeter :value="row.confidence" />
                </template>
                <template #cell-cited="{ row }">
                    <span class="tabular-nums">{{
                        row.cited_document_versions.length
                    }}</span>
                </template>
                <template #cell-channel="{ row }">
                    <span
                        class="inline-flex items-center gap-1.5 text-muted-foreground"
                    >
                        <TelegramIcon
                            v-if="row.channel === 'telegram'"
                            class="size-3.5"
                        />
                        <Globe v-else class="size-3.5" aria-hidden="true" />
                        {{ t(`ui.history.channels.${row.channel}`) }}
                    </span>
                </template>
                <template #empty>
                    <EmptyState
                        :icon="active() ? SearchX : MessagesSquare"
                        :scene="active() ? 'search' : 'chat'"
                        tone="ai"
                        :title="
                            active()
                                ? t('ui.history.empty_filtered')
                                : t('ui.history.empty_title')
                        "
                        :description="
                            active()
                                ? t('ui.common.try_other_filters')
                                : t('ui.history.empty_body')
                        "
                    >
                        <Button
                            v-if="active()"
                            variant="outline"
                            @click="reset"
                            >{{ t('ui.common.clear_filters') }}</Button
                        >
                        <Button v-else as-child>
                            <Link :href="endpoints.assistant.index()">{{
                                t('ui.history.open_assistant')
                            }}</Link>
                        </Button>
                    </EmptyState>
                </template>
                <template #footer>
                    <LaravelPagination
                        :links="queries.links"
                        :meta="queries.meta"
                        :count="queries.data.length"
                        :only="['queries', 'filters', 'stats']"
                    />
                </template>
            </DataTable>
            <p class="text-caption">{{ t('ui.history.retention') }}</p>
        </section>
    </div>

    <Sheet v-model:open="detailOpen">
        <SheetContent
            side="right"
            class="w-full overflow-y-auto sm:max-w-xl"
            data-test="history-drawer"
        >
            <SheetHeader v-if="selected">
                <SheetTitle>{{ t('ui.history.detail_title') }}</SheetTitle>
                <SheetDescription>
                    {{ fmt.dateTime(selected.created_at, 'short') }} ·
                    {{ t(`ui.history.channels.${selected.channel}`) }}
                    <template v-if="selected.user">
                        · {{ selected.user.name }}</template
                    >
                </SheetDescription>
            </SheetHeader>
            <div v-if="selected" class="space-y-5 px-4 pb-6">
                <div
                    class="rounded-2xl bg-primary px-4 py-3 text-primary-foreground"
                >
                    <p class="text-xs font-semibold uppercase opacity-80">
                        {{ t('ui.history.question') }}
                    </p>
                    <p class="mt-1 whitespace-pre-wrap">
                        {{ selected.question }}
                    </p>
                </div>
                <div class="rounded-2xl border bg-card px-4 py-3">
                    <div class="flex items-center justify-between gap-2">
                        <p
                            class="text-xs font-semibold text-muted-foreground uppercase"
                        >
                            {{ t('ui.history.answer') }}
                        </p>
                        <StatusBadge
                            :tone="resultTone(selected)"
                            :label="resultLabel(selected)"
                        />
                    </div>
                    <p class="mt-2 text-sm leading-relaxed whitespace-pre-wrap">
                        {{ selected.answer ?? '—' }}
                    </p>
                    <p
                        v-for="(line, index) in selected.disclaimers"
                        :key="index"
                        class="mt-2 text-sm text-muted-foreground italic"
                    >
                        {{ line }}
                    </p>
                </div>

                <div
                    v-if="selected.cited_document_versions.length"
                    class="space-y-2"
                >
                    <p
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        {{ t('ui.history.cited_versions') }}
                    </p>
                    <ul class="space-y-1.5">
                        <li
                            v-for="version in selected.cited_document_versions"
                            :key="version.document_id"
                            data-test="history-citation-version"
                            :data-archived="
                                version.archived_now ? 'true' : 'false'
                            "
                        >
                            <component
                                :is="can('knowledge.view') ? Link : 'div'"
                                :href="
                                    can('knowledge.view')
                                        ? endpoints.knowledge.show(
                                              version.document_id,
                                          ).url
                                        : undefined
                                "
                                class="flex flex-wrap items-center gap-2 rounded-xl border p-2.5 text-sm transition-colors hover:bg-surface-2"
                            >
                                <FileText
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 font-medium">{{
                                    version.title
                                }}</span>
                                <span
                                    v-if="version.version"
                                    class="font-mono text-xs text-muted-foreground"
                                    >{{
                                        t('ui.assistant.version', {
                                            version: version.version,
                                        })
                                    }}</span
                                >
                                <span
                                    v-if="version.effective_date"
                                    class="text-xs text-muted-foreground"
                                    >{{
                                        t('ui.knowledge.from_date', {
                                            date: fmt.date(
                                                `${version.effective_date}T12:00:00`,
                                            ),
                                        })
                                    }}</span
                                >
                                <OpsBadge
                                    v-if="version.archived_now"
                                    tone="sun"
                                    :icon="TriangleAlert"
                                    :label="t('ui.history.archived_now')"
                                />
                            </component>
                        </li>
                    </ul>
                </div>

                <div v-if="selected.actions.length" class="space-y-2">
                    <p
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        {{ t('ui.history.actions') }}
                    </p>
                    <ul class="flex flex-wrap gap-2">
                        <li
                            v-for="(action, index) in selected.actions"
                            :key="index"
                            data-test="history-action-status"
                            :data-status="action.status"
                        >
                            <OpsBadge
                                :tone="
                                    action.status === 'executed'
                                        ? 'mint'
                                        : 'neutral'
                                "
                                :label="`${tOr(`ui.history.action_types.${action.type}`, action.type)} · ${tOr(`ui.assistant.action_status.${action.status}`, t('ui.history.proposed'))}`"
                            />
                        </li>
                    </ul>
                </div>

                <Link
                    v-if="
                        selected.escalated_question_id &&
                        can('questions.answer')
                    "
                    :href="questionUrl(selected.escalated_question_id)"
                    class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-primary-strong hover:underline"
                >
                    <Forward class="size-4" aria-hidden="true" />
                    {{ t('ui.history.open_escalation') }}
                </Link>

                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div class="rounded-xl bg-surface-2 p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.assistant.confidence') }}
                        </dt>
                        <dd class="mt-1">
                            <ConfidenceMeter :value="selected.confidence" />
                        </dd>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.history.latency') }}
                        </dt>
                        <dd class="mt-1 font-semibold tabular-nums">
                            {{ fmt.duration(selected.latency_ms) }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.history.model') }}
                        </dt>
                        <dd class="mt-1 truncate font-mono text-xs">
                            {{ selected.provider ?? '—' }} /
                            {{ selected.model ?? '—' }}
                        </dd>
                    </div>
                    <div class="rounded-xl bg-surface-2 p-3">
                        <dt class="text-xs text-muted-foreground">
                            {{ t('ui.history.asked_at') }}
                        </dt>
                        <dd class="mt-1 text-xs">
                            <RelativeTime :value="selected.created_at" />
                        </dd>
                    </div>
                </dl>
            </div>
        </SheetContent>
    </Sheet>
</template>

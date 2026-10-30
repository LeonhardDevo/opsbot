<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { FilterX, Plus, Siren, SquareKanban, Table } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import SeverityBadge from '@/components/chat/SeverityBadge.vue';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import CategoryChip from '@/components/opsbot/CategoryChip.vue';
import CommentPromptDialog from '@/components/opsbot/CommentPromptDialog.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import IncidentCard from '@/components/opsbot/IncidentCard.vue';
import IncidentCreateDialog from '@/components/opsbot/IncidentCreateDialog.vue';
import IncidentStatusBadge from '@/components/opsbot/IncidentStatusBadge.vue';
import KanbanBoard from '@/components/opsbot/KanbanBoard.vue';
import type { BoardColumn } from '@/components/opsbot/KanbanBoard.vue';
import PersonChip from '@/components/opsbot/PersonChip.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFilters } from '@/composables/useFilters';
import { useIncidentTransition } from '@/composables/useIncidentTransition';
import { endpoints, withQuery } from '@/lib/endpoints';
import { t, tc } from '@/lib/i18n';
import {
    INCIDENT_CATEGORIES,
    INCIDENT_STATUSES,
    incidentTransitions,
    SEVERITIES,
} from '@/lib/opsbot';
import { incidentStatusIcon } from '@/lib/opsbot-icons';
import type { Paginated } from '@/types/models';
import type {
    DepartmentRef,
    Incident,
    IncidentFilters,
    IncidentStatus,
    UserRef,
} from '@/types/opsbot';

const props = defineProps<{
    view: 'board' | 'table';
    incidents: Paginated<Incident>;
    board: Record<IncidentStatus, Incident[]>;
    filters: IncidentFilters;
    counts: Record<IncidentStatus, number>;
    departments: DepartmentRef[];
    assignees: UserRef[];
    can: { create: boolean; manage: boolean };
}>();

const page = usePage();
const me = computed(() => page.props.auth.user?.id ?? 0);

const { filters, reset, active } = useFilters(
    () => endpoints.incidents.index().url,
    {
        view: props.view,
        q: props.filters.q ?? null,
        status: props.filters.status ?? null,
        category: props.filters.category ?? null,
        severity: props.filters.severity ?? null,
        department_id: props.filters.department_id ?? null,
        assignee: props.filters.assignee ?? null,
        from: props.filters.from ?? null,
        to: props.filters.to ?? null,
    },
    ['incidents', 'board', 'filters', 'counts', 'view'],
);

const view = computed({
    get: () => (filters.view as 'board' | 'table') ?? 'board',
    set: (value: 'board' | 'table') => {
        filters.view = value;
    },
});

const filtered = computed(() =>
    Object.entries(filters).some(
        ([key, value]) => key !== 'view' && value !== null && value !== '',
    ),
);

const creating = ref(false);

onMounted(() => {
    const params = new URLSearchParams(window.location.search);

    if (props.can.create && params.get('create') === '1') {
        creating.value = true;
    }

    if (
        !params.get('view') &&
        window.matchMedia('(max-width: 1023px)').matches &&
        props.view === 'board'
    ) {
        view.value = 'table';
    }
});

const transition = useIncidentTransition(['incidents', 'board', 'counts']);

function movesFor(incident: Incident): IncidentStatus[] {
    return incidentTransitions(incident.status, {
        manage: props.can.manage,
        assignee: incident.assignee?.id === me.value,
    });
}

function denied(incident: Incident, to: IncidentStatus): void {
    toast.error(
        t('ui.board.denied', {
            from: t(`incidents.status.${incident.status}`),
            to: t(`incidents.status.${to}`),
        }),
    );
}

const columns = computed<BoardColumn<IncidentStatus>[]>(() =>
    INCIDENT_STATUSES.map((status) => ({
        status,
        label: t(`incidents.status.${status}`),
        count: props.counts[status] ?? 0,
        icon: incidentStatusIcon[status],
        collapsed: status === 'closed',
    })),
);

const total = computed(() =>
    INCIDENT_STATUSES.reduce(
        (sum, status) => sum + (props.counts[status] ?? 0),
        0,
    ),
);

const tableColumns = computed<DataColumn<Incident>[]>(() => [
    { id: 'number', header: t('ui.incidents.fields.number'), sortable: true },
    { id: 'title', header: t('ui.incidents.fields.title') },
    {
        id: 'category',
        header: t('ui.incidents.fields.category'),
        hideBelow: 'lg',
    },
    {
        id: 'severity',
        header: t('ui.incidents.fields.severity'),
        sortable: true,
    },
    { id: 'status', header: t('ui.common.status'), sortable: true },
    {
        id: 'department',
        header: t('ui.common.department'),
        hideBelow: 'xl',
        accessor: (row) => row.department?.name ?? '',
    },
    {
        id: 'assignee',
        header: t('ui.incidents.fields.assignee'),
        hideBelow: 'md',
        accessor: (row) => row.assignee?.name ?? '',
    },
    {
        id: 'reporter',
        header: t('ui.incidents.fields.reporter'),
        hideBelow: 'xl',
        accessor: (row) => row.reporter?.name ?? '',
    },
    {
        id: 'created_at',
        header: t('ui.incidents.fields.created'),
        sortable: true,
        hideBelow: 'sm',
    },
    {
        id: 'age_hours',
        header: t('ui.incidents.age'),
        sortable: true,
        align: 'right',
        hideBelow: 'lg',
    },
]);

const assigneeOptions = computed(() => [
    { value: 'me', label: t('ui.common.mine') },
    ...props.assignees.map((user) => ({ value: user.id, label: user.name })),
]);

watch(
    () => props.view,
    (value) => {
        filters.view = value;
    },
);
</script>

<template>
    <Head :title="t('ui.incidents.title')" />

    <div
        class="mx-auto flex w-full max-w-[1440px] flex-col gap-5 px-4 py-6 md:px-6"
    >
        <PageHeader
            :title="t('ui.incidents.title')"
            :description="t('ui.incidents.subtitle')"
            :icon="Siren"
        >
            <template #actions>
                <div
                    role="radiogroup"
                    :aria-label="t('ui.board.view')"
                    class="inline-flex h-11 items-center gap-0.5 rounded-control border bg-surface-2 p-1"
                    data-test="incidents-view-toggle"
                >
                    <button
                        v-for="option in [
                            {
                                value: 'board',
                                icon: SquareKanban,
                                label: t('ui.board.board'),
                            },
                            {
                                value: 'table',
                                icon: Table,
                                label: t('ui.board.table'),
                            },
                        ] as const"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="view === option.value"
                        :data-view="option.value"
                        :class="[
                            'inline-flex h-full items-center gap-1.5 rounded-[10px] px-3 text-sm font-medium',
                            view === option.value
                                ? 'bg-surface shadow-clay-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        @click="view = option.value"
                    >
                        <component
                            :is="option.icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ option.label }}
                    </button>
                </div>
                <Button
                    v-if="can.create"
                    data-test="incidents-new"
                    @click="creating = true"
                >
                    <Plus class="size-4" aria-hidden="true" />
                    {{ t('ui.incidents.new') }}
                </Button>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <SearchInput
                v-model="filters.q"
                :placeholder="t('ui.incidents.search')"
                class="w-full sm:w-64"
            />
            <FilterSelect
                v-if="view === 'table'"
                v-model="filters.status"
                :label="t('ui.common.status')"
                :all-label="t('ui.common.all_statuses')"
                :options="
                    INCIDENT_STATUSES.map((s) => ({
                        value: s,
                        label: t(`incidents.status.${s}`),
                    }))
                "
            />
            <FilterSelect
                v-model="filters.category"
                :label="t('ui.incidents.fields.category')"
                :all-label="t('ui.incidents.all_categories')"
                :options="
                    INCIDENT_CATEGORIES.map((c) => ({
                        value: c,
                        label: t(`incidents.category.${c}`),
                    }))
                "
            />
            <FilterSelect
                v-model="filters.severity"
                :label="t('ui.incidents.fields.severity')"
                :all-label="t('ui.incidents.all_severities')"
                :options="
                    SEVERITIES.map((s) => ({
                        value: s,
                        label: t(`incidents.severity.${s}`),
                    }))
                "
            />
            <FilterSelect
                v-if="departments.length"
                v-model="filters.department_id"
                :label="t('ui.common.department')"
                :all-label="t('ui.common.all_departments')"
                :options="
                    departments.map((d) => ({ value: d.id, label: d.name }))
                "
            />
            <FilterSelect
                v-model="filters.assignee"
                :label="t('ui.incidents.fields.assignee')"
                :all-label="t('ui.common.anyone')"
                :options="assigneeOptions"
            />
            <label class="flex items-center gap-1.5 text-sm">
                <span class="sr-only">{{ t('ui.common.from') }}</span>
                <Input
                    :model-value="filters.from ?? ''"
                    type="date"
                    class="h-11 w-40"
                    :aria-label="t('ui.common.from')"
                    @update:model-value="
                        (value) => (filters.from = value ? String(value) : null)
                    "
                />
            </label>
            <label class="flex items-center gap-1.5 text-sm">
                <span class="sr-only">{{ t('ui.common.to') }}</span>
                <Input
                    :model-value="filters.to ?? ''"
                    type="date"
                    class="h-11 w-40"
                    :aria-label="t('ui.common.to')"
                    @update:model-value="
                        (value) => (filters.to = value ? String(value) : null)
                    "
                />
            </label>
            <Button
                v-if="filtered"
                variant="ghost"
                @click="
                    reset();
                    filters.view = view;
                "
            >
                <FilterX class="size-4" aria-hidden="true" />
                {{ t('ui.common.clear_filters') }}
            </Button>
        </div>

        <p class="text-caption" aria-live="polite">
            {{ tc('ui.incidents.count', total) }}
        </p>

        <EmptyState
            v-if="total === 0 && !filtered"
            :icon="Siren"
            scene="chat"
            tone="ai"
            :title="t('ui.incidents.empty_title')"
            :description="t('ui.incidents.empty_body')"
            class="rounded-card border bg-surface shadow-clay-sm"
        >
            <Button v-if="can.create" @click="creating = true">
                <Plus class="size-4" aria-hidden="true" />
                {{ t('ui.incidents.new') }}
            </Button>
        </EmptyState>

        <EmptyState
            v-else-if="total === 0"
            :icon="FilterX"
            scene="search"
            tone="muted"
            :title="t('ui.common.no_results')"
            :description="t('ui.common.try_other_filters')"
            compact
            class="rounded-card border bg-surface"
        />

        <KanbanBoard
            v-else-if="view === 'board'"
            :columns="columns"
            :items="board"
            :item-key="(item) => item.id"
            :status-of="(item) => item.status"
            :can-move="(item, to) => movesFor(item).includes(to)"
            column-test="incidents-column"
            @move="transition.request"
            @denied="denied"
        >
            <template #card="{ item }">
                <IncidentCard
                    :incident="item"
                    :moves="movesFor(item)"
                    @move="transition.request"
                />
            </template>
        </KanbanBoard>

        <template v-else>
            <DataTable
                :columns="tableColumns"
                :rows="incidents.data"
                :row-key="(row) => row.id"
                :caption="t('ui.incidents.title')"
                clickable
                :row-attrs="
                    (row) => ({
                        'data-test': 'incident-card',
                        'data-number': String(row.number),
                        'data-severity': row.severity,
                        'data-status': row.status,
                    })
                "
                @row-click="
                    (row) => router.visit(endpoints.incidents.show(row.id).url)
                "
            >
                <template #cell-number="{ row }">
                    <span class="font-mono text-[0.8125rem]">{{
                        row.display_number
                    }}</span>
                </template>
                <template #cell-title="{ row }">
                    <Link
                        :href="endpoints.incidents.show(row.id)"
                        class="line-clamp-2 font-medium hover:underline"
                        >{{ row.title }}</Link
                    >
                </template>
                <template #cell-category="{ row }"
                    ><CategoryChip :category="row.category"
                /></template>
                <template #cell-severity="{ row }"
                    ><SeverityBadge :severity="row.severity"
                /></template>
                <template #cell-status="{ row }"
                    ><IncidentStatusBadge :status="row.status"
                /></template>
                <template #cell-assignee="{ row }"
                    ><PersonChip :user="row.assignee"
                /></template>
                <template #cell-created_at="{ row }"
                    ><RelativeTime :value="row.created_at"
                /></template>
                <template #cell-age_hours="{ row }">
                    <span class="tabular-nums">{{
                        tc('ui.units.hours_short', row.age_hours)
                    }}</span>
                </template>
            </DataTable>
            <LaravelPagination
                :links="incidents.links"
                :meta="incidents.meta"
                :count="incidents.data.length"
                :only="['incidents']"
            />
        </template>
    </div>

    <IncidentCreateDialog
        v-model:open="creating"
        :departments="departments"
        :assignees="assignees"
        :can-assign="can.manage"
    />
    <CommentPromptDialog
        v-model:open="transition.promptOpen.value"
        :title="transition.promptTitle.value"
        :required="transition.required.value"
        :processing="transition.processing.value"
        @confirm="transition.confirm"
    />
</template>

<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlarmClock,
    CalendarDays,
    CalendarRange,
    FilterX,
    ListChecks,
    Plus,
    SquareKanban,
    Table,
    UserRound,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import type { DataColumn } from '@/components/common/DataTable.vue';
import DataTable from '@/components/common/DataTable.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import DueChip from '@/components/opsbot/DueChip.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import KanbanBoard from '@/components/opsbot/KanbanBoard.vue';
import type { BoardColumn } from '@/components/opsbot/KanbanBoard.vue';
import PersonChip from '@/components/opsbot/PersonChip.vue';
import TaskCard from '@/components/opsbot/TaskCard.vue';
import TaskDrawer from '@/components/opsbot/TaskDrawer.vue';
import TaskSheet from '@/components/opsbot/TaskSheet.vue';
import TaskStatusBadge from '@/components/opsbot/TaskStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { useFilters } from '@/composables/useFilters';
import { endpoints } from '@/lib/endpoints';
import { t, tc } from '@/lib/i18n';
import { TASK_STATUSES, taskTransitions } from '@/lib/opsbot';
import { taskStatusIcon } from '@/lib/opsbot-icons';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/models';
import type {
    DepartmentRef,
    Task,
    TaskFilters,
    TaskStatus,
    UserRef,
} from '@/types/opsbot';

const props = defineProps<{
    view: 'board' | 'table';
    tasks: Paginated<Task>;
    board: Record<TaskStatus, Task[]>;
    filters: TaskFilters;
    counts: Record<TaskStatus, number> & { overdue: number };
    departments: DepartmentRef[];
    assignees: UserRef[];
    can: { create: boolean; manage: boolean };
}>();

const page = usePage();
const me = computed(() => page.props.auth.user?.id ?? 0);

const { filters, reset } = useFilters(
    () => endpoints.tasks.index().url,
    {
        view: props.view,
        q: props.filters.q ?? null,
        status: props.filters.status ?? null,
        assignee: props.filters.assignee ?? null,
        department_id: props.filters.department_id ?? null,
        due: props.filters.due ?? null,
    },
    ['tasks', 'board', 'filters', 'counts', 'view'],
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

const quick = computed(() => [
    {
        key: 'mine',
        label: t('ui.tasks.quick.mine'),
        icon: UserRound,
        active: filters.assignee === 'me',
        count: null,
    },
    {
        key: 'overdue',
        label: t('ui.tasks.quick.overdue'),
        icon: AlarmClock,
        active: filters.due === 'overdue',
        count: props.counts.overdue,
    },
    {
        key: 'today',
        label: t('ui.tasks.quick.today'),
        icon: CalendarDays,
        active: filters.due === 'today',
        count: null,
    },
    {
        key: 'week',
        label: t('ui.tasks.quick.week'),
        icon: CalendarRange,
        active: filters.due === 'week',
        count: null,
    },
]);

function toggleQuick(key: string): void {
    if (key === 'mine') {
        filters.assignee = filters.assignee === 'me' ? null : 'me';

        return;
    }

    filters.due =
        filters.due === key ? null : (key as 'overdue' | 'today' | 'week');
}

function movesFor(task: Task): TaskStatus[] {
    return taskTransitions(task.status, {
        manage: props.can.manage,
        assignee: task.assignee?.id === me.value,
        creator: task.created_by?.id === me.value,
    });
}

const pending = ref<number | null>(null);

function move(task: Task, to: TaskStatus): void {
    pending.value = task.id;
    router.patch(
        endpoints.tasks.update(task.id).url,
        { status: to },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['tasks', 'board', 'counts'],
            onSuccess: () => {
                if (selected.value?.id === task.id) {
                    drawerOpen.value = false;
                }
            },
            onFinish: () => (pending.value = null),
        },
    );
}

function denied(task: Task, to: TaskStatus): void {
    toast.error(
        t('ui.board.denied', {
            from: t(`tasks.status.${task.status}`),
            to: t(`tasks.status.${to}`),
        }),
    );
}

const columns = computed<BoardColumn<TaskStatus>[]>(() =>
    TASK_STATUSES.map((status) => ({
        status,
        label: t(`tasks.status.${status}`),
        count: props.counts[status] ?? 0,
        icon: taskStatusIcon[status],
        collapsed: status === 'cancelled',
    })),
);

const total = computed(() =>
    TASK_STATUSES.reduce((sum, status) => sum + (props.counts[status] ?? 0), 0),
);

const selected = ref<Task | null>(null);
const drawerOpen = ref(false);
const editing = ref<Task | null>(null);
const sheetOpen = ref(false);

function openTask(task: Task): void {
    selected.value = task;
    drawerOpen.value = true;
}

function editTask(task: Task): void {
    drawerOpen.value = false;
    editing.value = task;
    sheetOpen.value = true;
}

function createTask(): void {
    editing.value = null;
    sheetOpen.value = true;
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    const focus = Number(params.get('focus'));

    if (focus) {
        const all = [...props.tasks.data, ...Object.values(props.board).flat()];
        const found = all.find((task) => task.id === focus);

        if (found) {
            openTask(found);
        }
    }

    if (props.can.create && params.get('create') === '1') {
        createTask();
    }

    if (
        !params.get('view') &&
        window.matchMedia('(max-width: 1023px)').matches &&
        props.view === 'board'
    ) {
        view.value = 'table';
    }
});

const tableColumns = computed<DataColumn<Task>[]>(() => [
    { id: 'number', header: t('ui.tasks.fields.number'), sortable: true },
    { id: 'title', header: t('ui.tasks.fields.title') },
    { id: 'status', header: t('ui.common.status'), sortable: true },
    { id: 'due_at', header: t('ui.tasks.fields.due'), sortable: true },
    {
        id: 'assignee',
        header: t('ui.tasks.fields.assignee'),
        hideBelow: 'md',
        accessor: (row) => row.assignee?.name ?? '',
    },
    {
        id: 'department',
        header: t('ui.common.department'),
        hideBelow: 'xl',
        accessor: (row) => row.department?.name ?? '',
    },
    {
        id: 'incident',
        header: t('ui.tasks.fields.incident'),
        hideBelow: 'lg',
        accessor: (row) => row.incident?.display_number ?? '',
    },
    {
        id: 'created_by',
        header: t('ui.tasks.fields.created_by'),
        hideBelow: 'xl',
        accessor: (row) => row.created_by?.name ?? '',
    },
    { id: 'source', header: t('ui.tasks.fields.source'), hideBelow: 'xl' },
]);

const assigneeOptions = computed(() => [
    { value: 'me', label: t('ui.common.mine') },
    ...props.assignees.map((user) => ({ value: user.id, label: user.name })),
]);
</script>

<template>
    <Head :title="t('ui.tasks.title')" />

    <div
        class="mx-auto flex w-full max-w-[1440px] flex-col gap-5 px-4 py-6 md:px-6"
    >
        <PageHeader
            :title="t('ui.tasks.title')"
            :description="t('ui.tasks.subtitle')"
            :icon="ListChecks"
        >
            <template #actions>
                <div
                    role="radiogroup"
                    :aria-label="t('ui.board.view')"
                    class="inline-flex h-11 items-center gap-0.5 rounded-control border bg-surface-2 p-1"
                    data-test="tasks-view-toggle"
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
                    data-test="tasks-new"
                    @click="createTask"
                >
                    <Plus class="size-4" aria-hidden="true" />
                    {{ t('ui.tasks.new') }}
                </Button>
            </template>
        </PageHeader>

        <div
            class="flex flex-wrap gap-2"
            role="group"
            :aria-label="t('ui.tasks.quick.label')"
        >
            <button
                v-for="chip in quick"
                :key="chip.key"
                type="button"
                :aria-pressed="chip.active"
                :class="
                    cn(
                        'press inline-flex min-h-11 items-center gap-1.5 rounded-chip border px-3.5 text-sm font-medium',
                        chip.active
                            ? 'border-primary bg-primary-soft text-primary-strong'
                            : 'bg-surface hover:bg-surface-2',
                        chip.key === 'overdue' &&
                            (chip.count ?? 0) > 0 &&
                            !chip.active
                            ? 'text-coral-strong'
                            : '',
                    )
                "
                data-test="tasks-quick-filter"
                :data-filter="chip.key"
                @click="toggleQuick(chip.key)"
            >
                <component :is="chip.icon" class="size-4" aria-hidden="true" />
                {{ chip.label }}
                <span
                    v-if="chip.count"
                    class="rounded-chip bg-coral-soft px-1.5 font-mono text-xs font-semibold text-coral-strong"
                    >{{ chip.count }}</span
                >
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <SearchInput
                v-model="filters.q"
                :placeholder="t('ui.tasks.search')"
                class="w-full sm:w-64"
            />
            <FilterSelect
                v-if="view === 'table'"
                v-model="filters.status"
                :label="t('ui.common.status')"
                :all-label="t('ui.common.all_statuses')"
                :options="
                    TASK_STATUSES.map((s) => ({
                        value: s,
                        label: t(`tasks.status.${s}`),
                    }))
                "
            />
            <FilterSelect
                v-model="filters.assignee"
                :label="t('ui.tasks.fields.assignee')"
                :all-label="t('ui.common.anyone')"
                :options="assigneeOptions"
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
            {{ tc('ui.tasks.count', total) }}
        </p>

        <EmptyState
            v-if="total === 0 && !filtered"
            :icon="ListChecks"
            scene="documents"
            tone="ai"
            :title="t('ui.tasks.empty_title')"
            :description="
                can.create
                    ? t('ui.tasks.empty_body_manager')
                    : t('ui.tasks.empty_body_employee')
            "
            class="rounded-card border bg-surface shadow-clay-sm"
        >
            <Button v-if="can.create" @click="createTask">
                <Plus class="size-4" aria-hidden="true" />
                {{ t('ui.tasks.new') }}
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
            column-test="tasks-column"
            @move="move"
            @denied="denied"
        >
            <template #card="{ item }">
                <TaskCard
                    :task="item"
                    :moves="movesFor(item)"
                    :pending="pending === item.id"
                    @move="move"
                    @open="openTask"
                />
            </template>
        </KanbanBoard>

        <template v-else>
            <DataTable
                :columns="tableColumns"
                :rows="tasks.data"
                :row-key="(row) => row.id"
                :caption="t('ui.tasks.title')"
                clickable
                :row-attrs="
                    (row) => ({
                        'data-test': 'task-card',
                        'data-number': String(row.number),
                        'data-status': row.status,
                        'data-overdue': row.overdue ? 'true' : 'false',
                    })
                "
                @row-click="openTask"
            >
                <template #cell-number="{ row }">
                    <span class="font-mono text-[0.8125rem]">{{
                        row.display_number
                    }}</span>
                </template>
                <template #cell-title="{ row }">
                    <span class="line-clamp-2 font-medium">{{
                        row.title
                    }}</span>
                </template>
                <template #cell-status="{ row }"
                    ><TaskStatusBadge :status="row.status"
                /></template>
                <template #cell-due_at="{ row }">
                    <DueChip
                        :due-at="row.due_at"
                        :done="
                            row.status === 'done' || row.status === 'cancelled'
                        "
                    />
                </template>
                <template #cell-assignee="{ row }"
                    ><PersonChip :user="row.assignee"
                /></template>
                <template #cell-incident="{ row }">
                    <Link
                        v-if="row.incident"
                        :href="endpoints.incidents.show(row.incident.id)"
                        class="font-mono text-primary-strong hover:underline"
                        >{{ row.incident.display_number }}</Link
                    >
                    <span v-else>—</span>
                </template>
                <template #cell-source="{ row }">{{
                    t(`tasks.source.${row.source}`)
                }}</template>
            </DataTable>
            <LaravelPagination
                :links="tasks.links"
                :meta="tasks.meta"
                :count="tasks.data.length"
                :only="['tasks']"
            />
        </template>
    </div>

    <TaskDrawer
        v-model:open="drawerOpen"
        :task="selected"
        :moves="selected ? movesFor(selected) : []"
        :can-edit="can.manage"
        @move="move"
        @edit="editTask"
    />
    <TaskSheet
        v-model:open="sheetOpen"
        :task="editing"
        :assignees="assignees"
        :departments="departments"
        :can-assign="can.manage || can.create"
        :can-manage="can.manage"
    />
</template>

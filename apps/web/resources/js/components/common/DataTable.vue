<script setup lang="ts" generic="TRow extends object">
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue';
import type { ColumnDef, SortingState } from '@tanstack/vue-table';
import {
    getCoreRowModel,
    getSortedRowModel,
    useVueTable,
} from '@tanstack/vue-table';
import { computed, ref } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type DataColumn<T> = {
    id: string;
    header: string;
    accessor?: (row: T) => unknown;
    sortable?: boolean;
    align?: 'left' | 'right' | 'center';
    hideBelow?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
    class?: string;
    srOnlyHeader?: boolean;
};

const props = withDefaults(
    defineProps<{
        columns: DataColumn<TRow>[];
        rows: TRow[];
        rowKey: (row: TRow) => string | number;
        caption: string;
        loading?: boolean;
        skeletonRows?: number;
        rowClass?: (row: TRow) => string;
        rowAttrs?: (row: TRow) => Record<string, string | undefined>;
        clickable?: boolean;
    }>(),
    {
        loading: false,
        skeletonRows: 6,
        rowClass: undefined,
        rowAttrs: undefined,
        clickable: false,
    },
);

const emit = defineEmits<{ rowClick: [row: TRow] }>();

const sorting = ref<SortingState>([]);

const columnDefs = computed<ColumnDef<TRow, unknown>[]>(() =>
    props.columns.map((column) => ({
        id: column.id,
        accessorFn:
            column.accessor ??
            ((row: TRow) => (row as Record<string, unknown>)[column.id]),
        header: column.header,
        enableSorting: column.sortable ?? false,
        sortUndefined: 'last',
    })),
);

const table = useVueTable({
    get data() {
        return props.rows;
    },
    get columns() {
        return columnDefs.value;
    },
    state: {
        get sorting() {
            return sorting.value;
        },
    },
    onSortingChange: (updater) => {
        sorting.value =
            typeof updater === 'function' ? updater(sorting.value) : updater;
    },
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    getRowId: (row) => String(props.rowKey(row)),
});

const meta = computed(
    () => new Map(props.columns.map((column) => [column.id, column])),
);

const hideClass = {
    sm: 'hidden sm:table-cell',
    md: 'hidden md:table-cell',
    lg: 'hidden lg:table-cell',
    xl: 'hidden xl:table-cell',
    '2xl': 'hidden 2xl:table-cell',
} as const;

const alignClass = {
    left: 'text-left',
    right: 'text-right',
    center: 'text-center',
} as const;

function cellClass(id: string): string {
    const column = meta.value.get(id);

    return cn(
        column?.hideBelow ? hideClass[column.hideBelow] : '',
        alignClass[column?.align ?? 'left'],
        column?.class,
    );
}

function ariaSort(id: string): 'ascending' | 'descending' | 'none' | undefined {
    const column = table.getColumn(id);

    if (!column?.getCanSort()) {
        return undefined;
    }

    const state = column.getIsSorted();

    return state === 'asc'
        ? 'ascending'
        : state === 'desc'
          ? 'descending'
          : 'none';
}

function onRowClick(event: MouseEvent, row: TRow): void {
    if (!props.clickable) {
        return;
    }

    const interactive = (event.target as HTMLElement).closest(
        'a, button, input, select, textarea, [role="menuitem"], [role="combobox"]',
    );

    if (interactive && interactive !== event.currentTarget) {
        return;
    }

    emit('rowClick', row);
}

function onRowKey(event: KeyboardEvent, row: TRow): void {
    if (event.target !== event.currentTarget) {
        return;
    }

    if (props.clickable && (event.key === 'Enter' || event.key === ' ')) {
        event.preventDefault();
        emit('rowClick', row);
    }
}
</script>

<template>
    <div
        class="relative overflow-hidden rounded-card border bg-card shadow-clay"
    >
        <div
            class="relative scrollbar-thin overflow-x-auto"
            role="region"
            :aria-label="caption"
            tabindex="0"
        >
            <table class="w-full caption-bottom text-sm">
                <caption class="sr-only">
                    {{
                        caption
                    }}
                </caption>
                <thead class="border-b bg-muted/50">
                    <tr
                        v-for="headerGroup in table.getHeaderGroups()"
                        :key="headerGroup.id"
                    >
                        <th
                            v-for="header in headerGroup.headers"
                            :key="header.id"
                            scope="col"
                            :aria-sort="ariaSort(header.column.id)"
                            :class="
                                cn(
                                    'h-11 bg-surface-2 px-4 text-sm font-semibold whitespace-nowrap text-muted-foreground',
                                    cellClass(header.column.id),
                                )
                            "
                        >
                            <span
                                v-if="meta.get(header.column.id)?.srOnlyHeader"
                                class="sr-only"
                                >{{ meta.get(header.column.id)?.header }}</span
                            >
                            <button
                                v-else-if="header.column.getCanSort()"
                                type="button"
                                class="-mx-1.5 inline-flex items-center gap-1 rounded-lg px-1.5 py-1 transition-colors hover:text-foreground"
                                @click="header.column.toggleSorting()"
                            >
                                {{ meta.get(header.column.id)?.header }}
                                <ArrowUp
                                    v-if="header.column.getIsSorted() === 'asc'"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <ArrowDown
                                    v-else-if="
                                        header.column.getIsSorted() === 'desc'
                                    "
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <ArrowUpDown
                                    v-else
                                    class="size-3.5 opacity-50"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">{{
                                    t('ui.table.sort')
                                }}</span>
                            </button>
                            <template v-else>{{
                                meta.get(header.column.id)?.header
                            }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <template v-if="loading">
                        <tr v-for="index in skeletonRows" :key="`s-${index}`">
                            <td
                                v-for="column in columns"
                                :key="column.id"
                                :class="cn('px-4 py-3.5', cellClass(column.id))"
                            >
                                <Skeleton class="h-4 w-full max-w-40" />
                            </td>
                        </tr>
                    </template>
                    <tr v-else-if="table.getRowModel().rows.length === 0">
                        <td :colspan="columns.length" class="p-0">
                            <slot name="empty">
                                <p
                                    class="px-4 py-10 text-center text-sm text-muted-foreground"
                                >
                                    {{ t('ui.table.empty') }}
                                </p>
                            </slot>
                        </td>
                    </tr>
                    <template v-else>
                        <tr
                            v-for="row in table.getRowModel().rows"
                            :key="row.id"
                            v-bind="rowAttrs?.(row.original) ?? {}"
                            :tabindex="clickable ? 0 : undefined"
                            :class="
                                cn(
                                    'h-[52px] transition-colors hover:bg-surface-2',
                                    clickable
                                        ? 'cursor-pointer focus-visible:bg-muted/60 focus-visible:outline-none'
                                        : '',
                                    rowClass?.(row.original),
                                )
                            "
                            @click="onRowClick($event, row.original)"
                            @keydown="onRowKey($event, row.original)"
                        >
                            <td
                                v-for="cell in row.getVisibleCells()"
                                :key="cell.id"
                                :class="
                                    cn(
                                        'px-4 py-3 align-middle',
                                        cellClass(cell.column.id),
                                    )
                                "
                            >
                                <slot
                                    :name="`cell-${cell.column.id}`"
                                    :row="row.original"
                                    :value="cell.getValue()"
                                >
                                    {{ cell.getValue() ?? '—' }}
                                </slot>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div v-if="$slots.footer" class="border-t px-4 py-3">
            <slot name="footer" />
        </div>
    </div>
</template>

<script setup lang="ts" generic="T, S extends string">
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { shallowRef } from 'vue';
import type { Component } from 'vue';
import { useBoardDnd } from '@/composables/useBoardDnd';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type BoardColumn<S> = {
    status: S;
    label: string;
    count: number;
    icon: Component;
    collapsed?: boolean;
};

const props = defineProps<{
    columns: BoardColumn<S>[];
    items: Record<S, T[]>;
    itemKey: (item: T) => string | number;
    statusOf: (item: T) => S;
    canMove: (item: T, to: S) => boolean;
    columnTest: string;
}>();

const emit = defineEmits<{
    move: [item: T, to: S];
    denied: [item: T, to: S];
}>();

const collapsed = shallowRef<Set<S>>(
    new Set(
        props.columns.filter((column) => column.collapsed).map((c) => c.status),
    ),
);

function toggle(status: S): void {
    const next = new Set(collapsed.value);

    if (next.has(status)) {
        next.delete(status);
    } else {
        next.add(status);
    }

    collapsed.value = next;
}

const dnd = useBoardDnd<T, S>({
    canMove: (item, to) => props.canMove(item, to),
    statusOf: (item) => props.statusOf(item),
    onMove: (item, to) => emit('move', item, to),
    onDenied: (item, to) => emit('denied', item, to),
});
</script>

<template>
    <div
        class="grid scrollbar-thin auto-cols-[minmax(17rem,1fr)] grid-flow-col gap-4 overflow-x-auto pb-2"
    >
        <section
            v-for="column in columns"
            :key="column.status"
            :aria-label="column.label"
            :data-test="columnTest"
            :data-status="column.status"
            :class="
                cn(
                    'flex min-h-48 flex-col rounded-card bg-surface-2 p-2 transition-colors',
                    dnd.over.value === column.status && dnd.dragging.value
                        ? canMove(dnd.dragging.value, column.status)
                            ? 'ring-2 ring-primary'
                            : 'ring-2 ring-coral'
                        : '',
                    collapsed.has(column.status) ? 'self-start' : '',
                )
            "
            @dragover="dnd.enter($event, column.status)"
            @dragleave="dnd.leave(column.status)"
            @drop="dnd.drop($event, column.status)"
        >
            <header class="flex items-center gap-2 px-2 py-1.5">
                <button
                    type="button"
                    class="inline-flex size-8 items-center justify-center rounded-xl hover:bg-surface pointer-coarse:size-11"
                    :aria-expanded="!collapsed.has(column.status)"
                    :aria-label="
                        collapsed.has(column.status)
                            ? t('ui.board.expand', { column: column.label })
                            : t('ui.board.collapse', { column: column.label })
                    "
                    @click="toggle(column.status)"
                >
                    <ChevronRight
                        v-if="collapsed.has(column.status)"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <ChevronDown v-else class="size-4" aria-hidden="true" />
                </button>
                <component
                    :is="column.icon"
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <h2 class="text-[0.9375rem] font-semibold">
                    {{ column.label }}
                </h2>
                <span
                    class="ml-auto rounded-chip bg-surface px-2 py-0.5 font-mono text-xs font-semibold tabular-nums"
                    >{{ column.count }}</span
                >
            </header>
            <ul
                v-if="!collapsed.has(column.status)"
                class="flex flex-1 flex-col gap-3 p-1"
            >
                <li
                    v-for="item in items[column.status] ?? []"
                    :key="itemKey(item)"
                    draggable="true"
                    :class="dnd.dragging.value === item ? 'opacity-60' : ''"
                    @dragstart="dnd.start($event, item)"
                    @dragend="dnd.end()"
                >
                    <slot name="card" :item="item" />
                </li>
                <li
                    v-if="!(items[column.status] ?? []).length"
                    class="rounded-2xl border border-dashed px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    {{ t('ui.board.column_empty') }}
                </li>
                <li
                    v-if="column.count > (items[column.status] ?? []).length"
                    class="px-2 text-center text-xs text-muted-foreground"
                >
                    {{
                        t('ui.board.more_in_table', {
                            count:
                                column.count -
                                (items[column.status] ?? []).length,
                        })
                    }}
                </li>
            </ul>
        </section>
    </div>
</template>

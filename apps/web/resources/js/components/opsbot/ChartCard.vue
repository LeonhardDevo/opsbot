<script setup lang="ts">
import { ChartColumn, Table } from '@lucide/vue';
import type { EChartsCoreOption } from 'echarts/core';
import { ref } from 'vue';
import ThemedChart from '@/components/charts/ThemedChart.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type { ChartTheme } from '@/lib/theme';

export type ChartTableColumn = {
    key: string;
    label: string;
    numeric?: boolean;
};

withDefaults(
    defineProps<{
        title: string;
        caption?: string | null;
        build: (theme: ChartTheme) => EChartsCoreOption;
        columns: ChartTableColumn[];
        rows: Array<Record<string, string | number | null>>;
        empty?: boolean;
        emptyText?: string | null;
        height?: string;
        testId?: string;
        toggleTestId?: string;
        class?: string;
    }>(),
    {
        caption: null,
        empty: false,
        emptyText: null,
        height: '16rem',
        testId: undefined,
        toggleTestId: undefined,
        class: '',
    },
);

const asTable = ref(false);
</script>

<template>
    <SectionCard
        :title="title"
        :description="caption"
        :class="$props.class"
        :data-test="testId"
    >
        <template #actions>
            <Button
                v-if="!empty"
                variant="ghost"
                size="sm"
                :aria-pressed="asTable"
                :data-test="toggleTestId"
                @click="asTable = !asTable"
            >
                <component
                    :is="asTable ? ChartColumn : Table"
                    class="size-4"
                    aria-hidden="true"
                />
                {{
                    asTable
                        ? t('ui.charts.show_chart')
                        : t('ui.charts.show_table')
                }}
            </Button>
        </template>
        <div
            v-if="empty"
            class="flex items-center justify-center rounded-2xl bg-surface-2 px-4 text-center text-sm text-muted-foreground"
            :style="{ minHeight: height }"
        >
            {{ emptyText ?? t('ui.charts.empty') }}
        </div>
        <div
            v-else-if="asTable"
            class="scrollbar-thin overflow-x-auto"
            :style="{ maxHeight: height }"
        >
            <table class="w-full text-sm">
                <caption class="sr-only">
                    {{
                        title
                    }}
                </caption>
                <thead>
                    <tr class="border-b text-muted-foreground">
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            :class="[
                                'px-2 py-2 font-semibold',
                                column.numeric ? 'text-right' : 'text-left',
                            ]"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="(row, index) in rows" :key="index">
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :class="[
                                'px-2 py-1.5',
                                column.numeric
                                    ? 'text-right tabular-nums'
                                    : '[overflow-wrap:anywhere]',
                            ]"
                        >
                            {{ row[column.key] ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <ThemedChart v-else :build="build" :label="title" :height="height" />
    </SectionCard>
</template>

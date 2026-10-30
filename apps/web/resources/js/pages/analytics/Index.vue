<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlarmClock,
    ChartColumn,
    CircleArrowUp,
    FileQuestion,
    FileX,
    ListChecks,
} from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import ChartCard from '@/components/opsbot/ChartCard.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import { Button } from '@/components/ui/button';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { useUpgrade } from '@/composables/useUpgrade';
import { endpoints, withQuery } from '@/lib/endpoints';
import { t, tc } from '@/lib/i18n';
import { displayLatency, INCIDENT_CATEGORIES } from '@/lib/opsbot';
import { groupedBars, horizontalBars, stackedBars } from '@/lib/opsbot-charts';
import type { AnalyticsProps } from '@/types/opsbot';

const props = defineProps<AnalyticsProps>();

const fmt = useFormat();
const { can } = useCan();
const upgrade = useUpgrade();

function visit(query: Record<string, string | number | null>): void {
    router.get(
        withQuery(endpoints.analytics.index().url, {
            period: props.period,
            department_id: props.departmentId,
            ...query,
        }),
        {},
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

const period = computed({
    get: () => props.period,
    set: (value: 7 | 30 | 90) => visit({ period: value }),
});

const department = computed({
    get: () => props.departmentId,
    set: (value: string | number | null | undefined) =>
        visit({ department_id: value ? Number(value) : null }),
});

const pct = (value: number | null) =>
    value === null ? '—' : fmt.value.percent(value);

const kpis = computed(() => [
    {
        key: 'questions',
        label: t('ui.analytics.kpis.questions'),
        value: fmt.value.number(props.kpis.questions),
    },
    {
        key: 'answered_pct',
        label: t('ui.analytics.kpis.answered'),
        value: pct(props.kpis.answered_pct),
    },
    {
        key: 'refused_pct',
        label: t('ui.analytics.kpis.refused'),
        value: pct(props.kpis.refused_pct),
    },
    {
        key: 'escalated_pct',
        label: t('ui.analytics.kpis.escalated'),
        value: pct(props.kpis.escalated_pct),
    },
    {
        key: 'median_latency_ms',
        label: t('ui.analytics.kpis.latency'),
        value: displayLatency(props.kpis.median_latency_ms, fmt.value.locale),
        hint:
            props.kpis.p90_latency_ms !== null
                ? t('ui.analytics.kpis.p90', {
                      value: displayLatency(
                          props.kpis.p90_latency_ms,
                          fmt.value.locale,
                      ),
                  })
                : null,
    },
    {
        key: 'active_askers',
        label: t('ui.analytics.kpis.askers'),
        value: fmt.value.number(props.kpis.active_askers),
    },
]);

const dayLabels = computed(() =>
    props.series.map((day) => fmt.value.dayLabel(`${day.date}T12:00:00`)),
);
const seriesEmpty = computed(() =>
    props.series.every((day) => day.total === 0),
);

const categoryValues = computed(() =>
    INCIDENT_CATEGORIES.map(
        (category) => props.incidents.by_category[category] ?? 0,
    ),
);
const categoriesEmpty = computed(() =>
    categoryValues.value.every((value) => value === 0),
);

const weekLabels = computed(() =>
    props.incidents.by_week.map((week) =>
        fmt.value.dayLabel(`${week.week_start}T12:00:00`),
    ),
);
const weeksEmpty = computed(() =>
    props.incidents.by_week.every((week) => week.opened + week.resolved === 0),
);

const taskTiles = computed(() => [
    {
        key: 'created',
        label: t('ui.analytics.tasks.created'),
        value: fmt.value.number(props.tasks.created),
    },
    {
        key: 'completed',
        label: t('ui.analytics.tasks.completed'),
        value: fmt.value.number(props.tasks.completed),
    },
    {
        key: 'overdue_now',
        label: t('ui.analytics.tasks.overdue'),
        value: fmt.value.number(props.tasks.overdue_now),
        alert: props.tasks.overdue_now > 0,
    },
    {
        key: 'on_time_pct',
        label: t('ui.analytics.tasks.on_time'),
        value: pct(props.tasks.on_time_pct),
    },
]);
</script>

<template>
    <Head :title="t('ui.analytics.title')" />

    <div
        class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            :title="t('ui.analytics.title')"
            :description="t('ui.analytics.timezone', { zone: timezone })"
            :icon="ChartColumn"
        >
            <template v-if="!locked" #actions>
                <div
                    role="radiogroup"
                    :aria-label="t('ui.analytics.period')"
                    class="inline-flex h-11 items-center gap-0.5 rounded-control border bg-surface-2 p-1"
                >
                    <button
                        v-for="value in [7, 30, 90] as const"
                        :key="value"
                        type="button"
                        role="radio"
                        :aria-checked="period === value"
                        :class="[
                            'inline-flex h-full items-center rounded-[10px] px-3 text-sm font-medium tabular-nums',
                            period === value
                                ? 'bg-surface shadow-clay-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        data-test="analytics-period"
                        :data-period="value"
                        @click="period = value"
                    >
                        {{ tc('ui.analytics.days', value) }}
                    </button>
                </div>
                <FilterSelect
                    v-if="departments.length"
                    v-model="department"
                    :label="t('ui.common.department')"
                    :all-label="t('ui.common.all_departments')"
                    :options="
                        departments.map((d) => ({ value: d.id, label: d.name }))
                    "
                    test-id="analytics-department"
                />
            </template>
        </PageHeader>

        <section
            v-if="locked"
            class="grid items-center gap-6 rounded-card border bg-surface p-6 shadow-clay md:grid-cols-[1fr_auto]"
            data-test="analytics-upgrade"
        >
            <div class="space-y-3">
                <h2 class="text-display-m">
                    {{ t('ui.analytics.locked_title') }}
                </h2>
                <p class="text-pretty text-muted-foreground">
                    {{ t('ui.analytics.locked_body') }}
                </p>
                <ul class="grid gap-1.5 text-sm">
                    <li
                        v-for="item in ['useful', 'gaps', 'ops']"
                        :key="item"
                        class="flex gap-2"
                    >
                        <ChartColumn
                            class="mt-0.5 size-4 shrink-0 text-primary-strong"
                            aria-hidden="true"
                        />
                        {{ t(`ui.analytics.locked_points.${item}`) }}
                    </li>
                </ul>
                <Button v-if="can('billing.view')" @click="upgrade.show(null)">
                    <CircleArrowUp class="size-4" aria-hidden="true" />
                    {{ t('ui.analytics.locked_cta') }}
                </Button>
            </div>
            <svg
                viewBox="0 0 240 160"
                class="mx-auto h-40 w-60"
                aria-hidden="true"
            >
                <rect
                    x="4"
                    y="4"
                    width="232"
                    height="152"
                    rx="20"
                    class="fill-surface-2"
                />
                <g class="fill-primary-soft">
                    <rect x="28" y="90" width="22" height="46" rx="4" />
                    <rect x="62" y="64" width="22" height="72" rx="4" />
                    <rect x="96" y="76" width="22" height="60" rx="4" />
                    <rect x="130" y="44" width="22" height="92" rx="4" />
                    <rect x="164" y="56" width="22" height="80" rx="4" />
                </g>
                <g class="fill-mint-soft">
                    <rect x="28" y="78" width="22" height="10" rx="4" />
                    <rect x="130" y="30" width="22" height="12" rx="4" />
                </g>
                <circle cx="206" cy="32" r="14" class="fill-sun-soft" />
                <line
                    x1="24"
                    y1="140"
                    x2="216"
                    y2="140"
                    class="stroke-border"
                    stroke-width="2"
                />
            </svg>
        </section>

        <template v-else>
            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <div
                    v-for="kpi in kpis"
                    :key="kpi.key"
                    class="space-y-1 rounded-card border bg-surface p-4 shadow-clay-sm"
                    data-test="analytics-kpi"
                    :data-kpi="kpi.key"
                >
                    <p class="text-caption">{{ kpi.label }}</p>
                    <p class="text-kpi">{{ kpi.value }}</p>
                    <p
                        v-if="'hint' in kpi && kpi.hint"
                        class="text-xs text-muted-foreground"
                    >
                        {{ kpi.hint }}
                    </p>
                </div>
            </div>

            <ChartCard
                :title="t('ui.analytics.questions_title')"
                :caption="t('ui.analytics.questions_caption')"
                :empty="seriesEmpty"
                :empty-text="t('ui.analytics.empty_period')"
                height="18rem"
                test-id="analytics-questions-chart"
                toggle-test-id="analytics-table-toggle"
                :columns="[
                    { key: 'day', label: t('ui.charts.day') },
                    {
                        key: 'answered',
                        label: t('ui.charts.answered'),
                        numeric: true,
                    },
                    {
                        key: 'refused',
                        label: t('ui.charts.refused'),
                        numeric: true,
                    },
                    {
                        key: 'escalated',
                        label: t('ui.charts.escalated'),
                        numeric: true,
                    },
                    {
                        key: 'total',
                        label: t('ui.charts.total'),
                        numeric: true,
                    },
                ]"
                :rows="
                    series.map((d, i) => ({
                        day: dayLabels[i] ?? d.date,
                        answered: d.answered,
                        refused: d.refused,
                        escalated: d.escalated,
                        total: d.total,
                    }))
                "
                :build="
                    (theme) =>
                        stackedBars(theme, dayLabels, [
                            {
                                name: t('ui.charts.answered'),
                                data: series.map((d) => d.answered),
                                color: 0,
                            },
                            {
                                name: t('ui.charts.refused'),
                                data: series.map((d) => d.refused),
                                color: 1,
                            },
                            {
                                name: t('ui.charts.escalated'),
                                data: series.map((d) => d.escalated),
                                color: 2,
                            },
                        ])
                "
            />

            <div class="grid gap-6 lg:grid-cols-2">
                <SectionCard
                    :title="t('ui.analytics.top_unanswered')"
                    :icon="FileQuestion"
                    data-test="analytics-top-unanswered"
                >
                    <ol v-if="topUnanswered.length" class="divide-y">
                        <li
                            v-for="(item, index) in topUnanswered"
                            :key="item.representative"
                            class="flex items-start gap-3 py-2.5"
                        >
                            <span
                                class="font-mono text-xs text-muted-foreground tabular-nums"
                                >{{ index + 1 }}</span
                            >
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-pretty">
                                    {{ item.representative }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        tc(
                                            'ui.unanswered.questions',
                                            item.count,
                                        )
                                    }}
                                    · {{ fmt.relative(item.last_asked_at) }}
                                </p>
                            </div>
                        </li>
                    </ol>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('ui.analytics.empty_period') }}
                    </p>
                    <template
                        v-if="topUnanswered.length && can('questions.answer')"
                        #footer
                    >
                        <Button size="sm" variant="secondary" as-child>
                            <Link :href="endpoints.unanswered.index()">{{
                                t('ui.analytics.open_unanswered')
                            }}</Link>
                        </Button>
                    </template>
                </SectionCard>

                <SectionCard
                    :title="t('ui.analytics.never_cited')"
                    :description="t('ui.analytics.never_cited_hint')"
                    :icon="FileX"
                    data-test="analytics-never-cited"
                >
                    <ul v-if="neverCited.length" class="divide-y">
                        <li
                            v-for="doc in neverCited"
                            :key="doc.document_id"
                            class="flex items-center gap-3 py-2.5"
                        >
                            <Link
                                :href="
                                    endpoints.knowledge.show(doc.document_id)
                                "
                                class="min-w-0 flex-1 truncate text-sm font-medium hover:underline"
                                >{{ doc.title }}</Link
                            >
                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                                >{{ fmt.date(doc.approved_at) }}</span
                            >
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('ui.analytics.all_cited') }}
                    </p>
                </SectionCard>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <ChartCard
                    :title="t('ui.analytics.top_documents')"
                    :empty="!topDocuments.length"
                    :empty-text="t('ui.analytics.empty_period')"
                    :height="`${Math.max(12, topDocuments.length * 2.6)}rem`"
                    test-id="analytics-top-documents"
                    :columns="[
                        { key: 'title', label: t('ui.analytics.document') },
                        {
                            key: 'citations',
                            label: t('ui.analytics.citations'),
                            numeric: true,
                        },
                    ]"
                    :rows="
                        topDocuments.map((d) => ({
                            title: d.title,
                            citations: d.citations,
                        }))
                    "
                    :build="
                        (theme) =>
                            horizontalBars(
                                theme,
                                topDocuments.map((d) => d.title),
                                topDocuments.map((d) => d.citations),
                                t('ui.analytics.citations'),
                            )
                    "
                />

                <ChartCard
                    :title="t('ui.analytics.incidents_by_category')"
                    :caption="
                        incidents.median_resolution_hours !== null
                            ? t('ui.analytics.median_resolution', {
                                  value: tc(
                                      'ui.units.hours',
                                      Math.round(
                                          incidents.median_resolution_hours,
                                      ),
                                  ),
                              })
                            : null
                    "
                    :empty="categoriesEmpty"
                    :empty-text="t('ui.analytics.empty_incidents')"
                    test-id="analytics-incidents-category"
                    :columns="[
                        {
                            key: 'category',
                            label: t('ui.incidents.fields.category'),
                        },
                        {
                            key: 'count',
                            label: t('ui.analytics.count'),
                            numeric: true,
                        },
                    ]"
                    :rows="
                        INCIDENT_CATEGORIES.map((c, i) => ({
                            category: t(`incidents.category.${c}`),
                            count: categoryValues[i],
                        }))
                    "
                    :build="
                        (theme) =>
                            horizontalBars(
                                theme,
                                INCIDENT_CATEGORIES.map((c) =>
                                    t(`incidents.category.${c}`),
                                ),
                                categoryValues,
                                t('ui.analytics.count'),
                            )
                    "
                />
            </div>

            <ChartCard
                :title="t('ui.analytics.incidents_weekly')"
                :empty="weeksEmpty"
                :empty-text="t('ui.analytics.empty_incidents')"
                test-id="analytics-incidents-weekly"
                :columns="[
                    { key: 'week', label: t('ui.analytics.week') },
                    {
                        key: 'opened',
                        label: t('ui.analytics.opened'),
                        numeric: true,
                    },
                    {
                        key: 'resolved',
                        label: t('ui.analytics.resolved'),
                        numeric: true,
                    },
                ]"
                :rows="
                    incidents.by_week.map((w, i) => ({
                        week: weekLabels[i] ?? w.week_start,
                        opened: w.opened,
                        resolved: w.resolved,
                    }))
                "
                :build="
                    (theme) =>
                        groupedBars(theme, weekLabels, [
                            {
                                name: t('ui.analytics.opened'),
                                data: incidents.by_week.map((w) => w.opened),
                                color: 0,
                            },
                            {
                                name: t('ui.analytics.resolved'),
                                data: incidents.by_week.map((w) => w.resolved),
                                color: 1,
                            },
                        ])
                "
            />

            <SectionCard
                :title="t('ui.analytics.tasks_title')"
                :icon="ListChecks"
                data-test="analytics-tasks"
            >
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div
                        v-for="tile in taskTiles"
                        :key="tile.key"
                        class="rounded-2xl bg-surface-2 p-3"
                    >
                        <p class="text-caption">{{ tile.label }}</p>
                        <p
                            :class="[
                                'text-kpi',
                                'alert' in tile && tile.alert
                                    ? 'text-coral-strong'
                                    : '',
                            ]"
                        >
                            <AlarmClock
                                v-if="'alert' in tile && tile.alert"
                                class="mr-1 inline size-5"
                                aria-hidden="true"
                            />
                            {{ tile.value }}
                        </p>
                    </div>
                </div>
            </SectionCard>
        </template>
    </div>
</template>

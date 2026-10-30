<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlarmClock,
    Bot,
    CalendarClock,
    CheckCheck,
    CircleArrowUp,
    CircleCheck,
    CircleDashed,
    KeyRound,
    ListChecks,
    MessageCircleQuestionMark,
    MessagesSquare,
    CirclePause,
    Plus,
    Send,
    Siren,
    Sparkles,
    TrendingDown,
    TrendingUp,
    UserPlus,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import SeverityBadge from '@/components/chat/SeverityBadge.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import AnswerDialog from '@/components/opsbot/AnswerDialog.vue';
import Callout from '@/components/opsbot/Callout.vue';
import ChartCard from '@/components/opsbot/ChartCard.vue';
import DeskItemRow from '@/components/opsbot/DeskItemRow.vue';
import DueChip from '@/components/opsbot/DueChip.vue';
import BotStatusPill from '@/components/shell/BotStatusPill.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useCan } from '@/composables/useCan';
import { useDeskActions } from '@/composables/useDeskActions';
import { fetchDeskFeed, suppressDeskItems } from '@/composables/useDeskFeed';
import { useFormat } from '@/composables/useFormat';
import { useUpgrade } from '@/composables/useUpgrade';
import { endpoints, withQuery } from '@/lib/endpoints';
import { t, tc, tOr } from '@/lib/i18n';
import {
    bucketToday,
    deskTarget,
    greetingKey,
    hourIn,
    mergeDesk,
    percentDelta,
    SEVERITIES,
} from '@/lib/opsbot';
import { stackedBars } from '@/lib/opsbot-charts';
import type { DashboardProps, DeskItem, Task } from '@/types/opsbot';

const props = defineProps<DashboardProps>();

const page = usePage();
const fmt = useFormat();
const { can } = useCan();
const upgrade = useUpgrade();

const manager = computed(() => can('incidents.manage'));
const workspace = computed(() => page.props.workspace);
const firstName = computed(
    () => page.props.auth.user?.name.split(' ')[0] ?? '',
);

const greeting = computed(() =>
    t(
        `ui.dashboard.greeting.${greetingKey(hourIn(workspace.value?.timezone))}`,
        {
            name: firstName.value,
        },
    ),
);

const todayLabel = computed(() => fmt.value.date(new Date(), 'full'));

const extra = ref<DeskItem[]>([]);
const loadingMore = ref(false);
const moreDone = ref(false);

const feedItems = computed(() => {
    const known = new Set(props.today.map((item) => item.id));

    return [
        ...props.today,
        ...extra.value.filter((item) => !known.has(item.id)),
    ];
});

const groups = computed(() => bucketToday(feedItems.value));

let release: (() => void) | null = null;

onMounted(() => {
    release = suppressDeskItems(props.today.map((item) => item.id));
});

onBeforeUnmount(() => release?.());

async function loadMore(): Promise<void> {
    loadingMore.value = true;

    try {
        const result = await fetchDeskFeed(null);
        const before = feedItems.value.length;
        extra.value = mergeDesk(extra.value, result.items, 60).reverse();
        moreDone.value = feedItems.value.length === before;
    } catch {
        moreDone.value = false;
    } finally {
        loadingMore.value = false;
    }
}

const { pending, answering, run } = useDeskActions(() => {
    router.reload({
        only: ['today', 'kpis', 'incidentsBySeverity', 'myTasks', 'unanswered'],
    });
});

const answerOpen = computed({
    get: () => answering.value !== null,
    set: (value: boolean) => {
        if (!value) {
            answering.value = null;
        }
    },
});

const pulse = computed(() => [
    {
        key: 'open_incidents',
        value: props.kpis.open_incidents,
        label: tc('ui.dashboard.pulse.incidents', props.kpis.open_incidents),
        href: withQuery(endpoints.incidents.index().url, {}),
        icon: Siren,
        tone: 'text-coral-strong',
        show: can('incidents.view_own'),
    },
    {
        key: 'overdue_tasks',
        value: props.kpis.overdue_tasks,
        label: tc('ui.dashboard.pulse.tasks', props.kpis.overdue_tasks),
        href: withQuery(endpoints.tasks.index().url, { due: 'overdue' }),
        icon: AlarmClock,
        tone: 'text-coral-strong',
        show: can('tasks.view_own'),
    },
    {
        key: 'unanswered_open',
        value: props.kpis.unanswered_open,
        label: tc('ui.dashboard.pulse.questions', props.kpis.unanswered_open),
        href: endpoints.unanswered.index().url,
        icon: MessageCircleQuestionMark,
        tone: 'text-sun-strong',
        show: can('questions.answer'),
    },
]);

const answeredRate = computed(() =>
    props.kpis.answered_rate_7d === null
        ? null
        : fmt.value.percent(props.kpis.answered_rate_7d),
);

const questionsDelta = computed(() =>
    percentDelta(props.kpis.questions_7d.delta),
);

const subscriptionState = computed(
    () => workspace.value?.subscription_state ?? 'ok',
);
const botProblem = computed(() => {
    const status = props.bot?.status ?? workspace.value?.bot?.status ?? null;

    return status === 'revoked' || status === 'invalid_token' ? status : null;
});

const chartLabels = computed(() =>
    props.questionsSeries.map((day) =>
        fmt.value.dayLabel(`${day.date}T12:00:00`),
    ),
);
const chartEmpty = computed(() =>
    props.questionsSeries.every(
        (day) => day.answered + day.refused + day.escalated === 0,
    ),
);
const chartRows = computed(() =>
    props.questionsSeries.map((day, index) => ({
        day: chartLabels.value[index] ?? day.date,
        answered: day.answered,
        refused: day.refused,
        escalated: day.escalated,
    })),
);

const stepsLeft = computed(
    () =>
        props.onboarding?.steps.filter(
            (step) => step.state === 'todo' || step.state === 'current',
        ) ?? [],
);

const doneTask = ref<number | null>(null);

function completeTask(task: Task): void {
    doneTask.value = task.id;
    router.patch(
        endpoints.tasks.update(task.id).url,
        { status: 'done' },
        {
            preserveScroll: true,
            only: ['myTasks', 'today', 'kpis'],
            onFinish: () => (doneTask.value = null),
        },
    );
}

const testing = ref(false);

function sendTest(): void {
    testing.value = true;
    router.post(
        endpoints.bot.testMessage().url,
        {},
        {
            preserveScroll: true,
            onFinish: () => (testing.value = false),
        },
    );
}

function stepHref(key: string): string {
    switch (key) {
        case 'bot':
            return endpoints.bot.index().url;
        case 'knowledge':
            return endpoints.knowledge.index().url;
        case 'team':
            return endpoints.employees.index().url;
        default:
            return endpoints.onboarding.setup().url;
    }
}
</script>

<template>
    <Head :title="t('ui.nav.dashboard')" />

    <div
        class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 py-6 md:px-6"
    >
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="space-y-1">
                <h1 class="text-display-m">{{ greeting }}</h1>
                <p class="text-caption first-letter:uppercase">
                    {{ todayLabel }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="can('incidents.create')"
                    variant="secondary"
                    as-child
                >
                    <Link
                        :href="
                            withQuery(endpoints.incidents.index().url, {
                                create: 1,
                            })
                        "
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        {{ t('ui.dashboard.create_incident') }}
                    </Link>
                </Button>
                <Button v-if="can('assistant.use')" as-child>
                    <Link :href="endpoints.assistant.index()">
                        <MessagesSquare class="size-4" aria-hidden="true" />
                        {{ t('ui.dashboard.ask') }}
                    </Link>
                </Button>
            </div>
        </header>

        <p
            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.9375rem] text-pretty"
            data-test="dashboard-pulse"
        >
            <span class="font-semibold">{{
                t('ui.dashboard.pulse.label')
            }}</span>
            <template
                v-for="(item, index) in pulse.filter((entry) => entry.show)"
                :key="item.key"
            >
                <Link
                    :href="item.href"
                    :data-kpi="item.key"
                    :class="[
                        'inline-flex items-center gap-1 rounded-lg tabular-nums underline-offset-4 hover:underline',
                        item.value > 0
                            ? `font-semibold ${item.tone}`
                            : 'text-muted-foreground',
                    ]"
                >
                    <component
                        :is="item.icon"
                        v-if="item.value > 0"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ item.label }}</Link
                ><span
                    v-if="
                        index < pulse.filter((entry) => entry.show).length - 1
                    "
                    class="-ml-2"
                    aria-hidden="true"
                    >,</span
                >
            </template>
            <template v-if="manager">
                <span aria-hidden="true">·</span>
                <span data-kpi="answered_rate_7d" class="tabular-nums">
                    {{
                        answeredRate
                            ? t('ui.dashboard.pulse.rate', {
                                  percent: answeredRate,
                              })
                            : t('ui.dashboard.pulse.no_rate')
                    }}
                </span>
                <span
                    v-if="questionsDelta !== null"
                    data-kpi="questions_7d"
                    :class="[
                        'inline-flex items-center gap-1 rounded-chip px-2 text-xs font-semibold tabular-nums',
                        questionsDelta >= 0
                            ? 'bg-success-soft text-success'
                            : 'bg-coral-soft text-coral-strong',
                    ]"
                >
                    <component
                        :is="questionsDelta >= 0 ? TrendingUp : TrendingDown"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{
                        t('ui.dashboard.pulse.delta', {
                            delta: `${questionsDelta > 0 ? '+' : ''}${questionsDelta}%`,
                            count: fmt.number(kpis.questions_7d.value),
                        })
                    }}
                </span>
            </template>
        </p>

        <div class="flex flex-col gap-3">
            <Callout
                v-if="subscriptionState === 'paused'"
                tone="coral"
                :icon="CirclePause"
                :title="t('ui.dashboard.banners.paused_title')"
                data-test="dashboard-banner"
                data-banner="paused"
            >
                {{ t('ui.dashboard.banners.paused_body') }}
                <template v-if="can('billing.view')" #actions>
                    <Button size="sm" as-child>
                        <Link :href="endpoints.settings.billing.show()">{{
                            t('ui.dashboard.banners.pay')
                        }}</Link>
                    </Button>
                </template>
            </Callout>
            <Callout
                v-else-if="subscriptionState === 'grace'"
                tone="coral"
                :icon="AlarmClock"
                :title="t('ui.dashboard.banners.grace_title')"
                data-test="dashboard-banner"
                data-banner="grace"
            >
                {{ t('ui.dashboard.banners.grace_body') }}
                <template v-if="can('billing.view')" #actions>
                    <Button size="sm" variant="secondary" as-child>
                        <Link :href="endpoints.settings.billing.show()">{{
                            t('ui.dashboard.banners.update_payment')
                        }}</Link>
                    </Button>
                </template>
            </Callout>
            <Callout
                v-if="botProblem"
                tone="coral"
                :icon="KeyRound"
                :title="t(`ui.dashboard.banners.bot_${botProblem}`)"
                data-test="dashboard-banner"
                data-banner="bot"
            >
                {{ t('ui.dashboard.banners.bot_body') }}
                <template v-if="can('bot.manage')" #actions>
                    <Button size="sm" variant="secondary" as-child>
                        <Link :href="endpoints.bot.index()">{{
                            t('ui.dashboard.banners.bot_fix')
                        }}</Link>
                    </Button>
                </template>
            </Callout>
            <Callout
                v-for="warning in limitsWarning"
                :key="warning.key"
                tone="sun"
                :icon="CircleArrowUp"
                data-test="dashboard-banner"
                :data-banner="`limit-${warning.key}`"
            >
                {{
                    t('ui.dashboard.banners.limit', {
                        limit: tOr(
                            `ui.billing.usage.${warning.key}`,
                            warning.key,
                        ),
                        used: fmt.number(warning.used),
                        total: fmt.number(warning.limit),
                    })
                }}
                <template #actions>
                    <Button
                        size="sm"
                        variant="secondary"
                        @click="upgrade.show(warning.key)"
                    >
                        {{ t('ui.billing.raise_limit') }}
                    </Button>
                </template>
            </Callout>
        </div>

        <div class="grid gap-6 lg:grid-cols-12">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-8">
                <SectionCard
                    v-if="onboarding && stepsLeft.length"
                    :title="t('ui.dashboard.setup_title')"
                    :description="
                        tc('ui.dashboard.setup_remaining', stepsLeft.length)
                    "
                    :icon="Sparkles"
                    data-test="dashboard-onboarding"
                >
                    <ol class="grid gap-2 sm:grid-cols-2">
                        <li
                            v-for="step in onboarding.steps"
                            :key="step.key"
                            class="flex items-center gap-3 rounded-2xl bg-surface-2 px-3 py-2.5"
                        >
                            <CircleCheck
                                v-if="step.state === 'done'"
                                class="size-5 text-success"
                                aria-hidden="true"
                            />
                            <CircleDashed
                                v-else
                                class="size-5 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">{{
                                    t(`ui.setup.steps.${step.key}`)
                                }}</span>
                                <span class="text-xs text-muted-foreground">{{
                                    t(`ui.setup.state.${step.state}`)
                                }}</span>
                            </span>
                            <Link
                                v-if="step.state !== 'done'"
                                :href="stepHref(step.key)"
                                class="text-sm font-semibold text-primary-strong underline-offset-4 hover:underline"
                                >{{ t('ui.dashboard.setup_open') }}</Link
                            >
                        </li>
                    </ol>
                    <template #footer>
                        <Button size="sm" as-child>
                            <Link :href="endpoints.onboarding.setup()">{{
                                t('ui.dashboard.setup_continue')
                            }}</Link>
                        </Button>
                    </template>
                </SectionCard>

                <section
                    :aria-busy="loadingMore"
                    :aria-label="t('ui.dashboard.feed_title')"
                    class="flex flex-col gap-5"
                    data-test="dashboard-feed"
                >
                    <EmptyState
                        v-if="!feedItems.length && !reviewDue.count"
                        :icon="CheckCheck"
                        scene="chat"
                        tone="ai"
                        :title="t('ui.dashboard.empty_title')"
                        :description="t('ui.dashboard.empty_body')"
                        class="rounded-card border bg-surface shadow-clay-sm"
                    >
                        <Button
                            v-if="
                                can('employees.manage') &&
                                (bot?.linked_count ?? 0) === 0
                            "
                            variant="secondary"
                            as-child
                        >
                            <Link :href="endpoints.employees.index()">
                                <UserPlus class="size-4" aria-hidden="true" />
                                {{ t('ui.dashboard.invite') }}
                            </Link>
                        </Button>
                    </EmptyState>

                    <div
                        v-if="groups.attention.length"
                        class="flex flex-col gap-3"
                    >
                        <h2 class="text-caption tracking-wide uppercase">
                            {{ t('ui.dashboard.groups.attention') }}
                        </h2>
                        <DeskItemRow
                            v-for="item in groups.attention"
                            :key="item.id"
                            :item="item"
                            :pending="pending"
                            @action="run"
                        />
                    </div>

                    <div v-if="groups.today.length" class="flex flex-col gap-3">
                        <h2 class="text-caption tracking-wide uppercase">
                            {{ t('ui.dashboard.groups.today') }}
                        </h2>
                        <DeskItemRow
                            v-for="item in groups.today"
                            :key="item.id"
                            :item="item"
                            :pending="pending"
                            @action="run"
                        />
                    </div>

                    <div v-if="reviewDue.count" class="flex flex-col gap-3">
                        <h2 class="text-caption tracking-wide uppercase">
                            {{ t('ui.dashboard.groups.documents') }}
                        </h2>
                        <article
                            v-for="doc in reviewDue.top"
                            :key="doc.id"
                            :aria-label="doc.title"
                            class="flex items-center gap-3 rounded-card border bg-surface p-3.5 shadow-clay-sm"
                            data-test="dashboard-feed-item"
                            data-kind="review"
                            :data-id="`review:${doc.id}`"
                        >
                            <span
                                class="inline-flex size-10 shrink-0 items-center justify-center rounded-2xl bg-sun-soft text-sun-strong"
                                aria-hidden="true"
                            >
                                <CalendarClock class="size-5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <Link
                                    :href="endpoints.knowledge.show(doc.id)"
                                    class="block truncate font-semibold hover:underline"
                                    >{{ doc.title }}</Link
                                >
                                <p class="text-sm text-muted-foreground">
                                    {{
                                        t('ui.dashboard.review_due', {
                                            date: fmt.date(
                                                `${doc.review_due_at}T12:00:00`,
                                            ),
                                        })
                                    }}
                                </p>
                            </div>
                            <Button size="sm" variant="secondary" as-child>
                                <Link
                                    :href="endpoints.knowledge.show(doc.id)"
                                    data-test="dashboard-feed-action"
                                    data-action="open"
                                    >{{ t('ui.dashboard.review') }}</Link
                                >
                            </Button>
                        </article>
                        <Link
                            v-if="reviewDue.count > reviewDue.top.length"
                            :href="
                                withQuery(endpoints.knowledge.index().url, {
                                    review: 'overdue',
                                })
                            "
                            class="text-sm font-semibold text-primary-strong underline-offset-4 hover:underline"
                            >{{
                                tc('ui.dashboard.review_more', reviewDue.count)
                            }}</Link
                        >
                    </div>

                    <Button
                        v-if="feedItems.length && !moreDone"
                        variant="secondary"
                        class="self-center"
                        :disabled="loadingMore"
                        data-test="dashboard-feed-more"
                        @click="loadMore"
                    >
                        <Spinner v-if="loadingMore" />
                        {{ t('ui.dashboard.feed_more') }}
                    </Button>
                </section>
            </div>

            <aside class="flex min-w-0 flex-col gap-6 lg:col-span-4">
                <SectionCard
                    v-if="can('bot.manage')"
                    :title="t('ui.dashboard.bot_title')"
                    :icon="Bot"
                    data-test="dashboard-bot-card"
                    class="max-lg:order-first"
                >
                    <div v-if="bot" class="space-y-3">
                        <BotStatusPill
                            :username="bot.username"
                            :state="bot.status"
                            :href="endpoints.bot.index()"
                        />
                        <dl class="grid grid-cols-2 gap-2 text-sm">
                            <div>
                                <dt class="text-caption">
                                    {{ t('ui.bot_page.linked') }}
                                </dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ fmt.number(bot.linked_count) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-caption">
                                    {{ t('ui.bot_page.last_seen') }}
                                </dt>
                                <dd class="font-semibold">
                                    {{
                                        bot.last_seen_at
                                            ? fmt.relative(bot.last_seen_at)
                                            : '—'
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <Button
                            v-if="bot.status === 'active'"
                            size="sm"
                            variant="secondary"
                            :disabled="testing"
                            @click="sendTest"
                        >
                            <Spinner v-if="testing" />
                            <Send v-else class="size-4" aria-hidden="true" />
                            {{ t('ui.bot_page.test_send') }}
                        </Button>
                    </div>
                    <div v-else class="space-y-3 text-sm">
                        <p class="text-muted-foreground">
                            {{ t('ui.dashboard.bot_missing') }}
                        </p>
                        <Button size="sm" as-child>
                            <Link :href="endpoints.bot.index()">{{
                                t('ui.dashboard.bot_connect')
                            }}</Link>
                        </Button>
                    </div>
                </SectionCard>

                <SectionCard
                    v-if="manager"
                    :title="t('ui.dashboard.severity_title')"
                    :icon="Siren"
                    data-test="dashboard-severity"
                >
                    <ul class="divide-y">
                        <li v-for="level in SEVERITIES" :key="level">
                            <Link
                                :href="
                                    withQuery(endpoints.incidents.index().url, {
                                        severity: level,
                                    })
                                "
                                class="flex items-center gap-3 py-2.5 hover:bg-surface-2"
                            >
                                <SeverityBadge :severity="level" />
                                <span
                                    class="ml-auto text-sm font-semibold tabular-nums"
                                >
                                    {{
                                        tc(
                                            'ui.dashboard.open_count',
                                            incidentsBySeverity[level]?.open ??
                                                0,
                                        )
                                    }}
                                </span>
                                <span
                                    v-if="
                                        (incidentsBySeverity[level]
                                            ?.unassigned ?? 0) > 0
                                    "
                                    class="text-xs font-semibold text-sun-strong"
                                    >{{
                                        t('ui.dashboard.unassigned', {
                                            count:
                                                incidentsBySeverity[level]
                                                    ?.unassigned ?? 0,
                                        })
                                    }}</span
                                >
                            </Link>
                        </li>
                    </ul>
                </SectionCard>

                <ChartCard
                    v-if="manager"
                    :title="t('ui.dashboard.chart.title')"
                    :caption="t('ui.dashboard.chart.caption')"
                    :empty="chartEmpty"
                    :empty-text="t('ui.dashboard.chart.empty')"
                    height="14rem"
                    test-id="dashboard-questions-chart"
                    toggle-test-id="dashboard-chart-table-toggle"
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
                    ]"
                    :rows="chartRows"
                    :build="
                        (theme) =>
                            stackedBars(theme, chartLabels, [
                                {
                                    name: t('ui.charts.answered'),
                                    data: questionsSeries.map(
                                        (d) => d.answered,
                                    ),
                                    color: 0,
                                },
                                {
                                    name: t('ui.charts.refused'),
                                    data: questionsSeries.map((d) => d.refused),
                                    color: 1,
                                },
                                {
                                    name: t('ui.charts.escalated'),
                                    data: questionsSeries.map(
                                        (d) => d.escalated,
                                    ),
                                    color: 2,
                                },
                            ])
                    "
                />

                <SectionCard
                    :title="t('ui.dashboard.my_tasks')"
                    :icon="ListChecks"
                    data-test="dashboard-my-tasks"
                >
                    <ul v-if="myTasks.length" class="divide-y">
                        <li
                            v-for="task in myTasks"
                            :key="task.id"
                            class="flex items-start gap-3 py-2.5"
                        >
                            <div class="min-w-0 flex-1 space-y-1">
                                <Link
                                    :href="
                                        withQuery(endpoints.tasks.index().url, {
                                            focus: task.id,
                                        })
                                    "
                                    class="block text-sm font-semibold hover:underline"
                                >
                                    <span
                                        class="font-mono text-xs text-muted-foreground"
                                        >{{ task.display_number }}</span
                                    >
                                    {{ task.title }}
                                </Link>
                                <DueChip :due-at="task.due_at" />
                            </div>
                            <Button
                                size="sm"
                                variant="secondary"
                                :disabled="doneTask === task.id"
                                :aria-label="
                                    t('ui.desk.actions.done_named', {
                                        title: task.title,
                                    })
                                "
                                data-test="dashboard-task-done"
                                @click="completeTask(task)"
                            >
                                <Spinner v-if="doneTask === task.id" />
                                <CheckCheck
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                <span class="max-sm:sr-only">{{
                                    t('ui.desk.actions.done')
                                }}</span>
                            </Button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('ui.dashboard.no_tasks') }}
                    </p>
                </SectionCard>
            </aside>
        </div>
    </div>

    <AnswerDialog
        v-model:open="answerOpen"
        :question-id="answering ? (deskTarget(answering)?.id ?? null) : null"
        :title="answering?.title ?? ''"
        :question="answering?.excerpt ?? null"
    />
</template>

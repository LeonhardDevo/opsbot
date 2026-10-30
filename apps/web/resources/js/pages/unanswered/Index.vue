<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    FilterX,
    Inbox,
    MessageCircleQuestionMark,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref } from 'vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import QuestionCard from '@/components/opsbot/QuestionCard.vue';
import { Button } from '@/components/ui/button';
import { useCan } from '@/composables/useCan';
import { useFilters } from '@/composables/useFilters';
import { t, tc } from '@/lib/i18n';
import { endpoints } from '@/lib/endpoints';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/models';
import type {
    DepartmentRef,
    QuestionFilters,
    QuestionStatus,
    UnansweredQuestion,
    UserRef,
} from '@/types/opsbot';

const props = defineProps<{
    questions: Paginated<UnansweredQuestion>;
    groups: Array<{
        representative: string;
        count: number;
        question_ids: number[];
    }>;
    filters: QuestionFilters;
    counts: Record<QuestionStatus, number> & { overdue_24h: number };
    departments: DepartmentRef[];
    assignees: UserRef[];
}>();

const { can } = useCan();

const { filters } = useFilters(
    () => endpoints.unanswered.index().url,
    {
        status: props.filters.status ?? 'open',
        department_id: props.filters.department_id ?? null,
        assignee: props.filters.assignee ?? null,
    },
    ['questions', 'groups', 'filters', 'counts'],
);

const tabs = computed(() => [
    {
        status: 'open',
        label: t('ui.unanswered.tabs.open'),
        count: props.counts.open,
    },
    {
        status: 'answered',
        label: t('ui.unanswered.tabs.answered'),
        count: props.counts.answered,
    },
    {
        status: 'converted_to_doc',
        label: t('ui.unanswered.tabs.converted'),
        count: props.counts.converted_to_doc,
    },
    {
        status: 'dismissed',
        label: t('ui.unanswered.tabs.dismissed'),
        count: props.counts.dismissed,
    },
    { status: 'all', label: t('ui.unanswered.tabs.all'), count: null },
]);

const search = ref('');
const groupFilter = ref<{ representative: string; ids: number[] } | null>(null);
const focusId = ref<number | null>(null);

const visible = computed(() => {
    const term = search.value.trim().toLocaleLowerCase();

    return props.questions.data.filter((question) => {
        if (groupFilter.value && !groupFilter.value.ids.includes(question.id)) {
            return false;
        }

        if (!term) {
            return true;
        }

        return (
            question.question.toLocaleLowerCase().includes(term) ||
            question.employee.name.toLocaleLowerCase().includes(term) ||
            question.display_number.toLocaleLowerCase().includes(term)
        );
    });
});

const maxGroup = computed(() =>
    Math.max(1, ...props.groups.map((group) => group.count)),
);

function pickGroup(group: {
    representative: string;
    question_ids: number[];
}): void {
    groupFilter.value =
        groupFilter.value?.representative === group.representative
            ? null
            : { representative: group.representative, ids: group.question_ids };
    filters.status = 'open';
}

onMounted(async () => {
    const focus = Number(
        new URLSearchParams(window.location.search).get('focus'),
    );

    if (!focus) {
        return;
    }

    focusId.value = focus;
    await nextTick();
    document
        .getElementById(`question-${focus}`)
        ?.scrollIntoView({ block: 'center' });
    setTimeout(() => (focusId.value = null), 2000);
});
</script>

<template>
    <Head :title="t('ui.unanswered.title')" />

    <div
        class="mx-auto flex w-full max-w-[1280px] flex-col gap-5 px-4 py-6 md:px-6"
    >
        <PageHeader
            :title="t('ui.unanswered.title')"
            :description="t('ui.unanswered.subtitle')"
            :icon="MessageCircleQuestionMark"
        />

        <div
            role="tablist"
            :aria-label="t('ui.unanswered.tabs.label')"
            class="flex scrollbar-none gap-1 overflow-x-auto rounded-control border bg-surface-2 p-1"
        >
            <button
                v-for="tab in tabs"
                :key="tab.status"
                type="button"
                role="tab"
                :aria-selected="filters.status === tab.status"
                :class="
                    cn(
                        'inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-[10px] px-3 text-sm font-medium whitespace-nowrap',
                        filters.status === tab.status
                            ? 'bg-surface shadow-clay-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )
                "
                data-test="unanswered-tab"
                :data-status="tab.status"
                @click="filters.status = tab.status as QuestionStatus | 'all'"
            >
                {{ tab.label }}
                <span
                    v-if="tab.count !== null"
                    class="rounded-chip bg-surface-2 px-1.5 font-mono text-xs"
                    >{{ tab.count }}</span
                >
                <span
                    v-if="tab.status === 'open' && counts.overdue_24h > 0"
                    class="rounded-chip bg-coral-soft px-1.5 text-xs font-semibold text-coral-strong"
                    >{{
                        t('ui.unanswered.older_24h', {
                            count: counts.overdue_24h,
                        })
                    }}</span
                >
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <SearchInput
                v-model="search"
                :placeholder="t('ui.unanswered.search')"
                class="w-full sm:w-64"
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
            <button
                type="button"
                :aria-pressed="filters.assignee === 'me'"
                :class="
                    cn(
                        'press inline-flex min-h-11 items-center gap-1.5 rounded-chip border px-3.5 text-sm font-medium',
                        filters.assignee === 'me'
                            ? 'border-primary bg-primary-soft text-primary-strong'
                            : 'bg-surface hover:bg-surface-2',
                    )
                "
                @click="
                    filters.assignee = filters.assignee === 'me' ? null : 'me'
                "
            >
                <UserRound class="size-4" aria-hidden="true" />
                {{ t('ui.unanswered.to_me') }}
            </button>
            <Button
                v-if="groupFilter"
                variant="ghost"
                @click="groupFilter = null"
            >
                <X class="size-4" aria-hidden="true" />
                {{ t('ui.unanswered.clear_group') }}
            </Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-12">
            <section class="flex min-w-0 flex-col gap-4 lg:col-span-8">
                <EmptyState
                    v-if="!questions.data.length && filters.status === 'open'"
                    :icon="Inbox"
                    scene="chat"
                    tone="ai"
                    :title="t('ui.unanswered.empty_title')"
                    :description="t('ui.unanswered.empty_body')"
                    class="rounded-card border bg-surface shadow-clay-sm"
                />
                <EmptyState
                    v-else-if="!visible.length"
                    :icon="FilterX"
                    scene="search"
                    tone="muted"
                    :title="t('ui.common.no_results')"
                    :description="t('ui.common.try_other_filters')"
                    compact
                    class="rounded-card border bg-surface"
                />
                <QuestionCard
                    v-for="question in visible"
                    :key="question.id"
                    :question="question"
                    :assignees="assignees"
                    :can-convert="can('knowledge.manage')"
                    :highlighted="focusId === question.id"
                />
                <LaravelPagination
                    v-if="questions.meta.last_page > 1"
                    :links="questions.links"
                    :meta="questions.meta"
                    :count="questions.data.length"
                    :only="['questions']"
                />
            </section>

            <aside class="min-w-0 max-lg:order-first lg:col-span-4">
                <SectionCard
                    :title="t('ui.unanswered.groups_title')"
                    :description="t('ui.unanswered.groups_hint')"
                >
                    <ul v-if="groups.length" class="space-y-2">
                        <li
                            v-for="group in groups.slice(0, 10)"
                            :key="group.representative"
                        >
                            <button
                                type="button"
                                :aria-pressed="
                                    groupFilter?.representative ===
                                    group.representative
                                "
                                :class="
                                    cn(
                                        'press grid w-full gap-1.5 rounded-2xl border px-3 py-2.5 text-left',
                                        groupFilter?.representative ===
                                            group.representative
                                            ? 'border-primary bg-primary-soft'
                                            : 'hover:bg-surface-2',
                                    )
                                "
                                data-test="unanswered-group"
                                @click="pickGroup(group)"
                            >
                                <span
                                    class="line-clamp-2 text-sm font-medium"
                                    >{{ group.representative }}</span
                                >
                                <span class="flex items-center gap-2">
                                    <span
                                        class="h-1.5 flex-1 overflow-hidden rounded-chip bg-surface-2"
                                    >
                                        <span
                                            class="block h-full rounded-chip bg-primary"
                                            :style="{
                                                width: `${(group.count / maxGroup) * 100}%`,
                                            }"
                                        />
                                    </span>
                                    <span
                                        class="text-xs font-semibold tabular-nums"
                                        >{{
                                            tc(
                                                'ui.unanswered.questions',
                                                group.count,
                                            )
                                        }}</span
                                    >
                                </span>
                            </button>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('ui.unanswered.groups_empty') }}
                    </p>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>

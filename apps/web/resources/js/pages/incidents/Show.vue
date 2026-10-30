<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Bot,
    CircleAlert,
    ListChecks,
    MessageCircleQuestionMark,
    Plus,
    SendHorizontal,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import SeverityBadge from '@/components/chat/SeverityBadge.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import CategoryChip from '@/components/opsbot/CategoryChip.vue';
import CommentPromptDialog from '@/components/opsbot/CommentPromptDialog.vue';
import DueChip from '@/components/opsbot/DueChip.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import IncidentStatusBadge from '@/components/opsbot/IncidentStatusBadge.vue';
import TaskSheet from '@/components/opsbot/TaskSheet.vue';
import TaskStatusBadge from '@/components/opsbot/TaskStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { useIncidentTransition } from '@/composables/useIncidentTransition';
import { endpoints, withQuery } from '@/lib/endpoints';
import { sanitizeAnswer } from '@/lib/sanitize';
import { t } from '@/lib/i18n';
import { INCIDENT_CATEGORIES, SEVERITIES } from '@/lib/opsbot';
import { incidentStatusIcon } from '@/lib/opsbot-icons';
import type {
    Citation,
    Incident,
    IncidentEvent,
    IncidentStatus,
    Task,
    UserRef,
} from '@/types/opsbot';

const props = defineProps<{
    incident: Incident;
    events: IncidentEvent[];
    tasks: Task[];
    sourceQuery: {
        id: number;
        question: string;
        answer: string | null;
        citations: Citation[];
    } | null;
    assignees: UserRef[];
    transitions: IncidentStatus[];
    can: { manage: boolean; comment: boolean; createTask: boolean };
}>();

const fmt = useFormat();
const transition = useIncidentTransition();

const comment = useForm({ body: '' });

function sendComment(): void {
    comment.post(endpoints.incidents.comment(props.incident.id).url, {
        preserveScroll: true,
        onSuccess: () => comment.reset(),
    });
}

const saving = ref(false);

function patch(data: Record<string, string | number | null>): void {
    saving.value = true;
    router.patch(endpoints.incidents.update(props.incident.id).url, data, {
        preserveScroll: true,
        onFinish: () => (saving.value = false),
    });
}

const assignee = computed({
    get: () => props.incident.assignee?.id ?? null,
    set: (value: string | number | null | undefined) =>
        patch({ assignee_id: value ? Number(value) : null }),
});

const taskOpen = ref(false);

function userName(id: string | null): string {
    if (!id) {
        return t('ui.common.unassigned');
    }

    return (
        props.assignees.find((user) => String(user.id) === id)?.name ??
        (props.incident.assignee && String(props.incident.assignee.id) === id
            ? props.incident.assignee.name
            : `#${id}`)
    );
}

function taskLabel(value: string | null): string {
    const task = props.tasks.find((item) => String(item.number) === value);

    return task ? `${task.display_number} ${task.title}` : (value ?? '');
}

function eventText(event: IncidentEvent): string {
    switch (event.type) {
        case 'status_changed':
            return t('ui.incidents.events.status_changed', {
                from: event.from ? t(`incidents.status.${event.from}`) : '—',
                to: event.to ? t(`incidents.status.${event.to}`) : '—',
            });
        case 'severity_changed':
            return t('ui.incidents.events.severity_changed', {
                from: event.from ? t(`incidents.severity.${event.from}`) : '—',
                to: event.to ? t(`incidents.severity.${event.to}`) : '—',
            });
        case 'category_changed':
            return t('ui.incidents.events.category_changed', {
                from: event.from ? t(`incidents.category.${event.from}`) : '—',
                to: event.to ? t(`incidents.category.${event.to}`) : '—',
            });
        case 'assigned':
            return t('ui.incidents.events.assigned', {
                to: userName(event.to),
            });
        case 'task_linked':
            return t('ui.incidents.events.task_linked', {
                task: taskLabel(event.to),
            });
        case 'created':
            return t('ui.incidents.events.created');
        default:
            return t('incidents.event.comment');
    }
}

const sourceAnswer = computed(() =>
    props.sourceQuery?.answer ? sanitizeAnswer(props.sourceQuery.answer) : null,
);
</script>

<template>
    <Head :title="`${incident.display_number} ${incident.title}`" />

    <div
        class="mx-auto flex w-full max-w-[1280px] flex-col gap-5 px-4 py-6 md:px-6"
    >
        <Link
            :href="endpoints.incidents.index()"
            class="inline-flex min-h-11 items-center gap-1.5 self-start text-sm font-medium text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            {{ t('ui.incidents.back') }}
        </Link>

        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <div class="min-w-0 space-y-2">
                <h1 class="text-h1 text-balance">
                    <span
                        class="mr-2 font-mono text-[1.0625rem] font-medium text-muted-foreground"
                        >{{ incident.display_number }}</span
                    >
                    {{ incident.title }}
                </h1>
                <div class="flex flex-wrap items-center gap-1.5">
                    <CategoryChip :category="incident.category" />
                    <SeverityBadge :severity="incident.severity" />
                    <IncidentStatusBadge :status="incident.status" />
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-for="(to, index) in transitions"
                    :key="to"
                    :variant="index === 0 ? 'default' : 'secondary'"
                    :disabled="transition.processing.value"
                    data-test="incident-transition"
                    :data-to="to"
                    @click="transition.request(incident, to)"
                >
                    <component
                        :is="incidentStatusIcon[to]"
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ t(`ui.incidents.transition.${to}`) }}
                </Button>
                <DropdownMenu v-if="can.manage">
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost">{{
                            t('ui.incidents.edit')
                        }}</Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuLabel>{{
                            t('ui.incidents.fields.severity')
                        }}</DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="level in SEVERITIES"
                            :key="level"
                            :disabled="level === incident.severity"
                            @select="patch({ severity: level })"
                        >
                            {{ t(`incidents.severity.${level}`) }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel>{{
                            t('ui.incidents.fields.category')
                        }}</DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="category in INCIDENT_CATEGORIES"
                            :key="category"
                            :disabled="category === incident.category"
                            @select="patch({ category })"
                        >
                            {{ t(`incidents.category.${category}`) }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>

        <div class="grid gap-6 lg:grid-cols-12">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-8">
                <SectionCard :title="t('ui.incidents.fields.description')">
                    <p class="text-pretty whitespace-pre-line">
                        {{ incident.description }}
                    </p>
                </SectionCard>

                <SectionCard :title="t('ui.incidents.timeline')">
                    <ol class="space-y-4" data-test="incident-timeline">
                        <li
                            v-for="event in events"
                            :key="event.id"
                            class="flex gap-3"
                        >
                            <UserAvatar
                                :name="
                                    event.actor?.name ?? t('ui.common.system')
                                "
                                size="sm"
                            />
                            <div class="min-w-0 flex-1 space-y-1">
                                <p class="text-sm">
                                    <span class="font-semibold">{{
                                        event.actor?.name ??
                                        t('ui.common.system')
                                    }}</span>
                                    <span class="text-muted-foreground">
                                        · {{ eventText(event) }}</span
                                    >
                                </p>
                                <p
                                    v-if="event.body"
                                    class="bubble-tail-start rounded-card bg-surface-2 px-3.5 py-2.5 text-[0.9375rem] whitespace-pre-line"
                                >
                                    {{ event.body }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    <RelativeTime :value="event.created_at" />
                                </p>
                            </div>
                        </li>
                    </ol>
                    <form
                        v-if="can.comment"
                        class="mt-5 grid gap-2 border-t pt-4"
                        @submit.prevent="sendComment"
                    >
                        <Label for="incident-comment">{{
                            t('ui.incidents.add_comment')
                        }}</Label>
                        <Textarea
                            id="incident-comment"
                            v-model="comment.body"
                            rows="3"
                            maxlength="2000"
                            data-test="incident-comment-input"
                        />
                        <p
                            v-if="comment.errors.body"
                            class="inline-flex items-center gap-1 text-xs text-destructive"
                        >
                            <CircleAlert class="size-3.5" aria-hidden="true" />
                            {{ comment.errors.body }}
                        </p>
                        <Button
                            type="submit"
                            class="justify-self-end"
                            :disabled="
                                comment.processing || !comment.body.trim()
                            "
                            data-test="incident-comment-submit"
                        >
                            <Spinner v-if="comment.processing" />
                            <SendHorizontal
                                v-else
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ t('ui.incidents.send_comment') }}
                        </Button>
                    </form>
                </SectionCard>
            </div>

            <aside class="flex min-w-0 flex-col gap-6 lg:col-span-4">
                <SectionCard :title="t('ui.incidents.details')">
                    <dl class="grid gap-3 text-sm">
                        <div class="grid gap-1">
                            <dt class="text-caption">
                                {{ t('ui.incidents.fields.assignee') }}
                            </dt>
                            <dd>
                                <FilterSelect
                                    v-if="can.manage"
                                    v-model="assignee"
                                    :label="t('ui.incidents.fields.assignee')"
                                    :all-label="t('ui.common.unassigned')"
                                    :options="
                                        assignees.map((u) => ({
                                            value: u.id,
                                            label: u.name,
                                        }))
                                    "
                                    class="sm:w-full"
                                    test-id="incident-assignee"
                                />
                                <span v-else>{{
                                    incident.assignee?.name ??
                                    t('ui.common.unassigned')
                                }}</span>
                            </dd>
                        </div>
                        <div class="grid grid-cols-2 gap-1">
                            <dt class="text-caption">
                                {{ t('ui.incidents.fields.reporter') }}
                            </dt>
                            <dd>{{ incident.reporter?.name ?? '—' }}</dd>
                            <dt class="text-caption">
                                {{ t('ui.common.department') }}
                            </dt>
                            <dd>{{ incident.department?.name ?? '—' }}</dd>
                            <dt class="text-caption">
                                {{ t('ui.incidents.fields.client_ref') }}
                            </dt>
                            <dd class="[overflow-wrap:anywhere]">
                                {{ incident.client_ref ?? '—' }}
                            </dd>
                            <dt class="text-caption">
                                {{ t('ui.incidents.fields.created') }}
                            </dt>
                            <dd>{{ fmt.dateTime(incident.created_at) }}</dd>
                            <template v-if="incident.resolved_at">
                                <dt class="text-caption">
                                    {{ t('ui.incidents.fields.resolved') }}
                                </dt>
                                <dd>
                                    {{ fmt.dateTime(incident.resolved_at) }}
                                </dd>
                            </template>
                            <template v-if="incident.closed_at">
                                <dt class="text-caption">
                                    {{ t('ui.incidents.fields.closed') }}
                                </dt>
                                <dd>{{ fmt.dateTime(incident.closed_at) }}</dd>
                            </template>
                            <dt class="text-caption">
                                {{ t('ui.incidents.fields.source') }}
                            </dt>
                            <dd class="inline-flex items-center gap-1">
                                <Sparkles
                                    v-if="incident.source === 'ai_proposal'"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                <Bot
                                    v-else-if="incident.source === 'bot'"
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                {{ t(`incidents.source.${incident.source}`) }}
                            </dd>
                        </div>
                    </dl>
                </SectionCard>

                <SectionCard
                    v-if="sourceQuery"
                    :title="t('ui.incidents.source_question')"
                    :icon="MessageCircleQuestionMark"
                    data-test="incident-source-query"
                >
                    <blockquote
                        class="bubble-tail-end rounded-card bg-primary-soft px-3.5 py-2.5 text-sm"
                    >
                        {{ sourceQuery.question }}
                    </blockquote>
                    <div
                        v-if="sourceAnswer"
                        class="answer-prose mt-3 line-clamp-6 text-sm"
                        v-html="sourceAnswer"
                    />
                    <div
                        v-if="sourceQuery.citations.length"
                        class="mt-3 flex flex-wrap gap-2"
                    >
                        <a
                            v-for="citation in sourceQuery.citations"
                            :key="citation.index"
                            :href="citation.url ?? undefined"
                            class="inline-flex h-7 max-w-[17.5rem] items-center gap-1.5 rounded-chip border bg-surface-2 py-1 pr-2.5 pl-1 text-[0.8125rem] font-medium text-primary-strong hover:border-primary"
                            :aria-label="
                                t('ui.assistant.citation_label', {
                                    n: citation.index,
                                    title: citation.title,
                                })
                            "
                        >
                            <span
                                class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-primary font-mono text-xs text-primary-foreground"
                                >{{ citation.index }}</span
                            >
                            <span class="truncate">{{ citation.title }}</span>
                        </a>
                    </div>
                    <p class="mt-3 text-xs text-muted-foreground">
                        {{ t('ui.incidents.source_audited') }}
                    </p>
                </SectionCard>

                <SectionCard
                    :title="t('ui.incidents.tasks')"
                    :icon="ListChecks"
                >
                    <template v-if="can.createTask" #actions>
                        <Button
                            size="sm"
                            variant="secondary"
                            data-test="incident-create-task"
                            @click="taskOpen = true"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            {{ t('ui.incidents.create_task') }}
                        </Button>
                    </template>
                    <ul v-if="tasks.length" class="divide-y">
                        <li
                            v-for="task in tasks"
                            :key="task.id"
                            class="space-y-1 py-2.5"
                        >
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
                            <div class="flex flex-wrap gap-1.5">
                                <TaskStatusBadge :status="task.status" />
                                <DueChip
                                    :due-at="task.due_at"
                                    :done="
                                        task.status === 'done' ||
                                        task.status === 'cancelled'
                                    "
                                />
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('ui.incidents.no_tasks') }}
                    </p>
                </SectionCard>
            </aside>
        </div>
    </div>

    <CommentPromptDialog
        v-model:open="transition.promptOpen.value"
        :title="transition.promptTitle.value"
        :required="transition.required.value"
        :processing="transition.processing.value"
        @confirm="transition.confirm"
    />
    <TaskSheet
        v-model:open="taskOpen"
        :task="null"
        :incident-id="incident.id"
        :assignees="assignees"
        :departments="[]"
        :can-manage="true"
        :can-assign="true"
    />
</template>

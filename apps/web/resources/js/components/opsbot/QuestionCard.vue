<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    Ban,
    BookPlus,
    CircleAlert,
    FileText,
    SendHorizontal,
    UserCheck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import type { OpsTone } from '@/components/opsbot/OpsBadge.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t, tc } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    DismissReason,
    QuestionReason,
    UnansweredQuestion,
    UserRef,
} from '@/types/opsbot';

const props = defineProps<{
    question: UnansweredQuestion;
    assignees: UserRef[];
    canConvert: boolean;
    highlighted?: boolean;
}>();

const fmt = useFormat();
const MAX = 2000;
const answer = ref('');
const error = ref<string | null>(null);
const busy = ref<string | null>(null);

const reasonTone: Record<QuestionReason, OpsTone> = {
    low_confidence: 'sun',
    not_found: 'neutral',
    conflict: 'coral',
    user_request: 'primary',
    limit_reached: 'neutral',
};

const dismissReasons: DismissReason[] = [
    'duplicate',
    'out_of_scope',
    'spam',
    'other',
];

const overdue = computed(
    () => props.question.status === 'open' && props.question.age_hours >= 24,
);

const age = computed(() =>
    props.question.age_hours >= 24
        ? tc('ui.units.days_short', Math.floor(props.question.age_hours / 24))
        : tc('ui.units.hours_short', props.question.age_hours),
);

function submitAnswer(): void {
    const text = answer.value.trim();

    if (!text) {
        return;
    }

    busy.value = 'answer';
    error.value = null;
    router.post(
        endpoints.unanswered.answer(props.question.id).url,
        { answer_text: text },
        {
            preserveScroll: true,
            onSuccess: () => (answer.value = ''),
            onError: (errors) =>
                (error.value = errors.answer_text ?? t('ui.errors.generic')),
            onFinish: () => (busy.value = null),
        },
    );
}

function dismiss(reason: DismissReason): void {
    busy.value = 'dismiss';
    router.post(
        endpoints.unanswered.dismiss(props.question.id).url,
        { reason },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function convert(): void {
    busy.value = 'convert';
    router.post(
        endpoints.unanswered.convert(props.question.id).url,
        {},
        { onFinish: () => (busy.value = null) },
    );
}

const assignee = computed({
    get: () => props.question.assignee?.id ?? null,
    set: (value: string | number | null | undefined) => {
        if (!value) {
            return;
        }

        busy.value = 'reassign';
        router.patch(
            endpoints.unanswered.reassign(props.question.id).url,
            { assignee_user_id: Number(value) },
            { preserveScroll: true, onFinish: () => (busy.value = null) },
        );
    },
});
</script>

<template>
    <article
        :id="`question-${question.id}`"
        :aria-label="question.display_number"
        :class="
            cn(
                'space-y-3 rounded-card border bg-surface p-4 shadow-clay-sm transition-shadow sm:p-5',
                highlighted ? 'outline-2 outline-offset-2 outline-ring' : '',
            )
        "
        data-test="unanswered-card"
        :data-number="question.number"
        :data-status="question.status"
    >
        <header class="flex flex-wrap items-center gap-2">
            <span
                class="font-mono text-[0.8125rem] font-medium text-muted-foreground"
                >{{ question.display_number }}</span
            >
            <OpsBadge
                :label="t(`questions.reason.${question.reason}`)"
                :tone="reasonTone[question.reason]"
            />
            <OpsBadge
                v-if="question.similar_count > 0"
                :label="tc('ui.unanswered.similar', question.similar_count)"
                tone="primary"
            />
            <span
                :class="[
                    'ml-auto text-xs font-medium tabular-nums',
                    overdue ? 'text-coral-strong' : 'text-muted-foreground',
                ]"
            >
                <RelativeTime :value="question.asked_at" />
                <span class="sr-only">· {{ age }}</span>
            </span>
        </header>

        <blockquote
            class="bubble-tail-end rounded-card bg-primary-soft px-4 py-3 text-[0.9375rem] text-pretty whitespace-pre-line"
        >
            {{ question.question }}
        </blockquote>

        <p
            class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground"
        >
            <span class="font-medium text-foreground">{{
                question.employee.name
            }}</span>
            <span
                v-if="question.department"
                class="inline-flex h-6 items-center rounded-chip bg-surface-2 px-2 text-xs font-medium"
                >{{ question.department.name }}</span
            >
            <span v-if="question.assignee"
                >·
                {{
                    t('ui.unanswered.assigned_to', {
                        name: question.assignee.name,
                    })
                }}</span
            >
        </p>

        <template v-if="question.status === 'open'">
            <form class="grid gap-2" @submit.prevent="submitAnswer">
                <Label :for="`answer-${question.id}`">{{
                    t('ui.unanswered.answer_label', {
                        number: question.display_number,
                    })
                }}</Label>
                <Textarea
                    :id="`answer-${question.id}`"
                    v-model="answer"
                    rows="3"
                    :maxlength="MAX"
                    :aria-invalid="Boolean(error)"
                    data-test="unanswered-answer-input"
                />
                <p
                    class="flex justify-between gap-2 text-xs text-muted-foreground"
                >
                    <span
                        v-if="error"
                        class="inline-flex items-center gap-1 text-destructive"
                    >
                        <CircleAlert class="size-3.5" aria-hidden="true" />
                        {{ error }}
                    </span>
                    <span v-else>{{ t('ui.unanswered.not_from_rules') }}</span>
                    <span class="tabular-nums"
                        >{{ answer.length }} / {{ MAX }}</span
                    >
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="submit"
                        :disabled="!answer.trim() || busy !== null"
                        data-test="unanswered-answer-submit"
                    >
                        <Spinner v-if="busy === 'answer'" />
                        <SendHorizontal
                            v-else
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ t('ui.unanswered.answer') }}
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                type="button"
                                variant="ghost"
                                :disabled="busy !== null"
                                data-test="unanswered-dismiss"
                            >
                                <Spinner v-if="busy === 'dismiss'" />
                                <Ban v-else class="size-4" aria-hidden="true" />
                                {{ t('ui.unanswered.dismiss') }}
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuLabel>{{
                                t('ui.unanswered.dismiss_reason')
                            }}</DropdownMenuLabel>
                            <DropdownMenuItem
                                v-for="reason in dismissReasons"
                                :key="reason"
                                data-test="unanswered-dismiss-reason"
                                :data-reason="reason"
                                @select="dismiss(reason)"
                            >
                                {{ t(`questions.dismiss_reason.${reason}`) }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <FilterSelect
                        v-if="assignees.length"
                        v-model="assignee"
                        :nullable="false"
                        :label="t('ui.unanswered.reassign')"
                        :options="
                            assignees.map((u) => ({
                                value: u.id,
                                label: u.name,
                            }))
                        "
                        class="ml-auto sm:w-52"
                        test-id="unanswered-reassign"
                    />
                </div>
            </form>
        </template>

        <template
            v-else-if="
                question.status === 'answered' ||
                question.status === 'converted_to_doc'
            "
        >
            <div
                class="bubble-tail-start space-y-1 rounded-card bg-sun-soft px-4 py-3"
            >
                <p
                    class="flex items-center gap-1.5 text-xs font-semibold text-sun-strong"
                >
                    <UserCheck class="size-3.5" aria-hidden="true" />
                    {{ t('ui.chat.manager_label') }}
                </p>
                <p class="text-[0.9375rem] whitespace-pre-line">
                    {{ question.answer_text }}
                </p>
                <p class="text-xs text-muted-foreground">
                    — {{ question.answered_by?.name ?? '—' }}
                    <template v-if="question.answered_at"
                        >·
                        {{
                            fmt.dateTime(question.answered_at, 'short')
                        }}</template
                    >
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    v-if="question.document"
                    :href="endpoints.knowledge.show(question.document.id)"
                    class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-primary-strong hover:underline"
                >
                    <FileText class="size-4" aria-hidden="true" />
                    {{ question.document.title }}
                </Link>
                <Button
                    v-else-if="canConvert && question.status === 'answered'"
                    variant="secondary"
                    :disabled="busy !== null"
                    data-test="unanswered-convert"
                    @click="convert"
                >
                    <Spinner v-if="busy === 'convert'" />
                    <BookPlus v-else class="size-4" aria-hidden="true" />
                    {{ t('ui.unanswered.convert') }}
                </Button>
            </div>
        </template>
    </article>
</template>

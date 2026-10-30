<script setup lang="ts">
import { Head, Link, useHttp, usePage } from '@inertiajs/vue3';
import {
    BookOpenText,
    CircleAlert,
    CirclePause,
    CircleCheck,
    Gauge,
    History,
    MessageCircleQuestionMark,
    RefreshCw,
    RotateCcw,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { toast } from 'vue-sonner';
import AssistantAnswer from '@/components/assistant/AssistantAnswer.vue';
import ChatComposer from '@/components/assistant/ChatComposer.vue';
import TypingIndicator from '@/components/assistant/TypingIndicator.vue';
import ChatBubble from '@/components/chat/ChatBubble.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import TelegramIcon from '@/components/integrations/TelegramIcon.vue';
import Callout from '@/components/opsbot/Callout.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { useUpgrade } from '@/composables/useUpgrade';
import { endpoints } from '@/lib/endpoints';
import { describeHttpError } from '@/lib/http-errors';
import { JsonRequestError, jsonRequest } from '@/lib/http';
import { t } from '@/lib/i18n';
import type {
    ActionResult,
    ActionStep,
    ChatAnswer,
    Citation,
    ProposedAction,
} from '@/types/opsbot';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ui.nav.assistant', href: endpoints.assistant.index() },
        ],
    },
});

const props = withDefaults(
    defineProps<{
        suggestions?: string[];
        bot?: { username: string | null };
        usage?: { used: number; limit: number | null };
        minConfidence?: number;
    }>(),
    {
        suggestions: () => [],
        bot: () => ({ username: null }),
        usage: () => ({ used: 0, limit: null }),
        minConfidence: 0.6,
    },
);

const page = usePage();
const { can } = useCan();
const fmt = useFormat();
const upgrade = useUpgrade();

type AssistantMessage = {
    id: string;
    role: 'assistant';
    question: string;
    answer: ChatAnswer | null;
    error: string | null;
};

type ChatMessage =
    | { id: string; role: 'user'; text: string }
    | AssistantMessage
    | {
          id: string;
          role: 'system';
          text: string;
          url: string | null;
          ok: boolean;
      };

const newId = (): string =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;

const conversationId = ref(newId());
const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const scroller = ref<HTMLElement | null>(null);
const composer = ref<InstanceType<typeof ChatComposer> | null>(null);
const used = ref(props.usage.used);
const blockedBy = ref<'paused' | 'limit' | null>(
    page.props.workspace?.subscription_state === 'paused'
        ? 'paused'
        : props.usage.limit !== null && props.usage.used >= props.usage.limit
          ? 'limit'
          : null,
);
const selectedId = ref<string | null>(null);
const sourcesOpen = ref(false);

const http = useHttp<{ message: string; conversation_id: string }, ChatAnswer>({
    message: '',
    conversation_id: conversationId.value,
});

const busy = computed(() => http.processing);

async function scrollToBottom(): Promise<void> {
    await nextTick();
    scroller.value?.scrollTo({
        top: scroller.value.scrollHeight,
        behavior: 'smooth',
    });
}

function find(id: string): AssistantMessage | null {
    const message = messages.value.find((item) => item.id === id);

    return message && message.role === 'assistant' ? message : null;
}

async function ask(question: string, retryId?: string): Promise<void> {
    const text = question.trim();

    if (!text || busy.value || blockedBy.value) {
        return;
    }

    let id = retryId ?? '';

    if (retryId) {
        const existing = find(retryId);

        if (existing) {
            existing.error = null;
        }
    } else {
        messages.value.push({ id: newId(), role: 'user', text });
        id = newId();
        messages.value.push({
            id,
            role: 'assistant',
            question: text,
            answer: null,
            error: null,
        });
        draft.value = '';
    }

    void scrollToBottom();

    http.message = text;
    http.conversation_id = conversationId.value;

    try {
        const answer = await http.post(endpoints.assistant.ask().url);
        const target = find(id);

        if (!target) {
            return;
        }

        if (answer) {
            target.answer = answer;
            selectedId.value = id;

            if (answer.refusal_reason !== 'limit_reached') {
                used.value += 1;
            }
        } else {
            const validation = http.errors.message;
            target.error =
                typeof validation === 'string' && validation
                    ? validation
                    : t('ui.assistant.error');
        }
    } catch (error) {
        const target = find(id);
        const info = describeHttpError(error);

        if (target) {
            if (info.kind === 'cancelled') {
                target.error = t('ui.assistant.stopped');
            } else if (info.status === 423) {
                blockedBy.value = 'paused';
                target.error = info.message ?? t('ui.assistant.paused_title');
            } else if (info.status === 402) {
                blockedBy.value = 'limit';
                target.error = info.message ?? t('ui.assistant.limit_title');

                if (can('billing.view')) {
                    upgrade.show('ai_queries_monthly');
                }
            } else if (info.status === 429) {
                target.error = info.message ?? t('ui.assistant.rate_limited');
            } else if (info.status === 503 || info.status === 502) {
                target.error = t('ui.assistant.unavailable');
            } else {
                target.error = info.message ?? t('ui.assistant.error');
            }
        }
    } finally {
        void scrollToBottom();
        composer.value?.focus();
    }
}

const pendingAction = ref<string | null>(null);
const confirming = ref<{
    message: AssistantMessage;
    action: ProposedAction;
    result: ActionResult;
} | null>(null);
const confirmOpen = computed({
    get: () => confirming.value !== null,
    set: (value: boolean) => {
        if (!value && confirming.value && !pendingAction.value) {
            void cancelConfirm();
        }
    },
});

async function step(
    action: ProposedAction,
    value: ActionStep,
): Promise<ActionResult> {
    try {
        return await jsonRequest<ActionResult>(
            endpoints.assistant.action(action.id).url,
            'POST',
            { step: value },
        );
    } catch (error) {
        if (error instanceof JsonRequestError && error.body) {
            return {
                ok: false,
                message:
                    typeof error.body.message === 'string'
                        ? error.body.message
                        : t('ui.assistant.action_failed'),
            };
        }

        return { ok: false, message: t('ui.assistant.action_failed') };
    }
}

function setStatus(
    message: AssistantMessage,
    actionId: string,
    status: ProposedAction['status'],
): void {
    const target = message.answer?.actions.find((item) => item.id === actionId);

    if (target) {
        target.status = status;
    }
}

function settle(
    message: AssistantMessage,
    action: ProposedAction,
    result: ActionResult,
): void {
    if (result.ok && result.result) {
        setStatus(message, action.id, 'executed');
        messages.value.push({
            id: newId(),
            role: 'system',
            text: result.message,
            url: result.result.url,
            ok: true,
        });
        toast.success(result.message);
        void scrollToBottom();

        return;
    }

    if (result.ok) {
        setStatus(message, action.id, 'executed');
        toast.success(result.message || t('ui.assistant.action_done'));

        return;
    }

    messages.value.push({
        id: newId(),
        role: 'system',
        text: result.message || t('ui.assistant.action_failed'),
        url: null,
        ok: false,
    });
    toast.error(result.message || t('ui.assistant.action_failed'));
    void scrollToBottom();
}

async function runAction(
    message: AssistantMessage,
    action: ProposedAction,
): Promise<void> {
    if (pendingAction.value) {
        return;
    }

    pendingAction.value = action.id;

    try {
        const result = await step(action, 'preview');

        if (result.ok && result.card && !result.result) {
            confirming.value = { message, action, result };

            return;
        }

        settle(message, action, result);
    } finally {
        pendingAction.value = null;
    }
}

async function confirmAction(): Promise<void> {
    const current = confirming.value;

    if (!current || pendingAction.value) {
        return;
    }

    pendingAction.value = current.action.id;

    try {
        const result = await step(current.action, 'confirm');
        confirming.value = null;
        settle(current.message, current.action, result);
    } finally {
        pendingAction.value = null;
        composer.value?.focus();
    }
}

async function cancelConfirm(): Promise<void> {
    const current = confirming.value;
    confirming.value = null;

    if (!current) {
        return;
    }

    const result = await step(current.action, 'cancel');

    if (result.ok) {
        setStatus(current.message, current.action.id, 'cancelled');
    }
}

function stop(): void {
    http.cancel();
}

function reset(): void {
    if (busy.value) {
        http.cancel();
    }

    messages.value = [];
    selectedId.value = null;
    conversationId.value = newId();
    composer.value?.focus();
}

const selected = computed<AssistantMessage | null>(() => {
    const answered = messages.value.filter(
        (message): message is AssistantMessage =>
            message.role === 'assistant' && message.answer !== null,
    );

    return (
        answered.find((message) => message.id === selectedId.value) ??
        answered.at(-1) ??
        null
    );
});

const selectedCitations = computed<Citation[]>(
    () => selected.value?.answer?.citations ?? [],
);

function showSources(message: AssistantMessage): void {
    selectedId.value = message.id;
    sourcesOpen.value = true;
}

const visibleSuggestions = computed(() => props.suggestions.slice(0, 4));

const usageCaption = computed(() =>
    props.usage.limit === null
        ? t('ui.assistant.usage_unlimited', {
              used: fmt.value.number(used.value),
          })
        : t('ui.assistant.usage', {
              used: fmt.value.number(used.value),
              limit: fmt.value.number(props.usage.limit),
          }),
);

const thresholdPercent = computed(() => fmt.value.percent(props.minConfidence));
</script>

<template>
    <Head :title="t('ui.nav.assistant')" />

    <div
        class="mx-auto flex h-[calc(100svh-3.5rem-5rem)] w-full max-w-[1400px] gap-6 px-3 pt-4 sm:px-6 md:h-[calc(100svh-3.5rem-0.5rem)]"
    >
        <section
            class="flex min-w-0 flex-1 flex-col"
            :aria-label="t('ui.assistant.conversation')"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-3 pb-3"
            >
                <div class="min-w-0">
                    <h1 class="text-h1">{{ t('ui.assistant.title') }}</h1>
                    <p class="text-[0.9375rem] text-muted-foreground">
                        {{ t('ui.assistant.telegram_caption') }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a
                        v-if="bot.username"
                        :href="`https://t.me/${bot.username}`"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="hidden h-9 items-center gap-2 rounded-chip border bg-surface px-3 text-sm hover:border-primary sm:inline-flex"
                        :aria-label="`${t('ui.assistant.open_bot')}: @${bot.username}`"
                        data-test="assistant-open-bot"
                    >
                        <TelegramIcon class="size-4" />
                        <span class="font-mono text-[0.8125rem]"
                            >@{{ bot.username }}</span
                        >
                    </a>
                    <Button
                        v-if="can('assistant.history')"
                        as-child
                        variant="ghost"
                        size="sm"
                    >
                        <Link :href="endpoints.assistant.history()">
                            <History aria-hidden="true" />
                            <span class="max-sm:sr-only">{{
                                t('ui.nav.history')
                            }}</span>
                        </Link>
                    </Button>
                    <Button
                        variant="secondary"
                        size="sm"
                        :disabled="!messages.length"
                        @click="reset"
                    >
                        <RotateCcw aria-hidden="true" />
                        <span class="max-sm:sr-only">{{
                            t('ui.assistant.new_chat')
                        }}</span>
                    </Button>
                </div>
            </header>

            <div
                ref="scroller"
                class="bg-dot-pattern relative flex-1 scrollbar-thin overflow-y-auto rounded-dialog border bg-surface-2"
            >
                <div
                    v-if="!messages.length"
                    class="flex min-h-full flex-col items-center justify-center gap-5 px-4 py-10 text-center"
                >
                    <EmptyState
                        :icon="BookOpenText"
                        scene="documents"
                        class="py-0"
                        :title="t('ui.assistant.empty_title')"
                        :description="t('ui.assistant.empty_body')"
                    />
                    <ul
                        v-if="visibleSuggestions.length && !blockedBy"
                        class="flex w-full max-w-2xl flex-wrap justify-center gap-2"
                        :aria-label="t('ui.assistant.suggestions')"
                    >
                        <li
                            v-for="suggestion in visibleSuggestions"
                            :key="suggestion"
                        >
                            <button
                                type="button"
                                class="press inline-flex min-h-11 max-w-full items-center gap-2 rounded-chip border bg-surface px-4 py-2 text-left text-sm font-medium shadow-clay-sm hover:border-primary"
                                data-test="assistant-suggestion"
                                @click="ask(suggestion)"
                            >
                                <MessageCircleQuestionMark
                                    class="size-4 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                <span class="[overflow-wrap:anywhere]">{{
                                    suggestion
                                }}</span>
                            </button>
                        </li>
                    </ul>
                </div>

                <ol
                    v-else
                    role="log"
                    aria-live="polite"
                    :aria-busy="busy"
                    class="mx-auto max-w-[760px] space-y-4 px-3 py-5 sm:px-6"
                >
                    <li
                        v-for="message in messages"
                        :key="message.id"
                        data-test="assistant-message"
                        :data-role="
                            message.role === 'user'
                                ? 'employee'
                                : message.role === 'system'
                                  ? 'system'
                                  : 'bot'
                        "
                        :data-refused="
                            message.role === 'assistant' &&
                            message.answer?.refused
                                ? 'true'
                                : 'false'
                        "
                    >
                        <ChatBubble
                            v-if="message.role === 'user'"
                            variant="employee"
                        >
                            <span class="sr-only">{{
                                t('ui.assistant.you_said')
                            }}</span>
                            <span class="whitespace-pre-wrap">{{
                                message.text
                            }}</span>
                        </ChatBubble>
                        <p
                            v-else-if="message.role === 'system'"
                            role="status"
                            :class="[
                                'mx-auto flex w-fit max-w-full items-center gap-2 rounded-chip px-3.5 py-1.5 text-sm',
                                message.ok
                                    ? 'bg-mint-soft text-mint-strong'
                                    : 'bg-coral-soft text-coral-strong',
                            ]"
                        >
                            <CircleCheck
                                v-if="message.ok"
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <CircleAlert
                                v-else
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="[overflow-wrap:anywhere]">{{
                                message.text
                            }}</span>
                            <Link
                                v-if="message.url"
                                :href="message.url"
                                class="font-semibold underline underline-offset-4"
                                data-test="assistant-result-link"
                                >{{ t('ui.common.open') }}</Link
                            >
                        </p>
                        <article
                            v-else
                            :aria-label="t('ui.assistant.answer_label')"
                            @focusin="selectedId = message.id"
                            @pointerdown="selectedId = message.id"
                        >
                            <ChatBubble
                                variant="bot"
                                :refused="Boolean(message.answer?.refused)"
                                class="[&>div]:w-full sm:[&>div]:w-auto sm:[&>div]:min-w-[22rem]"
                            >
                                <TypingIndicator
                                    v-if="!message.answer && !message.error"
                                />
                                <div
                                    v-else-if="message.error"
                                    class="space-y-3"
                                    role="alert"
                                >
                                    <p
                                        class="flex items-center gap-2 text-sm font-medium text-foreground"
                                    >
                                        <CircleAlert
                                            class="size-4 shrink-0 text-coral-strong"
                                            aria-hidden="true"
                                        />
                                        {{ message.error }}
                                    </p>
                                    <Button
                                        v-if="!blockedBy"
                                        size="sm"
                                        variant="secondary"
                                        :disabled="busy"
                                        data-test="assistant-retry"
                                        @click="
                                            ask(message.question, message.id)
                                        "
                                    >
                                        <RefreshCw aria-hidden="true" />
                                        {{ t('ui.common.retry') }}
                                    </Button>
                                </div>
                                <AssistantAnswer
                                    v-else-if="message.answer"
                                    :answer="message.answer"
                                    :threshold="minConfidence"
                                    :pending-action="pendingAction"
                                    @ask="ask"
                                    @action="
                                        (action) => runAction(message, action)
                                    "
                                    @sources="showSources(message)"
                                />
                            </ChatBubble>
                        </article>
                    </li>
                </ol>
            </div>

            <div class="space-y-2 pt-3 pb-3">
                <Callout
                    v-if="blockedBy"
                    :tone="blockedBy === 'paused' ? 'coral' : 'sun'"
                    :icon="blockedBy === 'paused' ? CirclePause : Gauge"
                    :title="
                        blockedBy === 'paused'
                            ? t('ui.assistant.paused_title')
                            : t('ui.assistant.limit_title')
                    "
                    data-test="assistant-blocked"
                    :data-reason="blockedBy"
                >
                    {{
                        blockedBy === 'paused'
                            ? t('ui.assistant.paused_body')
                            : t('ui.assistant.limit_body')
                    }}
                    <template v-if="can('billing.view')" #actions>
                        <Button
                            v-if="blockedBy === 'limit'"
                            size="sm"
                            @click="upgrade.show('ai_queries_monthly')"
                            >{{ t('ui.upgrade.cta') }}</Button
                        >
                        <Button v-else size="sm" as-child>
                            <Link :href="endpoints.settings.billing.show()">{{
                                t('ui.dashboard.banners.pay')
                            }}</Link>
                        </Button>
                    </template>
                </Callout>
                <template v-else>
                    <div
                        v-if="
                            messages.length &&
                            visibleSuggestions.length &&
                            !busy
                        "
                        class="flex scrollbar-none gap-2 overflow-x-auto pb-1"
                    >
                        <button
                            v-for="suggestion in visibleSuggestions.slice(0, 3)"
                            :key="suggestion"
                            type="button"
                            class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-chip border bg-surface px-3.5 text-[0.8125rem] font-medium text-muted-foreground transition-colors hover:border-primary hover:text-foreground pointer-coarse:h-11"
                            data-test="assistant-suggestion"
                            @click="ask(suggestion)"
                        >
                            <MessageCircleQuestionMark
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            {{ suggestion }}
                        </button>
                    </div>
                    <ChatComposer
                        ref="composer"
                        v-model="draft"
                        :busy="busy"
                        @send="ask"
                        @stop="stop"
                    />
                </template>
                <p class="text-center text-xs text-muted-foreground">
                    {{ t('ui.assistant.disclaimer') }}
                </p>
            </div>
        </section>

        <aside
            class="hidden w-[340px] shrink-0 flex-col gap-4 overflow-y-auto pb-4 xl:flex"
            :aria-label="t('ui.assistant.sources_panel')"
            data-test="assistant-sources-panel"
        >
            <div class="rounded-card border bg-surface p-5 shadow-clay">
                <h2 class="text-h2">
                    {{ t('ui.assistant.sources_panel') }}
                </h2>
                <p
                    v-if="!selectedCitations.length"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ t('ui.assistant.sources_empty') }}
                </p>
                <ul v-else class="mt-3 space-y-3">
                    <li
                        v-for="citation in selectedCitations"
                        :key="`${citation.chunk_id}-${citation.index}`"
                        class="rounded-2xl bg-surface-2 p-3 text-sm"
                    >
                        <p class="flex items-start gap-2 font-semibold">
                            <span
                                class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-primary font-mono text-xs text-primary-foreground"
                                aria-hidden="true"
                                >{{ citation.index }}</span
                            >
                            <span class="[overflow-wrap:anywhere]">{{
                                citation.title
                            }}</span>
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            <span v-if="citation.version">{{
                                t('ui.assistant.version', {
                                    version: citation.version,
                                })
                            }}</span>
                            <span v-if="citation.version && citation.page">
                                ·
                            </span>
                            <span v-if="citation.page">{{
                                t('ui.assistant.page', { page: citation.page })
                            }}</span>
                        </p>
                        <mark
                            v-if="citation.snippet"
                            class="mt-2 line-clamp-4 rounded bg-mark px-1 text-[0.8125rem] text-foreground"
                            >{{ citation.snippet }}</mark
                        >
                        <Link
                            v-if="citation.url?.startsWith('/')"
                            :href="citation.url"
                            class="mt-2 inline-flex min-h-9 items-center text-[0.8125rem] font-semibold text-primary-strong hover:underline"
                            >{{ t('ui.assistant.open_document') }}</Link
                        >
                        <a
                            v-else-if="citation.url"
                            :href="citation.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-2 inline-flex min-h-9 items-center text-[0.8125rem] font-semibold text-primary-strong hover:underline"
                            >{{ t('ui.assistant.open_document') }}</a
                        >
                    </li>
                </ul>
            </div>
            <div class="rounded-card border bg-surface p-5 shadow-clay-sm">
                <h2 class="text-[0.9375rem] font-semibold">
                    {{ t('ui.assistant.how_title') }}
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        t('ui.assistant.threshold_explainer', {
                            percent: thresholdPercent,
                        })
                    }}
                </p>
                <ul class="mt-2 space-y-2 text-sm text-muted-foreground">
                    <li
                        v-for="key in ['how_1', 'how_2', 'how_3']"
                        :key="key"
                        class="flex gap-2"
                    >
                        <span
                            class="mt-2 size-1.5 shrink-0 rounded-full bg-mint"
                            aria-hidden="true"
                        />
                        {{ t(`ui.assistant.${key}`) }}
                    </li>
                </ul>
            </div>
            <p
                class="text-caption px-1 tabular-nums"
                data-test="assistant-usage"
            >
                {{ usageCaption }}
            </p>
        </aside>
    </div>

    <Sheet v-model:open="sourcesOpen">
        <SheetContent side="bottom" class="max-h-[80svh] overflow-y-auto">
            <SheetHeader>
                <SheetTitle>{{ t('ui.assistant.sources_panel') }}</SheetTitle>
                <SheetDescription>{{ usageCaption }}</SheetDescription>
            </SheetHeader>
            <ul class="space-y-3 px-4 pb-6">
                <li
                    v-for="citation in selectedCitations"
                    :key="`${citation.chunk_id}-${citation.index}`"
                    class="rounded-2xl bg-surface-2 p-3 text-sm"
                >
                    <p class="font-semibold">
                        {{ citation.index }}. {{ citation.title }}
                    </p>
                    <mark
                        v-if="citation.snippet"
                        class="mt-2 block rounded bg-mark px-1 text-[0.8125rem] text-foreground"
                        >{{ citation.snippet }}</mark
                    >
                    <a
                        v-if="citation.url"
                        :href="citation.url"
                        class="mt-2 inline-flex min-h-11 items-center text-[0.8125rem] font-semibold text-primary-strong"
                        >{{ t('ui.assistant.open_document') }}</a
                    >
                </li>
            </ul>
        </SheetContent>
    </Sheet>

    <Dialog v-model:open="confirmOpen">
        <DialogContent class="sm:max-w-md" data-test="assistant-action-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    confirming?.result.card?.title ?? confirming?.action.label
                }}</DialogTitle>
                <DialogDescription>{{
                    confirming?.result.message || t('ui.assistant.confirm_hint')
                }}</DialogDescription>
            </DialogHeader>
            <dl
                v-if="confirming?.result.card"
                class="grid grid-cols-[minmax(0,auto)_1fr] gap-x-4 gap-y-2 rounded-2xl bg-surface-2 p-4 text-sm"
            >
                <template
                    v-for="field in confirming.result.card.fields"
                    :key="field.label"
                >
                    <dt class="text-muted-foreground">{{ field.label }}</dt>
                    <dd class="font-medium [overflow-wrap:anywhere]">
                        {{ field.value }}
                    </dd>
                </template>
            </dl>
            <DialogFooter class="gap-2">
                <Button
                    variant="secondary"
                    :disabled="Boolean(pendingAction)"
                    data-test="assistant-action-cancel"
                    @click="cancelConfirm"
                    >{{ t('ui.common.cancel') }}</Button
                >
                <Button
                    :disabled="Boolean(pendingAction)"
                    data-test="assistant-action-confirm"
                    @click="confirmAction"
                >
                    <Spinner v-if="pendingAction" />
                    {{ t('ui.common.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

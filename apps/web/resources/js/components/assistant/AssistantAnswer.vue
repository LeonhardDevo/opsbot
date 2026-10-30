<script setup lang="ts">
import {
    Check,
    CircleCheck,
    ClipboardPlus,
    Clock,
    Copy,
    Cpu,
    Database,
    ExternalLink,
    Forward,
    ListPlus,
    MessageCircleQuestionMark,
    SearchX,
    Siren,
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import CitationChip from '@/components/assistant/CitationChip.vue';
import ConfidenceMeter from '@/components/assistant/ConfidenceMeter.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { linkCitationMarkers, plainToHtml } from '@/lib/citations';
import { t, tOr } from '@/lib/i18n';
import { sanitizeAnswer } from '@/lib/sanitize';
import type { ChatAnswer, ProposedAction } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        answer: ChatAnswer;
        threshold?: number | null;
        pendingAction?: string | null;
    }>(),
    { threshold: null, pendingAction: null },
);

const emit = defineEmits<{
    ask: [question: string];
    action: [action: ProposedAction];
    sources: [];
}>();

const fmt = useFormat();
const { copy, copied } = useClipboard({ legacy: true, copiedDuring: 1500 });

const html = computed(() => {
    const base = props.answer.answer_html?.trim()
        ? sanitizeAnswer(props.answer.answer_html)
        : plainToHtml(props.answer.answer_markdown ?? '');

    return linkCitationMarkers(base, props.answer.citations.length, (n) =>
        t('ui.assistant.source_n', { n }),
    );
});

const openCitation = ref<number | null>(null);

function onAnswerClick(event: MouseEvent): void {
    const target = (event.target as HTMLElement).closest<HTMLElement>(
        '[data-cite]',
    );

    if (target?.dataset.cite) {
        openCitation.value = Number(target.dataset.cite);
    }
}

const ICONS: Record<ProposedAction['type'], Component> = {
    create_incident: Siren,
    create_task: ListPlus,
    escalate: Forward,
    open_source: ExternalLink,
    ask: MessageCircleQuestionMark,
};

const actions = computed(() =>
    props.answer.actions
        .filter(
            (action) => action.type !== 'open_source' || Boolean(action.url),
        )
        .slice(0, 4),
);

const refusalText = computed(() =>
    props.answer.refusal_reason
        ? tOr(
              `ui.assistant.refusal.${props.answer.refusal_reason}`,
              t('ui.assistant.refused_banner'),
          )
        : t('ui.assistant.refused_banner'),
);

const mediumConfidence = computed(
    () => !props.answer.refused && props.answer.confidence_band === 'medium',
);

function run(action: ProposedAction): void {
    if (action.type === 'ask') {
        emit('ask', action.label);

        return;
    }

    emit('action', action);
}

function statusLabel(action: ProposedAction): string | null {
    return action.status === 'proposed'
        ? null
        : tOr(`ui.assistant.action_status.${action.status}`, action.status);
}
</script>

<template>
    <div
        class="space-y-3"
        data-test="assistant-answer"
        :data-refused="answer.refused ? 'true' : 'false'"
        :data-query-id="answer.query_id"
    >
        <p
            v-if="answer.refused"
            class="flex items-start gap-2 rounded-control bg-coral-soft px-3 py-2 text-sm font-medium text-coral-strong"
            role="note"
            data-test="assistant-refusal"
            :data-reason="answer.refusal_reason ?? ''"
        >
            <SearchX class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ refusalText }}
        </p>
        <p
            v-if="answer.answer_kind === 'tool_data'"
            class="inline-flex items-center gap-1.5 text-[0.8125rem] text-muted-foreground"
        >
            <Database class="size-3.5" aria-hidden="true" />
            {{ t('ui.assistant.tool_data') }}
        </p>
        <div class="answer-prose" @click="onAnswerClick" v-html="html" />

        <p
            v-for="(line, index) in answer.disclaimers"
            :key="index"
            class="text-sm text-muted-foreground italic"
            data-test="assistant-disclaimer"
        >
            {{ line }}
        </p>

        <div v-if="answer.citations.length" class="space-y-1.5">
            <div class="flex items-center justify-between gap-2">
                <p class="text-caption">{{ t('ui.assistant.sources') }}</p>
                <button
                    type="button"
                    class="text-xs font-semibold text-primary-strong underline-offset-4 hover:underline xl:hidden"
                    @click="emit('sources')"
                >
                    {{ t('ui.assistant.show_sources') }}
                </button>
            </div>
            <div class="flex flex-wrap gap-2">
                <CitationChip
                    v-for="citation in answer.citations"
                    :key="`${citation.chunk_id}-${citation.index}`"
                    :citation="citation"
                    :index="citation.index"
                    :open="openCitation === citation.index"
                    @update:open="
                        (value: boolean) =>
                            (openCitation = value ? citation.index : null)
                    "
                />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <ConfidenceMeter
                :value="answer.confidence"
                :band="answer.confidence_band"
                :threshold="threshold"
            />
            <span
                v-if="mediumConfidence"
                class="text-[0.8125rem] font-medium text-warning"
                >{{ t('ui.assistant.check_source') }}</span
            >
        </div>

        <div v-if="actions.length" class="flex flex-wrap gap-2">
            <template v-for="action in actions" :key="action.id">
                <Button
                    v-if="action.type === 'open_source' && action.url"
                    as-child
                    size="sm"
                    variant="secondary"
                >
                    <a
                        :href="action.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-test="assistant-action"
                        :data-action-type="action.type"
                    >
                        <ExternalLink aria-hidden="true" />
                        {{ action.label }}
                    </a>
                </Button>
                <Button
                    v-else
                    size="sm"
                    :variant="
                        action.type === 'escalate' && answer.refused
                            ? 'default'
                            : 'secondary'
                    "
                    :disabled="
                        action.status !== 'proposed' ||
                        pendingAction === action.id
                    "
                    data-test="assistant-action"
                    :data-action-type="action.type"
                    :data-status="action.status"
                    @click="run(action)"
                >
                    <Spinner v-if="pendingAction === action.id" />
                    <CircleCheck
                        v-else-if="action.status === 'executed'"
                        aria-hidden="true"
                    />
                    <component
                        :is="ICONS[action.type]"
                        v-else
                        aria-hidden="true"
                    />
                    {{ action.label }}
                    <span
                        v-if="statusLabel(action)"
                        class="text-xs text-muted-foreground"
                        >· {{ statusLabel(action) }}</span
                    >
                </Button>
            </template>
        </div>

        <div
            class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t pt-2 text-[0.8125rem] text-muted-foreground"
        >
            <span v-if="answer.model" class="inline-flex items-center gap-1">
                <Cpu class="size-3.5" aria-hidden="true" />
                <span class="font-mono">{{ answer.model }}</span>
            </span>
            <span class="inline-flex items-center gap-1">
                <Clock class="size-3.5" aria-hidden="true" />
                {{ fmt.duration(answer.latency_ms) }}
            </span>
            <span class="inline-flex items-center gap-1">
                <ClipboardPlus class="size-3.5" aria-hidden="true" />
                <span class="font-mono">#{{ answer.query_id }}</span>
            </span>
            <Button
                variant="ghost"
                size="icon-sm"
                class="ml-auto"
                :aria-label="
                    copied
                        ? t('ui.common.copied')
                        : t('ui.assistant.copy_answer')
                "
                @click="copy(answer.answer_markdown)"
            >
                <Check v-if="copied" class="text-success" />
                <Copy v-else />
            </Button>
        </div>
    </div>
</template>

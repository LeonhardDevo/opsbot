<script setup lang="ts">
import { Gauge } from '@lucide/vue';
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { confidenceLevel } from '@/lib/citations';
import { t } from '@/lib/i18n';
import type { ConfidenceBand } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        value: number | null | undefined;
        threshold?: number | null;
        band?: ConfidenceBand | null;
    }>(),
    { threshold: null, band: null },
);

const fmt = useFormat();
const level = computed(() => props.band ?? confidenceLevel(props.value));

const barClass = computed(() => {
    switch (level.value) {
        case 'high':
            return 'bg-mint-strong dark:bg-mint';
        case 'medium':
            return 'bg-warning';
        case 'low':
            return 'bg-destructive';
        default:
            return 'bg-muted-foreground';
    }
});

const percent = computed(() =>
    props.value !== null && props.value !== undefined
        ? fmt.value.percent(props.value)
        : '—',
);

const bandLabel = computed(() => t(`ui.assistant.band.${level.value}`));

const width = computed(() => `${Math.round((props.value ?? 0) * 100)}%`);

const tick = computed(() =>
    props.threshold !== null ? `${Math.round(props.threshold * 100)}%` : null,
);
</script>

<template>
    <span
        class="inline-flex items-center gap-2 text-[0.8125rem] text-muted-foreground"
        :title="t('ui.assistant.confidence_hint')"
        data-test="assistant-confidence"
        :data-level="level"
    >
        <Gauge class="size-3.5 shrink-0" aria-hidden="true" />
        <span
            role="meter"
            :aria-label="t('ui.assistant.confidence')"
            :aria-valuenow="value ?? undefined"
            aria-valuemin="0"
            aria-valuemax="1"
            :aria-valuetext="
                t('ui.assistant.confidence_valuetext', {
                    band: bandLabel,
                    percent,
                })
            "
            class="relative h-1.5 w-20 overflow-hidden rounded-full bg-neutral-soft"
        >
            <span
                :class="[
                    'absolute inset-y-0 left-0 rounded-full transition-[width] duration-500',
                    barClass,
                ]"
                :style="{ width }"
            />
            <span
                v-if="tick"
                class="absolute inset-y-0 w-0.5 bg-foreground/70"
                :style="{ left: tick }"
                aria-hidden="true"
            />
        </span>
        <span class="font-medium text-foreground tabular-nums"
            >{{ percent }} · {{ bandLabel }}</span
        >
    </span>
</template>

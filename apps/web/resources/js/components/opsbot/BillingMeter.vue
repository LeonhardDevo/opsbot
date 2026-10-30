<script setup lang="ts">
import { CircleAlert, CircleArrowUp } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';
import { meterLevel, meterRatio } from '@/lib/opsbot';
import { cn } from '@/lib/utils';
import type { LimitEntry, LimitKey } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        limitKey: LimitKey;
        label: string;
        entry: LimitEntry | null | undefined;
        icon?: Component | null;
        hint?: string | null;
        canUpgrade?: boolean;
        compact?: boolean;
    }>(),
    { icon: null, hint: null, canUpgrade: false, compact: false },
);

const emit = defineEmits<{ upgrade: [key: LimitKey] }>();

const fmt = useFormat();

const level = computed(() => meterLevel(props.entry));
const ratio = computed(() => meterRatio(props.entry));
const dataLevel = computed(() =>
    level.value === 'unlimited' ? 'ok' : level.value,
);

function format(value: number): string {
    return props.limitKey === 'storage_mb'
        ? fmt.value.bytes(value * 1024 * 1024)
        : fmt.value.number(value);
}

const valueText = computed(() => {
    const entry = props.entry;

    if (!entry) {
        return '—';
    }

    return entry.limit === null
        ? t('ui.billing.meter.unlimited_value', { used: format(entry.used) })
        : t('ui.billing.meter.value', {
              used: format(entry.used),
              limit: format(entry.limit),
          });
});

const barClass = computed(() => {
    switch (level.value) {
        case 'full':
            return 'bg-coral';
        case 'warn':
            return 'bg-sun';
        default:
            return 'bg-primary';
    }
});
</script>

<template>
    <div
        :class="cn('grid gap-2', compact ? '' : 'py-3')"
        data-test="billing-meter"
        :data-limit-key="limitKey"
        :data-level="dataLevel"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="flex min-w-0 items-center gap-2 font-medium">
                <component
                    :is="icon"
                    v-if="icon"
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                {{ label }}
            </span>
            <span class="ml-auto text-sm tabular-nums">{{ valueText }}</span>
        </div>
        <div
            v-if="entry && entry.limit !== null"
            role="meter"
            :aria-label="label"
            :aria-valuemin="0"
            :aria-valuemax="entry.limit"
            :aria-valuenow="Math.min(entry.used, entry.limit)"
            :aria-valuetext="valueText"
            class="h-2 overflow-hidden rounded-chip bg-neutral-soft"
        >
            <div
                :class="['h-full rounded-chip transition-[width]', barClass]"
                :style="{
                    width: `${Math.max(ratio * 100, entry.used > 0 ? 2 : 0)}%`,
                }"
            />
        </div>
        <div
            v-if="hint || level === 'warn' || level === 'full'"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
        >
            <span v-if="hint" class="min-w-0 text-pretty">{{ hint }}</span>
            <span
                v-if="level === 'full'"
                class="inline-flex items-center gap-1 font-semibold text-coral-strong"
            >
                <CircleAlert class="size-3.5" aria-hidden="true" />
                {{ t('ui.billing.meter.full') }}
            </span>
            <span
                v-else-if="level === 'warn'"
                class="inline-flex items-center gap-1 font-semibold text-sun-strong"
            >
                <CircleAlert class="size-3.5" aria-hidden="true" />
                {{ t('ui.billing.meter.warn') }}
            </span>
            <button
                v-if="canUpgrade && (level === 'warn' || level === 'full')"
                type="button"
                class="ml-auto inline-flex min-h-11 items-center gap-1 font-semibold text-primary-strong underline-offset-4 hover:underline sm:min-h-0"
                data-test="billing-meter-upgrade"
                @click="emit('upgrade', limitKey)"
            >
                <CircleArrowUp class="size-3.5" aria-hidden="true" />
                {{ t('ui.billing.raise_limit') }}
            </button>
        </div>
    </div>
</template>

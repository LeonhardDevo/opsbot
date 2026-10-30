<script setup lang="ts">
import {
    CircleAlert,
    FileText,
    Gauge,
    HardDrive,
    Infinity as InfinityIcon,
    MessagesSquare,
    TriangleAlert,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { usageState } from '@/lib/billing';
import { t } from '@/lib/i18n';
import type { UsageEntry } from '@/types/models';

const props = withDefaults(
    defineProps<{
        label: string;
        entry: UsageEntry;
        limitKey?: string | null;
        format?: (value: number) => string;
    }>(),
    { limitKey: null, format: undefined },
);

const fmt = useFormat();
const state = computed(() => usageState(props.entry));
const show = (value: number) =>
    props.format ? props.format(value) : fmt.value.number(value);

const icons: Record<string, Component> = {
    seats: Users,
    documents: FileText,
    storage_mb: HardDrive,
    ai_queries_monthly: MessagesSquare,
};

const icon = computed(() => icons[props.limitKey ?? ''] ?? Gauge);

const level = computed(() => {
    switch (state.value.level) {
        case 'critical':
            return 'full';
        case 'warning':
            return 'warn';
        default:
            return 'ok';
    }
});

const barClass = computed(() => {
    switch (state.value.level) {
        case 'critical':
            return 'bg-coral';
        case 'warning':
            return 'bg-sun';
        default:
            return 'bg-primary';
    }
});

const width = computed(() => `${Math.max(2, (state.value.ratio ?? 0) * 100)}%`);
</script>

<template>
    <div
        class="space-y-2"
        data-test="billing-meter"
        :data-limit-key="limitKey ?? undefined"
        :data-level="level"
    >
        <div class="flex items-center justify-between gap-3 text-sm">
            <span class="flex min-w-0 items-center gap-2 font-medium">
                <component
                    :is="icon"
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <span class="truncate">{{ label }}</span>
            </span>
            <span class="shrink-0 text-muted-foreground tabular-nums">
                <template v-if="state.level === 'unlimited'">
                    {{ show(entry.used) }} ·
                    <span class="inline-flex items-center gap-1">
                        <InfinityIcon class="size-3.5" aria-hidden="true" />
                        {{ t('ui.billing.unlimited') }}
                    </span>
                </template>
                <template v-else>
                    {{ show(entry.used) }} / {{ show(entry.limit ?? 0) }}
                </template>
            </span>
        </div>
        <div
            v-if="state.ratio !== null"
            role="meter"
            :aria-label="label"
            :aria-valuenow="entry.used"
            :aria-valuemin="0"
            :aria-valuemax="entry.limit ?? undefined"
            :aria-valuetext="`${show(entry.used)} / ${show(entry.limit ?? 0)}`"
            class="h-2 overflow-hidden rounded-full bg-neutral-soft"
        >
            <div
                :class="[
                    'h-full rounded-full transition-[width] duration-500 ease-out',
                    barClass,
                ]"
                :style="{ width }"
            />
        </div>
        <div
            v-if="state.level === 'critical' || state.level === 'warning'"
            class="flex flex-wrap items-center justify-between gap-2"
        >
            <p
                :class="[
                    'inline-flex items-center gap-1.5 text-[0.8125rem] font-medium',
                    state.level === 'critical'
                        ? 'text-coral-strong'
                        : 'text-sun-strong',
                ]"
            >
                <component
                    :is="
                        state.level === 'critical' ? CircleAlert : TriangleAlert
                    "
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{
                    state.level === 'critical'
                        ? t('ui.billing.limit_reached')
                        : t('ui.billing.limit_near', {
                              remaining: show(state.remaining ?? 0),
                          })
                }}
            </p>
            <slot name="action" />
        </div>
    </div>
</template>

<script setup lang="ts">
import { TrendingDown, TrendingUp } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import NumberTicker from '@/components/fx/NumberTicker.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useFormat } from '@/composables/useFormat';
import { t } from '@/lib/i18n';

const props = withDefaults(
    defineProps<{
        label: string;
        value: number | string | null;
        delta?: number | null;
        invertDelta?: boolean;
        icon?: Component;
        format?: (value: number) => string;
        hint?: string | null;
        loading?: boolean;
        tone?: 'primary' | 'ai' | 'warning' | 'info';
    }>(),
    {
        delta: null,
        invertDelta: false,
        icon: undefined,
        format: undefined,
        hint: null,
        loading: false,
        tone: 'primary',
    },
);

const fmt = useFormat();

const deltaGood = computed(() => {
    if (props.delta === null || props.delta === undefined) {
        return null;
    }

    return props.invertDelta ? props.delta <= 0 : props.delta >= 0;
});

const toneClass = {
    primary: 'bg-primary-soft text-primary-soft-foreground',
    ai: 'bg-ai-soft text-ai-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    info: 'bg-info-soft text-info-soft-foreground',
} as const;
</script>

<template>
    <article
        class="group relative flex flex-col gap-3 overflow-hidden rounded-card border bg-card p-5 shadow-clay"
    >
        <div class="flex items-center justify-between gap-3">
            <p class="truncate text-sm font-medium text-muted-foreground">
                {{ label }}
            </p>
            <span
                v-if="icon"
                :class="[
                    'inline-flex size-9 items-center justify-center rounded-xl',
                    toneClass[tone],
                ]"
                aria-hidden="true"
            >
                <component :is="icon" class="size-4" />
            </span>
        </div>

        <template v-if="loading">
            <Skeleton class="h-8 w-28" />
            <Skeleton class="h-4 w-20" />
        </template>
        <template v-else>
            <p class="text-kpi">
                <NumberTicker
                    v-if="typeof value === 'number'"
                    :value="value"
                    :format="format ?? ((n: number) => fmt.number(n))"
                />
                <span
                    v-else
                    class="block truncate py-1 font-mono text-lg leading-tight font-semibold tracking-normal"
                    :title="value ?? undefined"
                    >{{ value ?? '—' }}</span
                >
            </p>
            <div class="flex min-h-5 items-center gap-2 text-xs">
                <span
                    v-if="deltaGood !== null && delta !== null"
                    :class="[
                        'inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 font-semibold',
                        deltaGood
                            ? 'bg-success-soft text-success-soft-foreground'
                            : 'bg-destructive-soft text-destructive-soft-foreground',
                    ]"
                >
                    <component
                        :is="delta >= 0 ? TrendingUp : TrendingDown"
                        class="size-3"
                        aria-hidden="true"
                    />
                    {{ delta >= 0 ? '+' : '−'
                    }}{{ fmt.percent(Math.abs(delta), 1) }}
                    <span class="sr-only">{{
                        delta >= 0 ? t('ui.kpi.increase') : t('ui.kpi.decrease')
                    }}</span>
                </span>
                <span v-if="hint" class="truncate text-muted-foreground">{{
                    hint
                }}</span>
            </div>
        </template>
    </article>
</template>

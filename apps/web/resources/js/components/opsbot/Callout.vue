<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        tone?: 'info' | 'sun' | 'coral' | 'mint' | 'neutral';
        icon?: Component | null;
        title?: string | null;
        class?: string;
    }>(),
    { tone: 'info', icon: null, title: null, class: '' },
);

const tones = {
    info: 'bg-primary-soft',
    sun: 'bg-sun-soft',
    coral: 'bg-coral-soft',
    mint: 'bg-mint-soft',
    neutral: 'bg-surface-2',
} as const;

const iconTones = {
    info: 'text-primary-strong',
    sun: 'text-sun-strong',
    coral: 'text-coral-strong',
    mint: 'text-mint-strong',
    neutral: 'text-muted-foreground',
} as const;
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col gap-3 rounded-card px-4 py-3.5 text-foreground sm:flex-row sm:items-center',
                tones[props.tone],
                props.class,
            )
        "
    >
        <div class="flex min-w-0 flex-1 items-start gap-3">
            <component
                :is="icon"
                v-if="icon"
                :class="['mt-0.5 size-5 shrink-0', iconTones[props.tone]]"
                aria-hidden="true"
            />
            <div class="min-w-0 space-y-0.5 text-sm">
                <p v-if="title" class="font-semibold">{{ title }}</p>
                <div class="text-pretty"><slot /></div>
            </div>
        </div>
        <div
            v-if="$slots.actions"
            class="flex shrink-0 flex-wrap items-center gap-2"
        >
            <slot name="actions" />
        </div>
    </div>
</template>

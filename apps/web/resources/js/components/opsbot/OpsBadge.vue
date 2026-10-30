<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

export type OpsTone =
    | 'primary'
    | 'sun'
    | 'coral'
    | 'success'
    | 'mint'
    | 'neutral';

const props = withDefaults(
    defineProps<{
        label: string;
        tone?: OpsTone;
        icon?: Component | null;
        dashed?: boolean;
        strike?: boolean;
        mono?: boolean;
        class?: string;
    }>(),
    {
        tone: 'neutral',
        icon: null,
        dashed: false,
        strike: false,
        mono: false,
        class: '',
    },
);

const tones: Record<OpsTone, string> = {
    primary: 'bg-primary-soft text-primary-strong border-transparent',
    sun: 'bg-sun-soft text-sun-strong border-transparent',
    coral: 'bg-coral-soft text-coral-strong border-[color-mix(in_oklch,var(--incident-coral-strong)_35%,transparent)]',
    success: 'bg-success-soft text-success border-transparent',
    mint: 'bg-mint-soft text-mint-strong border-transparent',
    neutral: 'bg-neutral-soft text-neutral-foreground border-transparent',
};
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex h-6 max-w-full shrink-0 items-center gap-1 rounded-chip border px-2 text-xs font-semibold whitespace-nowrap',
                tones[props.tone],
                props.dashed ? 'border-dashed border-input' : '',
                props.class,
            )
        "
    >
        <component
            :is="icon"
            v-if="icon"
            class="size-3.5 shrink-0"
            aria-hidden="true"
        />
        <span
            :class="[
                'truncate',
                strike ? 'line-through' : '',
                mono ? 'font-mono font-medium' : '',
            ]"
            >{{ label }}</span
        >
    </span>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Bot, KeyRound, LoaderCircle, Unplug } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Href } from '@/types/navigation';

export type BotState =
    | 'active'
    | 'connecting'
    | 'disconnecting'
    | 'invalid_token'
    | 'revoked'
    | 'disconnected';

const props = withDefaults(
    defineProps<{
        username: string | null;
        state?: BotState | null;
        href?: Href | null;
        class?: string;
    }>(),
    { state: null, href: null, class: '' },
);

const tone = computed(() => {
    switch (props.state) {
        case 'active':
            return 'bg-success-soft text-success';
        case 'connecting':
        case 'disconnecting':
            return 'bg-primary-soft text-primary-strong';
        case 'invalid_token':
        case 'revoked':
            return 'bg-coral-soft text-coral-strong';
        case 'disconnected':
            return 'bg-neutral-soft text-neutral-foreground';
        default:
            return 'bg-surface text-foreground border border-border';
    }
});

const icon = computed<Component>(() => {
    switch (props.state) {
        case 'connecting':
        case 'disconnecting':
            return LoaderCircle;
        case 'invalid_token':
        case 'revoked':
            return KeyRound;
        case 'disconnected':
            return Unplug;
        default:
            return Bot;
    }
});

const label = computed(() =>
    props.state ? t(`ui.bot.state.${props.state}`) : t('ui.bot.label'),
);

const spinning = computed(
    () => props.state === 'connecting' || props.state === 'disconnecting',
);
</script>

<template>
    <component
        :is="href ? Link : 'span'"
        :href="href ?? undefined"
        :class="
            cn(
                'inline-flex h-9 max-w-full min-w-0 items-center gap-2 rounded-chip px-3 text-sm font-medium transition-colors',
                href ? 'hover:shadow-clay-sm pointer-coarse:h-11' : '',
                tone,
                props.class,
            )
        "
        data-test="shell-bot-pill"
        :data-state="state ?? 'unknown'"
    >
        <span class="relative inline-flex size-2 shrink-0" aria-hidden="true">
            <span
                v-if="state === 'active'"
                class="absolute inset-0 rounded-full bg-success opacity-60 motion-safe:animate-ping"
            />
            <span class="relative size-2 rounded-full bg-current" />
        </span>
        <component
            :is="icon"
            :class="[
                'size-4 shrink-0',
                spinning ? 'motion-safe:animate-spin' : '',
            ]"
            aria-hidden="true"
        />
        <span class="hidden truncate xl:inline">{{ label }}</span>
        <span
            v-if="username"
            class="truncate font-mono text-[0.8125rem] font-medium"
            >@{{ username }}</span
        >
        <span aria-live="polite" class="sr-only">{{ label }}</span>
    </component>
</template>

<script setup lang="ts">
import type { Component } from 'vue';

export type IllustrationScene = 'chat' | 'documents' | 'people' | 'search';

withDefaults(
    defineProps<{
        icon: Component;
        tone?: 'primary' | 'ai' | 'warning' | 'info' | 'muted';
        scene?: IllustrationScene;
        size?: 'sm' | 'md';
    }>(),
    { tone: 'primary', scene: 'chat', size: 'md' },
);

const tones = {
    primary: 'bg-primary text-primary-foreground',
    ai: 'bg-mint text-mint-foreground',
    warning: 'bg-sun text-sun-foreground',
    info: 'bg-primary text-primary-foreground',
    muted: 'bg-neutral-soft text-neutral-foreground',
} as const;
</script>

<template>
    <div
        aria-hidden="true"
        :class="['relative mx-auto', size === 'sm' ? 'h-24 w-32' : 'h-32 w-40']"
        data-test="empty-illustration"
    >
        <svg viewBox="0 0 160 128" class="absolute inset-0 size-full">
            <circle cx="40" cy="40" r="30" class="fill-primary-soft" />
            <circle cx="126" cy="86" r="26" class="fill-mint-soft" />
            <circle cx="132" cy="22" r="10" class="fill-sun-soft" />

            <g v-if="scene === 'chat'" class="motion-safe:animate-float">
                <rect
                    x="20"
                    y="30"
                    width="84"
                    height="34"
                    rx="14"
                    class="fill-surface stroke-border"
                    stroke-width="1.5"
                />
                <rect
                    x="32"
                    y="42"
                    width="46"
                    height="5"
                    rx="2.5"
                    class="fill-muted-foreground/30"
                />
                <rect
                    x="32"
                    y="52"
                    width="30"
                    height="5"
                    rx="2.5"
                    class="fill-muted-foreground/20"
                />
                <rect
                    x="58"
                    y="72"
                    width="80"
                    height="30"
                    rx="14"
                    class="fill-primary-soft stroke-primary"
                    stroke-width="1.5"
                />
                <rect
                    x="70"
                    y="83"
                    width="40"
                    height="5"
                    rx="2.5"
                    class="fill-primary/50"
                />
                <rect
                    x="114"
                    y="83"
                    width="12"
                    height="5"
                    rx="2.5"
                    class="fill-primary/30"
                />
            </g>

            <g
                v-else-if="scene === 'documents'"
                class="motion-safe:animate-float"
            >
                <rect
                    x="44"
                    y="20"
                    width="62"
                    height="80"
                    rx="10"
                    class="fill-surface-2 stroke-border"
                    stroke-width="1.5"
                    transform="rotate(-8 75 60)"
                />
                <rect
                    x="52"
                    y="24"
                    width="62"
                    height="80"
                    rx="10"
                    class="fill-surface stroke-border"
                    stroke-width="1.5"
                />
                <rect
                    x="62"
                    y="38"
                    width="34"
                    height="5"
                    rx="2.5"
                    class="fill-muted-foreground/35"
                />
                <rect
                    x="62"
                    y="50"
                    width="42"
                    height="4"
                    rx="2"
                    class="fill-muted-foreground/20"
                />
                <rect
                    x="62"
                    y="60"
                    width="38"
                    height="4"
                    rx="2"
                    class="fill-muted-foreground/20"
                />
                <rect
                    x="60"
                    y="69"
                    width="44"
                    height="8"
                    rx="2"
                    class="fill-mark"
                />
                <rect
                    x="62"
                    y="84"
                    width="26"
                    height="4"
                    rx="2"
                    class="fill-muted-foreground/20"
                />
            </g>

            <g v-else-if="scene === 'people'" class="motion-safe:animate-float">
                <circle
                    cx="62"
                    cy="48"
                    r="12"
                    class="fill-surface stroke-border"
                    stroke-width="1.5"
                />
                <rect
                    x="42"
                    y="64"
                    width="40"
                    height="32"
                    rx="16"
                    class="fill-primary-soft stroke-primary"
                    stroke-width="1.5"
                />
                <circle
                    cx="100"
                    cy="54"
                    r="10"
                    class="fill-surface stroke-border"
                    stroke-width="1.5"
                />
                <rect
                    x="84"
                    y="68"
                    width="34"
                    height="28"
                    rx="14"
                    class="fill-mint-soft stroke-mint-strong"
                    stroke-width="1.5"
                />
            </g>

            <g v-else class="motion-safe:animate-float">
                <rect
                    x="28"
                    y="30"
                    width="92"
                    height="58"
                    rx="14"
                    class="fill-surface stroke-border"
                    stroke-width="1.5"
                />
                <rect
                    x="40"
                    y="44"
                    width="50"
                    height="5"
                    rx="2.5"
                    class="fill-muted-foreground/30"
                />
                <rect
                    x="40"
                    y="56"
                    width="66"
                    height="4"
                    rx="2"
                    class="fill-muted-foreground/20"
                />
                <rect
                    x="40"
                    y="66"
                    width="40"
                    height="4"
                    rx="2"
                    class="fill-muted-foreground/20"
                />
                <circle
                    cx="108"
                    cy="88"
                    r="14"
                    class="fill-none stroke-primary"
                    stroke-width="3"
                />
                <path
                    d="M118 98 L128 108"
                    class="stroke-primary"
                    stroke-width="4"
                    stroke-linecap="round"
                />
            </g>
        </svg>
        <span
            :class="[
                'absolute inline-flex items-center justify-center rounded-2xl ring-4 shadow-clay ring-background',
                size === 'sm'
                    ? '-bottom-1 left-1 size-9'
                    : '-bottom-1 left-2 size-11',
                tones[tone],
            ]"
        >
            <component
                :is="icon"
                :class="size === 'sm' ? 'size-4' : 'size-5'"
                :stroke-width="1.75"
            />
        </span>
    </div>
</template>

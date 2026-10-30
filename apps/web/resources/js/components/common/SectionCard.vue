<script setup lang="ts">
import type { Component } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        title?: string | null;
        description?: string | null;
        icon?: Component;
        tone?: 'default' | 'danger';
        flush?: boolean;
        class?: string;
    }>(),
    {
        title: null,
        description: null,
        icon: undefined,
        tone: 'default',
        flush: false,
        class: '',
    },
);
</script>

<template>
    <section
        :class="
            cn(
                'overflow-hidden rounded-card border bg-card text-card-foreground shadow-clay',
                tone === 'danger'
                    ? 'border-[color-mix(in_oklch,var(--incident-coral-strong)_35%,transparent)]'
                    : '',
                $props.class,
            )
        "
    >
        <header
            v-if="title || $slots.actions"
            class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4 sm:px-5 sm:pt-5"
        >
            <div class="flex min-w-[min(100%,14rem)] flex-1 items-start gap-3">
                <span
                    v-if="icon"
                    :class="[
                        'mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-xl',
                        tone === 'danger'
                            ? 'bg-coral-soft text-coral-strong'
                            : 'bg-surface-2 text-primary-strong',
                    ]"
                    aria-hidden="true"
                >
                    <component :is="icon" class="size-[1.125rem]" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-h2">
                        {{ title }}
                    </h2>
                    <p
                        v-if="description"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <div :class="flush ? 'pt-3' : 'px-4 py-4 sm:px-5 sm:py-5'">
            <slot />
        </div>
        <footer
            v-if="$slots.footer"
            class="flex flex-col-reverse gap-2 border-t bg-surface-2/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-5"
        >
            <slot name="footer" />
        </footer>
    </section>
</template>

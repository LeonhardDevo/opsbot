<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    title: string;
    description?: string | null;
    icon?: Component;
    eyebrow?: string | null;
}>();
</script>

<template>
    <header
        class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        data-test="page-header"
    >
        <div class="flex min-w-0 items-start gap-3.5">
            <span
                v-if="icon"
                class="mt-0.5 hidden size-11 shrink-0 items-center justify-center rounded-2xl bg-primary-soft text-primary-strong shadow-clay-sm sm:inline-flex"
                aria-hidden="true"
            >
                <component :is="icon" class="size-5" :stroke-width="1.75" />
            </span>
            <div class="min-w-0 space-y-1">
                <p v-if="eyebrow" class="text-caption">
                    {{ eyebrow }}
                </p>
                <h1 class="text-h1 text-balance">
                    {{ title }}
                </h1>
                <p
                    v-if="description"
                    class="max-w-2xl text-[0.9375rem] text-pretty text-muted-foreground"
                >
                    {{ description }}
                </p>
                <div
                    v-if="$slots.meta"
                    class="flex flex-wrap items-center gap-2 pt-1"
                >
                    <slot name="meta" />
                </div>
            </div>
        </div>
        <div
            v-if="$slots.actions"
            class="flex shrink-0 flex-wrap items-center gap-2"
        >
            <slot name="actions" />
        </div>
    </header>
</template>

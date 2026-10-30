<script setup lang="ts">
import type { Component } from 'vue';
import EmptyIllustration from '@/components/brand/EmptyIllustration.vue';
import type { IllustrationScene } from '@/components/brand/EmptyIllustration.vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        icon: Component;
        title: string;
        description?: string | null;
        tone?: 'primary' | 'ai' | 'warning' | 'info' | 'muted';
        scene?: IllustrationScene;
        compact?: boolean;
        class?: string;
    }>(),
    {
        description: null,
        tone: 'primary',
        scene: 'chat',
        compact: false,
        class: '',
    },
);
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col items-center justify-center text-center',
                compact ? 'gap-3 px-4 py-8' : 'gap-4 px-6 py-12',
                $props.class,
            )
        "
        data-test="empty-state"
    >
        <EmptyIllustration
            :icon="icon"
            :tone="tone"
            :scene="scene"
            :size="compact ? 'sm' : 'md'"
        />
        <div class="max-w-sm space-y-1.5">
            <h3 class="text-h2 text-balance">
                {{ title }}
            </h3>
            <p
                v-if="description"
                class="text-[0.9375rem] text-pretty text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap justify-center gap-2">
            <slot />
        </div>
    </div>
</template>

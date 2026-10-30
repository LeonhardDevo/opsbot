<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{ keys: Array<{ label: string; icon?: Component }> }>();
</script>

<template>
    <div class="grid gap-1.5">
        <span
            v-for="key in keys"
            :key="key.label"
            class="inline-flex h-9 items-center justify-center gap-1.5 rounded-control border bg-surface px-3 text-[0.8125rem] font-semibold text-primary-strong shadow-clay-sm"
        >
            <component
                :is="key.icon"
                v-if="key.icon"
                class="size-4"
                aria-hidden="true"
            />
            {{ key.label }}
        </span>
    </div>
</template>

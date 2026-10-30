<script setup lang="ts">
withDefaults(
    defineProps<{ index: number; title: string; page?: string | null }>(),
    { page: null },
);
</script>

<template>
    <span
        class="inline-flex h-7 max-w-[260px] items-center gap-1.5 rounded-chip border bg-surface-2 py-1 pr-2.5 pl-1 text-[0.8125rem] font-medium text-primary-strong"
    >
        <span
            class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-primary font-mono text-xs font-semibold text-primary-foreground"
            >{{ index }}</span
        >
        <span class="truncate">{{ title }}</span>
        <span v-if="page" class="shrink-0">· {{ page }}</span>
    </span>
</template>

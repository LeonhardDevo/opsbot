<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';

withDefaults(defineProps<{ showName?: boolean; subtitle?: string | null }>(), {
    showName: true,
    subtitle: null,
});

const page = usePage();
</script>

<template>
    <span class="flex min-w-0 items-center gap-2.5">
        <AppLogoMark class="size-9 shrink-0" />
        <span v-if="showName" class="grid min-w-0 text-left leading-tight">
            <span
                class="truncate font-display text-[1.05rem] font-semibold tracking-tight"
            >
                {{ page.props.name }}
            </span>
            <span
                v-if="subtitle"
                class="truncate text-xs text-muted-foreground"
            >
                {{ subtitle }}
            </span>
        </span>
    </span>
</template>

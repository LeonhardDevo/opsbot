<script setup lang="ts">
import { SignalHigh, SignalLow, SignalMedium } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';

const props = defineProps<{ severity: 'low' | 'medium' | 'high' }>();

const style = computed(() => {
    switch (props.severity) {
        case 'high':
            return {
                icon: SignalHigh,
                class: 'bg-coral-soft text-coral-strong border-[color-mix(in_oklch,var(--incident-coral-strong)_35%,transparent)]',
            };
        case 'medium':
            return {
                icon: SignalMedium,
                class: 'bg-sun-soft text-sun-strong border-transparent',
            };
        default:
            return {
                icon: SignalLow,
                class: 'bg-neutral-soft text-neutral-foreground border-transparent',
            };
    }
});
</script>

<template>
    <span
        :class="[
            'inline-flex h-6 shrink-0 items-center gap-1 rounded-chip border px-2 text-xs font-semibold whitespace-nowrap',
            style.class,
        ]"
        :data-severity="severity"
    >
        <component :is="style.icon" class="size-3.5" aria-hidden="true" />
        {{ t(`ui.chat.severity.${severity}`) }}
    </span>
</template>

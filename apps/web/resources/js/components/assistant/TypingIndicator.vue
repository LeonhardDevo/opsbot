<script setup lang="ts">
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ label?: string | null }>(), { label: null });
</script>

<template>
    <div
        class="inline-flex items-center gap-2.5"
        role="status"
        aria-live="polite"
    >
        <span
            class="inline-flex items-center gap-1 motion-reduce:hidden"
            aria-hidden="true"
        >
            <span
                class="size-1.5 animate-typing rounded-full bg-muted-foreground"
            />
            <span
                class="size-1.5 animate-typing rounded-full bg-muted-foreground [animation-delay:160ms]"
            />
            <span
                class="size-1.5 animate-typing rounded-full bg-muted-foreground [animation-delay:320ms]"
            />
        </span>
        <span class="text-sm text-muted-foreground">{{
            label ?? t('ui.assistant.thinking')
        }}</span>
    </div>
</template>

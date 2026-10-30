<script setup lang="ts">
import { ChevronLeft } from '@lucide/vue';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import TelegramIcon from '@/components/integrations/TelegramIcon.vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        title: string;
        subtitle: string;
        compact?: boolean;
        class?: string;
    }>(),
    { compact: false, class: '' },
);
</script>

<template>
    <div
        :class="
            cn(
                'relative w-full rounded-[2.25rem] bg-phone-frame p-2.5 shadow-clay-lg',
                compact ? 'max-w-[340px]' : 'max-w-[380px]',
                $props.class,
            )
        "
    >
        <div
            class="flex flex-col overflow-hidden rounded-[1.75rem] bg-phone-screen"
            :class="compact ? 'min-h-[300px]' : 'min-h-[560px]'"
        >
            <div
                class="flex items-center gap-2.5 border-b bg-surface px-3 py-2.5"
            >
                <ChevronLeft
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                <AppLogoMark class="size-9 shrink-0" />
                <div class="grid min-w-0 flex-1 leading-tight">
                    <span class="truncate text-sm font-semibold">{{
                        title
                    }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{
                        subtitle
                    }}</span>
                </div>
                <TelegramIcon class="size-5 shrink-0" />
            </div>
            <div class="flex flex-1 flex-col justify-end gap-2.5 p-3">
                <slot />
            </div>
        </div>
    </div>
</template>

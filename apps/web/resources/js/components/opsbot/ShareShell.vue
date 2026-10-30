<script setup lang="ts">
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';

withDefaults(defineProps<{ caption?: string | null }>(), { caption: null });
</script>

<template>
    <div class="min-h-svh bg-background">
        <header
            class="viewer-print-hide sticky top-0 z-10 flex h-14 items-center gap-2.5 border-b bg-surface/95 px-4 backdrop-blur-md"
        >
            <AppLogoMark class="size-8 shrink-0" />
            <span
                v-if="caption"
                class="min-w-0 truncate text-sm font-medium text-muted-foreground"
                >{{ caption }}</span
            >
            <div class="ml-auto flex items-center gap-1">
                <LocaleSwitcher compact :test-id="null" />
                <ThemeToggle :test-id="null" />
            </div>
        </header>
        <main
            id="main-content"
            class="mx-auto w-full max-w-[760px] px-4 py-6 sm:px-8 sm:py-8"
        >
            <slot />
        </main>
    </div>
</template>

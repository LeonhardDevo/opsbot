<script setup lang="ts">
import { BookOpenText, CircleCheck, Forward } from '@lucide/vue';
import TelegramPreview from '@/components/chat/TelegramPreview.vue';
import StoryScene from '@/components/landing/StoryScene.vue';
import { t } from '@/lib/i18n';

const points = [
    { key: 'approved', icon: CircleCheck },
    { key: 'sources', icon: BookOpenText },
    { key: 'honest', icon: Forward },
] as const;
</script>

<template>
    <div
        class="relative flex h-full flex-col justify-between gap-10 overflow-hidden rounded-dialog bg-surface-2 p-10 xl:p-12"
        data-test="auth-showcase"
    >
        <div
            class="pointer-events-none absolute -top-24 -right-24 size-80 rounded-full bg-[var(--glow-sky)] blur-3xl"
            aria-hidden="true"
        />
        <div
            class="pointer-events-none absolute -bottom-28 -left-16 size-80 rounded-full bg-[var(--glow-mint)] blur-3xl"
            aria-hidden="true"
        />
        <div class="relative max-w-md space-y-4">
            <p class="text-display-m">{{ t('marketing.auth.headline') }}</p>
            <p class="text-[0.9375rem] text-muted-foreground">
                {{ t('marketing.auth.subhead') }}
            </p>
            <ul class="space-y-2 pt-1">
                <li
                    v-for="point in points"
                    :key="point.key"
                    class="flex items-center gap-2.5 text-[0.9375rem] font-medium"
                >
                    <span
                        class="inline-flex size-7 items-center justify-center rounded-full bg-mint-soft text-mint-strong"
                        aria-hidden="true"
                    >
                        <component :is="point.icon" class="size-4" />
                    </span>
                    {{ t(`marketing.auth.points.${point.key}`) }}
                </li>
            </ul>
        </div>
        <figure class="relative mx-auto w-full max-w-[360px]">
            <figcaption class="sr-only">
                {{ t('marketing.auth.preview_label') }}.
                {{ t('marketing.demo.summary') }}
            </figcaption>
            <div aria-hidden="true">
                <TelegramPreview
                    compact
                    :title="t('marketing.demo.bot_title')"
                    :subtitle="t('marketing.demo.bot_subtitle')"
                >
                    <StoryScene scene="refusal" dense />
                </TelegramPreview>
            </div>
        </figure>
    </div>
</template>

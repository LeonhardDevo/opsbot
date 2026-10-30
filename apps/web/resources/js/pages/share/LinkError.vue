<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ShareShell from '@/components/opsbot/ShareShell.vue';
import { t } from '@/lib/i18n';

defineProps<{ reason: 'expired' | 'forbidden' | 'not_found' }>();
</script>

<template>
    <Head :title="t(`ui.viewer.errors.${reason}.title`)">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <ShareShell>
        <section
            class="mx-auto mt-6 flex max-w-md flex-col items-center gap-4 rounded-card border bg-surface p-8 text-center shadow-clay"
            data-test="viewer-error"
            :data-reason="reason"
        >
            <svg viewBox="0 0 160 120" class="h-28 w-36" aria-hidden="true">
                <circle cx="52" cy="58" r="40" class="fill-primary-soft" />
                <circle
                    cx="52"
                    cy="58"
                    r="26"
                    class="fill-surface stroke-primary"
                    stroke-width="3"
                />
                <line
                    x1="52"
                    y1="58"
                    x2="52"
                    y2="42"
                    class="stroke-primary"
                    stroke-width="3"
                    stroke-linecap="round"
                />
                <line
                    x1="52"
                    y1="58"
                    x2="64"
                    y2="64"
                    class="stroke-primary"
                    stroke-width="3"
                    stroke-linecap="round"
                />
                <rect
                    x="96"
                    y="40"
                    width="44"
                    height="16"
                    rx="8"
                    class="fill-sun-soft stroke-border"
                    stroke-width="2"
                    transform="rotate(-24 118 48)"
                />
                <rect
                    x="104"
                    y="70"
                    width="44"
                    height="16"
                    rx="8"
                    class="fill-mint-soft stroke-border"
                    stroke-width="2"
                    transform="rotate(18 126 78)"
                />
            </svg>
            <h1 class="text-h1 text-balance">
                {{ t(`ui.viewer.errors.${reason}.title`) }}
            </h1>
            <p class="text-sm text-pretty text-muted-foreground">
                {{ t(`ui.viewer.errors.${reason}.body`) }}
            </p>
        </section>
    </ShareShell>
</template>

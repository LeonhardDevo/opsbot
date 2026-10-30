<script setup lang="ts">
import BubbleScene from '@/components/marketing/BubbleScene.vue';
import Reveal from '@/components/fx/Reveal.vue';

defineProps<{ title: string; description?: string | null }>();
</script>

<template>
    <section class="px-4 py-16 sm:px-6 sm:py-20" data-test="cta-band">
        <Reveal>
            <div
                class="relative mx-auto max-w-[1200px] overflow-hidden rounded-dialog border bg-primary-soft px-6 py-14 text-center shadow-clay sm:px-12"
            >
                <BubbleScene class="pointer-events-none absolute inset-0" />
                <div class="relative mx-auto max-w-2xl space-y-5">
                    <h2 class="text-display-l">
                        {{ title }}
                    </h2>
                    <p
                        v-if="description"
                        class="text-lg text-pretty text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                    <div
                        class="flex flex-col items-center justify-center gap-3 pt-2 sm:flex-row"
                    >
                        <slot />
                    </div>
                </div>
            </div>
        </Reveal>
    </section>
</template>

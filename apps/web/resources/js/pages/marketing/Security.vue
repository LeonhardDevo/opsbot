<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bot,
    Check,
    FileCheck,
    FileLock2,
    Fingerprint,
    Network,
    ScrollText,
    ShieldCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import ChatBubble from '@/components/chat/ChatBubble.vue';
import DemoCitation from '@/components/chat/DemoCitation.vue';
import Reveal from '@/components/fx/Reveal.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { endpoints } from '@/lib/endpoints';
import { listIndices, t } from '@/lib/i18n';

const pillars = [
    { key: 'data', icon: FileLock2 },
    { key: 'access', icon: Fingerprint },
    { key: 'knowledge', icon: FileCheck },
    { key: 'ai', icon: Bot },
    { key: 'infra', icon: Network },
    { key: 'audit', icon: ScrollText },
] as const;

const sections = computed(() =>
    pillars.map((pillar) => ({
        ...pillar,
        points: listIndices(
            `marketing.security_page.pillars.${pillar.key}.points`,
            'text',
        ).map((index) =>
            t(
                `marketing.security_page.pillars.${pillar.key}.points.${index}.text`,
            ),
        ),
    })),
);
</script>

<template>
    <SeoHead
        :title="t('marketing.security_page.meta_title')"
        :description="t('marketing.security_page.meta_description')"
    />

    <section class="relative overflow-hidden" aria-labelledby="security-hero">
        <div
            class="pointer-events-none absolute -top-40 -right-32 size-[30rem] rounded-full bg-[var(--glow-mint)] blur-3xl"
            aria-hidden="true"
        />
        <div
            class="relative mx-auto grid max-w-[1200px] items-center gap-10 px-4 pt-14 pb-16 sm:px-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] lg:pt-20"
        >
            <div class="space-y-5 motion-safe:animate-fade-up">
                <p
                    class="inline-flex items-center gap-2 rounded-chip bg-mint-soft px-3 py-1 text-[0.8125rem] font-semibold text-mint-strong"
                >
                    <ShieldCheck class="size-4" aria-hidden="true" />
                    {{ t('marketing.nav.security') }}
                </p>
                <h1 id="security-hero" class="text-display-l">
                    {{ t('marketing.security_page.title') }}
                </h1>
                <p class="max-w-xl text-lg text-pretty text-muted-foreground">
                    {{ t('marketing.security_page.subtitle') }}
                </p>
            </div>
            <div
                class="mx-auto w-full max-w-md rounded-dialog border bg-surface-2 p-4 shadow-clay"
                aria-hidden="true"
            >
                <div class="space-y-3">
                    <ChatBubble variant="employee" dense>
                        {{ t('marketing.demo.question') }}
                    </ChatBubble>
                    <ChatBubble variant="bot" dense>
                        {{ t('marketing.demo.answer_intro') }}
                        <template #footer>
                            <div class="flex flex-wrap gap-1.5">
                                <DemoCitation
                                    :index="1"
                                    :title="t('marketing.demo.cite_1')"
                                    :page="t('marketing.demo.cite_1_page')"
                                />
                            </div>
                        </template>
                    </ChatBubble>
                    <div
                        class="flex items-center gap-3 rounded-card border bg-surface p-3"
                    >
                        <span
                            class="inline-flex size-9 items-center justify-center rounded-xl bg-success-soft text-success"
                        >
                            <FileLock2 class="size-4" />
                        </span>
                        <div class="min-w-0 text-sm">
                            <p class="font-semibold">
                                {{ t('marketing.story.viewer_title') }}
                            </p>
                            <p class="font-mono text-xs text-muted-foreground">
                                {{ t('marketing.story.viewer_valid') }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section
        class="mx-auto max-w-[1200px] px-4 pb-20 sm:px-6"
        :aria-label="t('marketing.security_page.pillars_label')"
    >
        <ul class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            <li v-for="(section, index) in sections" :key="section.key">
                <Reveal :delay="index * 0.05" class="h-full">
                    <article
                        class="flex h-full flex-col rounded-card border bg-surface p-6 shadow-clay"
                    >
                        <span
                            class="inline-flex size-11 items-center justify-center rounded-2xl bg-primary-soft text-primary-strong"
                            aria-hidden="true"
                        >
                            <component :is="section.icon" class="size-5" />
                        </span>
                        <h2 class="text-h2 mt-4">
                            {{
                                t(
                                    `marketing.security_page.pillars.${section.key}.title`,
                                )
                            }}
                        </h2>
                        <p
                            class="mt-1.5 text-[0.9375rem] text-muted-foreground"
                        >
                            {{
                                t(
                                    `marketing.security_page.pillars.${section.key}.body`,
                                )
                            }}
                        </p>
                        <ul class="mt-4 space-y-2 border-t pt-4 text-sm">
                            <li
                                v-for="point in section.points"
                                :key="point"
                                class="flex gap-2"
                            >
                                <Check
                                    class="mt-0.5 size-4 shrink-0 text-success"
                                    aria-hidden="true"
                                />
                                <span>{{ point }}</span>
                            </li>
                        </ul>
                    </article>
                </Reveal>
            </li>
        </ul>

        <div
            class="mt-10 flex flex-col gap-4 rounded-dialog border bg-sun-soft p-6 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="space-y-1">
                <h2 class="text-h2">
                    {{ t('marketing.security_page.disclosure_title') }}
                </h2>
                <p class="max-w-2xl text-[0.9375rem]">
                    {{ t('marketing.security_page.disclosure_body') }}
                </p>
            </div>
            <Button as-child variant="secondary" class="shrink-0">
                <Link :href="endpoints.legal('privacy')">
                    {{ t('marketing.footer.privacy') }}
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </Button>
        </div>
    </section>

    <CtaBand
        :title="t('marketing.final_cta.title')"
        :description="t('marketing.final_cta.subtitle')"
    >
        <Button as-child size="lg">
            <Link :href="endpoints.pricing()">
                {{ t('marketing.final_cta.secondary') }}
                <ArrowRight class="size-5" aria-hidden="true" />
            </Link>
        </Button>
    </CtaBand>
</template>

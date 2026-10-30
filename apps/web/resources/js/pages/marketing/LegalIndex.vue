<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Scale, ShieldCheck, ShieldUser } from '@lucide/vue';
import { computed } from 'vue';
import Reveal from '@/components/fx/Reveal.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { has, t } from '@/lib/i18n';
import { legalDocIcon } from '@/lib/legal';
import { login } from '@/routes';
import type { LegalDocSummary } from '@/types/legal';

const props = withDefaults(defineProps<{ docs?: LegalDocSummary[] }>(), {
    docs: () => [],
});

const page = usePage();
const fmt = useFormat();
const authed = computed(() => Boolean(page.props.auth?.user));

const GROUPS = [
    { key: 'service', docs: ['terms', 'aup', 'dpa', 'subprocessors'] },
    { key: 'privacy', docs: ['privacy', 'cookies', 'consent-ru'] },
    { key: 'people', docs: ['employee-notice', 'ai'] },
] as const;

function blurb(key: string): string | null {
    const id = `marketing.legal.blurbs.${key.replace(/-/g, '_')}`;

    return has(id) ? t(id) : null;
}

const groups = computed(() => {
    const known = new Set<string>(GROUPS.flatMap((group) => group.docs));
    const decorate = (doc: LegalDocSummary) => ({
        ...doc,
        icon: legalDocIcon(doc.key),
        blurb: blurb(doc.key),
    });

    return GROUPS.map((group, index) => ({
        key: group.key,
        items: props.docs
            .filter(
                (doc) =>
                    (group.docs as readonly string[]).includes(doc.key) ||
                    (index === 0 && !known.has(doc.key)),
            )
            .sort(
                (a, b) =>
                    (group.docs as readonly string[]).indexOf(a.key) -
                    (group.docs as readonly string[]).indexOf(b.key),
            )
            .map(decorate),
    })).filter((group) => group.items.length > 0);
});
</script>

<template>
    <SeoHead
        :title="t('marketing.legal.index_title')"
        :description="t('marketing.legal.index_description')"
    />

    <section class="relative overflow-hidden" aria-labelledby="legal-hero">
        <div
            class="pointer-events-none absolute -top-40 -right-32 size-[30rem] rounded-full bg-[var(--glow-mint)] blur-3xl"
            aria-hidden="true"
        />
        <div
            class="relative mx-auto max-w-[1200px] px-4 pt-14 pb-10 sm:px-6 lg:pt-20"
        >
            <div class="max-w-2xl space-y-4 motion-safe:animate-fade-up">
                <p
                    class="inline-flex items-center gap-1.5 rounded-chip bg-mint-soft px-3 py-1 text-[0.8125rem] font-semibold text-mint-strong"
                >
                    <Scale class="size-3.5" aria-hidden="true" />
                    {{ t('marketing.legal.eyebrow') }}
                </p>
                <h1 id="legal-hero" class="text-display-l">
                    {{ t('marketing.legal.index_title') }}
                </h1>
                <p class="text-lg text-pretty text-muted-foreground">
                    {{ t('marketing.legal.index_description') }}
                </p>
            </div>
        </div>
    </section>

    <div
        class="mx-auto max-w-[1200px] space-y-12 px-4 pb-20 sm:px-6"
        data-test="legal-index"
    >
        <section
            v-for="group in groups"
            :key="group.key"
            :aria-labelledby="`legal-group-${group.key}`"
            :data-group="group.key"
        >
            <div class="mb-4 max-w-2xl">
                <h2 :id="`legal-group-${group.key}`" class="text-h2">
                    {{ t(`marketing.legal.groups.${group.key}.title`) }}
                </h2>
                <p class="mt-1 text-[0.9375rem] text-muted-foreground">
                    {{ t(`marketing.legal.groups.${group.key}.body`) }}
                </p>
            </div>
            <ul class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <li v-for="(doc, index) in group.items" :key="doc.key">
                    <Reveal :delay="index * 0.04" class="h-full">
                        <Link
                            :href="endpoints.legal(doc.key)"
                            class="group flex h-full flex-col rounded-card border bg-surface p-5 shadow-clay transition-shadow hover:shadow-clay-lg"
                            :data-test="`legal-doc-${doc.key}`"
                        >
                            <span
                                class="flex items-start justify-between gap-3"
                            >
                                <span
                                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary-soft text-primary-strong"
                                    aria-hidden="true"
                                >
                                    <component :is="doc.icon" class="size-5" />
                                </span>
                                <ArrowRight
                                    class="mt-1 size-4 shrink-0 text-muted-foreground transition-colors group-hover:text-primary-strong"
                                    aria-hidden="true"
                                />
                            </span>
                            <span class="text-h2 mt-4 text-balance">{{
                                doc.title
                            }}</span>
                            <span
                                v-if="doc.blurb"
                                class="mt-1.5 text-sm text-pretty text-muted-foreground"
                                >{{ doc.blurb }}</span
                            >
                            <span
                                class="mt-auto flex flex-wrap items-center gap-2 pt-4 text-xs"
                            >
                                <span
                                    class="inline-flex h-6 items-center gap-1 rounded-chip bg-surface-2 px-2 text-muted-foreground"
                                    >{{ t('marketing.legal.version') }}
                                    <span
                                        class="font-mono font-medium text-foreground"
                                        >{{ doc.version }}</span
                                    ></span
                                >
                                <span
                                    class="inline-flex h-6 items-center gap-1 rounded-chip bg-surface-2 px-2 text-muted-foreground"
                                    >{{ t('marketing.legal.effective') }}
                                    <time
                                        :datetime="doc.effective_date"
                                        class="font-medium text-foreground"
                                        >{{
                                            fmt.date(doc.effective_date)
                                        }}</time
                                    ></span
                                >
                            </span>
                        </Link>
                    </Reveal>
                </li>
            </ul>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            <div
                class="flex flex-col gap-4 rounded-dialog border bg-surface-2 p-5 sm:flex-row sm:items-center"
            >
                <span
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-2xl bg-mint-soft text-mint-strong"
                    aria-hidden="true"
                >
                    <ShieldCheck class="size-5" />
                </span>
                <p class="min-w-0 flex-1 text-sm text-pretty">
                    {{ t('marketing.legal.security_hint') }}
                </p>
                <Button variant="secondary" size="sm" as-child>
                    <Link :href="endpoints.security()">{{
                        t('ui.legal.security')
                    }}</Link>
                </Button>
            </div>
            <div
                class="flex flex-col gap-4 rounded-dialog border bg-surface-2 p-5 sm:flex-row sm:items-center"
                data-test="legal-privacy-controls"
            >
                <span
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary-soft text-primary-strong"
                    aria-hidden="true"
                >
                    <ShieldUser class="size-5" />
                </span>
                <p class="min-w-0 flex-1 text-sm text-pretty">
                    {{ t('marketing.legal.controls_hint') }}
                </p>
                <Button variant="secondary" size="sm" as-child>
                    <Link :href="authed ? endpoints.privacy.show() : login()">{{
                        authed
                            ? t('marketing.legal.controls_open')
                            : t('marketing.legal.controls_login')
                    }}</Link>
                </Button>
            </div>
        </div>
    </div>
</template>

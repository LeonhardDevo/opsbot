<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Armchair,
    Bandage,
    Bot,
    Check,
    CircleEllipsis,
    DoorClosed,
    FileCheck,
    FileText,
    KeyRound,
    Languages,
    Link2,
    Minus,
    MessageSquareWarning,
    MessagesSquare,
    PackageSearch,
    QrCode,
    Server,
    ShieldCheck,
    Siren,
    TriangleAlert,
    UserCheck,
    Users,
} from '@lucide/vue';
import { useIntersectionObserver } from '@vueuse/core';
import { computed, onMounted, reactive, ref } from 'vue';
import type { Component } from 'vue';
import SeverityBadge from '@/components/chat/SeverityBadge.vue';
import TelegramPreview from '@/components/chat/TelegramPreview.vue';
import Reveal from '@/components/fx/Reveal.vue';
import StoryScene from '@/components/landing/StoryScene.vue';
import { pickActiveScene, storyScenes } from '@/components/landing/story';
import type { StorySceneKey } from '@/components/landing/story';
import CtaBand from '@/components/marketing/CtaBand.vue';
import FaqList from '@/components/marketing/FaqList.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { useLimits } from '@/composables/useLimits';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { endpoints } from '@/lib/endpoints';
import { listIndices, t } from '@/lib/i18n';
import { register } from '@/routes';
import type { Plan } from '@/types';
import type { LimitKey } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{ canRegister?: boolean; plans?: Plan[] }>(),
    { canRegister: true, plans: () => [] },
);

const fmt = useFormat();
const limits = useLimits();
const reduced = useReducedMotion();

const startHref = computed(() =>
    props.canRegister ? register().url : endpoints.home().url,
);

const proof: Array<{ key: string; icon: Component }> = [
    { key: 'bot', icon: Bot },
    { key: 'approved', icon: FileCheck },
    { key: 'languages', icon: Languages },
    { key: 'links', icon: ShieldCheck },
];

const howSteps: Array<{ key: string; icon: Component }> = [
    { key: 'connect', icon: Bot },
    { key: 'upload', icon: FileCheck },
    { key: 'invite', icon: QrCode },
    { key: 'ask', icon: MessagesSquare },
];

const categories: Array<{ key: string; icon: Component }> = [
    { key: 'damage', icon: Armchair },
    { key: 'complaint', icon: MessageSquareWarning },
    { key: 'no_access', icon: DoorClosed },
    { key: 'injury', icon: Bandage },
    { key: 'lost_item', icon: PackageSearch },
    { key: 'other', icon: CircleEllipsis },
];

const compareRows = ['accounts', 'approved', 'refusal', 'incidents', 'day_one'];
const compareColumns = ['office', 'apps', 'opsbot'] as const;

const securityItems: Array<{ key: string; icon: Component }> = [
    { key: 'tokens', icon: KeyRound },
    { key: 'links', icon: Link2 },
    { key: 'notice', icon: UserCheck },
    { key: 'local', icon: Server },
];

const packDocs = computed(() =>
    Array.from({ length: 8 }, (_, index) => t(`marketing.pack.docs.${index}`)),
);

const faq = computed(() =>
    listIndices('marketing.faq.items', 'q').map((index) => ({
        id: `faq-${index}`,
        question: t(`marketing.faq.items.${index}.q`),
        answer: t(`marketing.faq.items.${index}.a`),
    })),
);

const teaserPlans = computed(() => props.plans.slice(0, 4));

function planPrice(plan: Plan): string {
    return plan.price_monthly_minor === 0
        ? t('marketing.pricing_teaser.free')
        : fmt.value.money(plan.price_monthly_minor / 100, plan.currency, {
              fractionDigits: 0,
          });
}

function planLimit(plan: Plan, key: LimitKey): string {
    const value = plan.limits?.[key];

    if (value === null) {
        return t('ui.billing.unlimited');
    }

    return value === undefined ? '—' : limits.value(key, value);
}

const activeScene = ref<StorySceneKey>('answer');
const sceneRefs = ref<HTMLElement[]>([]);
const ratios = reactive<Record<string, { ratio: number; top: number }>>({});
const mounted = ref(false);

onMounted(() => {
    mounted.value = true;
    sceneRefs.value.forEach((element) => {
        const key = element.dataset.scene as StorySceneKey;

        useIntersectionObserver(
            element,
            ([entry]) => {
                ratios[key] = {
                    ratio: entry?.isIntersecting ? entry.intersectionRatio : 0,
                    top: entry?.boundingClientRect.top ?? 0,
                };
                activeScene.value = pickActiveScene(
                    storyScenes.map((scene) => ({
                        key: scene.key,
                        ratio: ratios[scene.key]?.ratio ?? 0,
                        top: ratios[scene.key]?.top ?? 0,
                    })),
                    activeScene.value,
                );
            },
            {
                rootMargin: '-35% 0px -35% 0px',
                threshold: [0, 0.25, 0.5, 0.75, 1],
            },
        );
    });
});

function focusScene(key: StorySceneKey): void {
    activeScene.value = key;
}
</script>

<template>
    <SeoHead
        :title="t('marketing.meta.title')"
        :description="t('marketing.meta.description')"
    />

    <section
        class="relative overflow-hidden"
        aria-labelledby="hero-title"
        data-test="landing-hero"
    >
        <div
            class="pointer-events-none absolute -top-40 -left-32 size-[34rem] rounded-full bg-[var(--glow-sky)] blur-3xl"
            aria-hidden="true"
        />
        <div
            class="pointer-events-none absolute top-24 -right-40 size-[30rem] rounded-full bg-[var(--glow-mint)] blur-3xl"
            aria-hidden="true"
        />
        <div
            class="relative mx-auto grid max-w-[1200px] items-center gap-12 px-4 pt-12 pb-16 sm:px-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)] lg:pt-20 lg:pb-24"
        >
            <div class="space-y-6 motion-safe:animate-fade-up">
                <p
                    class="inline-flex items-center gap-2 rounded-chip border bg-surface px-3 py-1.5 text-[0.8125rem] font-semibold text-primary-strong shadow-clay-sm"
                >
                    <Bot class="size-4" aria-hidden="true" />
                    {{ t('marketing.hero.eyebrow') }}
                </p>
                <h1 id="hero-title" class="text-display-xl">
                    {{ t('marketing.hero.title') }}
                </h1>
                <p class="max-w-xl text-lg text-pretty text-muted-foreground">
                    {{ t('marketing.hero.subtitle') }}
                </p>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <Button as-child size="lg">
                        <Link :href="startHref" data-test="landing-cta-start">
                            {{ t('marketing.hero.cta_primary') }}
                            <ArrowRight class="size-5" aria-hidden="true" />
                        </Link>
                    </Button>
                    <Button as-child size="lg" variant="secondary">
                        <a href="#story" data-test="landing-cta-how">
                            {{ t('marketing.hero.cta_secondary') }}
                        </a>
                    </Button>
                </div>
                <p class="text-caption">{{ t('marketing.hero.note') }}</p>
            </div>

            <div
                class="relative mx-auto w-full max-w-[400px]"
                data-test="landing-preview"
            >
                <p class="sr-only">{{ t('marketing.demo.summary') }}</p>
                <div aria-hidden="true" class="motion-safe:animate-pop-in">
                    <TelegramPreview
                        :title="t('marketing.demo.bot_title')"
                        :subtitle="t('marketing.demo.bot_subtitle')"
                        class="mx-auto"
                    >
                        <StoryScene scene="answer" dense />
                    </TelegramPreview>
                    <div
                        class="absolute -bottom-10 left-0 w-64 rounded-card border bg-surface p-3.5 shadow-clay-lg motion-safe:animate-float sm:-left-12"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 font-mono text-sm font-semibold whitespace-nowrap"
                            >
                                <Siren
                                    class="size-4 text-coral-strong"
                                    aria-hidden="true"
                                />
                                {{ t('marketing.demo.incident_title') }}
                            </span>
                            <SeverityBadge severity="medium" />
                        </div>
                        <p class="mt-1.5 text-sm font-semibold">
                            {{ t('marketing.demo.incident_route') }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ t('marketing.demo.incident_meta') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section
        class="border-y bg-surface"
        :aria-label="t('marketing.proof.label')"
    >
        <ul
            class="mx-auto grid max-w-[1200px] grid-cols-1 gap-x-6 gap-y-4 px-4 py-6 sm:grid-cols-2 sm:px-6 lg:grid-cols-4"
        >
            <li
                v-for="item in proof"
                :key="item.key"
                class="flex items-center gap-3"
            >
                <span
                    class="inline-flex size-10 shrink-0 items-center justify-center rounded-2xl bg-primary-soft text-primary-strong"
                    aria-hidden="true"
                >
                    <component :is="item.icon" class="size-5" />
                </span>
                <span class="text-[0.9375rem] font-semibold">{{
                    t(`marketing.proof.items.${item.key}`)
                }}</span>
            </li>
        </ul>
    </section>

    <section
        id="story"
        class="mx-auto max-w-[1200px] px-4 py-20 sm:px-6 lg:py-28"
        aria-labelledby="story-title"
        data-test="landing-story"
    >
        <SectionHeading
            id="story-title"
            :eyebrow="t('marketing.story.eyebrow')"
            :title="t('marketing.story.title')"
            :description="t('marketing.story.subtitle')"
        />

        <div
            class="mt-14 grid gap-10 lg:grid-cols-[380px_minmax(0,1fr)] lg:gap-16"
        >
            <div class="hidden lg:block">
                <div
                    class="sticky top-24"
                    data-test="landing-story-phone"
                    :data-active-scene="activeScene"
                    aria-hidden="true"
                >
                    <TelegramPreview
                        :title="t('marketing.demo.bot_title')"
                        :subtitle="t('marketing.demo.bot_subtitle')"
                    >
                        <Transition
                            :name="reduced ? '' : 'scene'"
                            mode="out-in"
                        >
                            <StoryScene
                                :key="activeScene"
                                :scene="activeScene"
                            />
                        </Transition>
                    </TelegramPreview>
                </div>
            </div>

            <ol class="space-y-6 lg:space-y-[16vh] lg:py-[8vh]">
                <li
                    v-for="(scene, index) in storyScenes"
                    :key="scene.key"
                    ref="sceneRefs"
                    :data-scene="scene.key"
                    data-test="landing-story-scene"
                    :data-active="
                        mounted && activeScene === scene.key ? 'true' : 'false'
                    "
                    tabindex="0"
                    class="rounded-dialog border bg-surface p-5 shadow-clay transition-[box-shadow,border-color] duration-200 sm:p-6 lg:border-transparent lg:bg-transparent lg:shadow-none lg:data-[active=true]:border-border lg:data-[active=true]:bg-surface lg:data-[active=true]:shadow-clay-lg"
                    @focus="focusScene(scene.key)"
                >
                    <div class="flex items-start gap-4">
                        <span
                            class="inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-mint-soft text-mint-strong"
                            aria-hidden="true"
                        >
                            <component :is="scene.icon" class="size-6" />
                        </span>
                        <div class="min-w-0 space-y-1.5">
                            <p class="font-mono text-xs text-muted-foreground">
                                {{ String(index + 1).padStart(2, '0') }} / 06
                            </p>
                            <h3 class="text-xl leading-snug font-semibold">
                                {{
                                    t(
                                        `marketing.story.scenes.${scene.key}.title`,
                                    )
                                }}
                            </h3>
                            <p class="text-[0.9375rem] text-muted-foreground">
                                {{
                                    t(
                                        `marketing.story.scenes.${scene.key}.body`,
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="mt-5 rounded-card bg-phone-screen p-3 lg:hidden"
                        aria-hidden="true"
                    >
                        <StoryScene :scene="scene.key" dense />
                    </div>
                </li>
            </ol>
        </div>
    </section>

    <section
        id="how"
        class="bg-surface-2 py-20 lg:py-24"
        aria-labelledby="how-title"
        data-test="landing-how"
    >
        <div class="mx-auto max-w-[1200px] px-4 sm:px-6">
            <SectionHeading
                id="how-title"
                :eyebrow="t('marketing.how.eyebrow')"
                :title="t('marketing.how.title')"
                :description="t('marketing.how.subtitle')"
            />
            <ol class="relative mt-14 grid gap-6 lg:grid-cols-4">
                <div
                    class="absolute top-7 right-[12%] left-[12%] hidden h-0.5 bg-[repeating-linear-gradient(90deg,var(--border)_0_10px,transparent_10px_18px)] lg:block"
                    aria-hidden="true"
                />
                <li
                    v-for="(step, index) in howSteps"
                    :key="step.key"
                    class="relative"
                >
                    <Reveal :delay="index * 0.08">
                        <div
                            class="flex gap-4 lg:flex-col lg:items-center lg:text-center"
                        >
                            <span
                                class="relative inline-flex size-14 shrink-0 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-clay"
                                aria-hidden="true"
                            >
                                <component :is="step.icon" class="size-6" />
                                <span
                                    class="absolute -top-2 -right-2 inline-flex size-6 items-center justify-center rounded-full bg-sun font-mono text-xs font-semibold text-sun-foreground ring-2 ring-surface-2"
                                    >{{ index + 1 }}</span
                                >
                            </span>
                            <div class="space-y-1.5">
                                <h3 class="text-lg font-semibold">
                                    {{
                                        t(
                                            `marketing.how.steps.${step.key}.title`,
                                        )
                                    }}
                                </h3>
                                <p
                                    class="text-[0.9375rem] text-muted-foreground"
                                >
                                    {{
                                        t(
                                            `marketing.how.steps.${step.key}.body`,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                    </Reveal>
                </li>
            </ol>
        </div>
    </section>

    <section
        id="pack"
        class="mx-auto max-w-[1200px] px-4 py-20 sm:px-6 lg:py-24"
        aria-labelledby="pack-title"
        data-test="landing-pack"
    >
        <div
            class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-start"
        >
            <div class="space-y-6">
                <SectionHeading
                    id="pack-title"
                    align="left"
                    :eyebrow="t('marketing.pack.eyebrow')"
                    :title="t('marketing.pack.title')"
                    :description="t('marketing.pack.subtitle')"
                />
                <p
                    class="flex items-start gap-3 rounded-card bg-sun-soft p-4 text-[0.9375rem] text-foreground"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0 text-sun-strong"
                        aria-hidden="true"
                    />
                    {{ t('marketing.pack.note') }}
                </p>
                <div class="space-y-3">
                    <h3 class="text-h2">
                        {{ t('marketing.pack.categories_title') }}
                    </h3>
                    <ul class="flex flex-wrap gap-2">
                        <li
                            v-for="category in categories"
                            :key="category.key"
                            class="inline-flex h-9 items-center gap-2 rounded-chip bg-neutral-soft px-3.5 text-sm font-medium text-neutral-foreground"
                        >
                            <component
                                :is="category.icon"
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ t(`marketing.pack.categories.${category.key}`) }}
                        </li>
                    </ul>
                </div>
            </div>
            <Reveal>
                <div
                    class="rounded-dialog border bg-surface p-5 shadow-clay sm:p-6"
                >
                    <h3 class="text-h2">
                        {{ t('marketing.pack.docs_label') }}
                    </h3>
                    <ul class="mt-4 grid gap-2.5">
                        <li
                            v-for="(doc, index) in packDocs"
                            :key="index"
                            class="flex items-center gap-3 rounded-control border bg-background px-3.5 py-3 [overflow-wrap:anywhere]"
                        >
                            <span
                                class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-strong"
                                aria-hidden="true"
                            >
                                <FileText class="size-4" />
                            </span>
                            <span
                                class="min-w-0 flex-1 text-[0.9375rem] font-medium"
                                >{{ doc }}</span
                            >
                            <span
                                class="shrink-0 rounded-chip bg-neutral-soft px-2 py-0.5 font-mono text-xs text-neutral-foreground"
                                >RU · EN</span
                            >
                        </li>
                    </ul>
                </div>
            </Reveal>
        </div>
    </section>

    <section
        class="bg-surface-2 py-20 lg:py-24"
        aria-labelledby="compare-title"
        data-test="landing-compare"
    >
        <div class="mx-auto max-w-[1200px] px-4 sm:px-6">
            <SectionHeading
                id="compare-title"
                :eyebrow="t('marketing.compare.eyebrow')"
                :title="t('marketing.compare.title')"
                :description="t('marketing.compare.subtitle')"
            />
            <div
                class="mt-12 hidden overflow-hidden rounded-dialog border bg-surface shadow-clay md:block"
            >
                <table
                    class="w-full border-collapse text-left text-[0.9375rem]"
                >
                    <thead>
                        <tr class="bg-surface-2">
                            <th
                                scope="col"
                                class="w-[28%] px-5 py-4 text-sm font-semibold"
                            >
                                {{ t('marketing.compare.need') }}
                            </th>
                            <th
                                v-for="column in compareColumns"
                                :key="column"
                                scope="col"
                                :class="[
                                    'px-5 py-4 text-sm font-semibold',
                                    column === 'opsbot'
                                        ? 'bg-primary-soft text-primary-strong'
                                        : '',
                                ]"
                            >
                                {{ t(`marketing.compare.columns.${column}`) }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in compareRows"
                            :key="row"
                            class="border-t align-top"
                        >
                            <th scope="row" class="px-5 py-4 font-semibold">
                                {{ t(`marketing.compare.rows.${row}.need`) }}
                            </th>
                            <td
                                v-for="column in compareColumns"
                                :key="column"
                                :class="[
                                    'px-5 py-4',
                                    column === 'opsbot'
                                        ? 'bg-primary-soft/50'
                                        : 'text-muted-foreground',
                                ]"
                            >
                                <span class="flex items-start gap-2">
                                    <component
                                        :is="
                                            column === 'opsbot' ? Check : Minus
                                        "
                                        :class="[
                                            'mt-0.5 size-4 shrink-0',
                                            column === 'opsbot'
                                                ? 'text-success'
                                                : '',
                                        ]"
                                        aria-hidden="true"
                                    />
                                    <span>
                                        <span class="sr-only"
                                            >{{
                                                column === 'opsbot'
                                                    ? t('marketing.compare.yes')
                                                    : t('marketing.compare.no')
                                            }}:
                                        </span>
                                        <span
                                            :class="
                                                column === 'opsbot'
                                                    ? 'font-medium text-foreground'
                                                    : ''
                                            "
                                            >{{
                                                t(
                                                    `marketing.compare.rows.${row}.${column}`,
                                                )
                                            }}</span
                                        >
                                    </span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ul class="mt-10 space-y-4 md:hidden">
                <li
                    v-for="row in compareRows"
                    :key="row"
                    class="rounded-card border bg-surface p-4 shadow-clay-sm"
                >
                    <p class="font-semibold">
                        {{ t(`marketing.compare.rows.${row}.need`) }}
                    </p>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div
                            v-for="column in compareColumns"
                            :key="column"
                            :class="[
                                'flex items-start gap-2 rounded-control p-2.5',
                                column === 'opsbot'
                                    ? 'bg-primary-soft'
                                    : 'bg-surface-2',
                            ]"
                        >
                            <component
                                :is="column === 'opsbot' ? Check : Minus"
                                :class="[
                                    'mt-0.5 size-4 shrink-0',
                                    column === 'opsbot'
                                        ? 'text-success'
                                        : 'text-muted-foreground',
                                ]"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <dt
                                    class="text-xs font-semibold text-muted-foreground"
                                >
                                    {{
                                        t(`marketing.compare.columns.${column}`)
                                    }}
                                </dt>
                                <dd class="font-medium">
                                    {{
                                        t(
                                            `marketing.compare.rows.${row}.${column}`,
                                        )
                                    }}
                                </dd>
                            </div>
                        </div>
                    </dl>
                </li>
            </ul>
        </div>
    </section>

    <section
        class="mx-auto max-w-[1200px] px-4 py-20 sm:px-6 lg:py-24"
        aria-labelledby="security-title"
        data-test="landing-security"
    >
        <SectionHeading
            id="security-title"
            :eyebrow="t('marketing.security.eyebrow')"
            :title="t('marketing.security.title')"
            :description="t('marketing.security.subtitle')"
        />
        <ul class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <li v-for="(item, index) in securityItems" :key="item.key">
                <Reveal :delay="index * 0.06" class="h-full">
                    <article
                        class="flex h-full flex-col gap-3 rounded-card border bg-surface p-5 shadow-clay"
                    >
                        <span
                            class="inline-flex size-11 items-center justify-center rounded-2xl bg-mint-soft text-mint-strong"
                            aria-hidden="true"
                        >
                            <component :is="item.icon" class="size-5" />
                        </span>
                        <h3 class="text-lg font-semibold">
                            {{
                                t(`marketing.security.items.${item.key}.title`)
                            }}
                        </h3>
                        <p class="text-[0.9375rem] text-muted-foreground">
                            {{ t(`marketing.security.items.${item.key}.body`) }}
                        </p>
                    </article>
                </Reveal>
            </li>
        </ul>
        <div class="mt-8 text-center">
            <Button as-child variant="secondary">
                <Link :href="endpoints.security()">
                    <ShieldCheck class="size-5" aria-hidden="true" />
                    {{ t('marketing.security.cta') }}
                </Link>
            </Button>
        </div>
    </section>

    <section
        class="bg-surface-2 py-20 lg:py-24"
        aria-labelledby="pricing-teaser-title"
        data-test="landing-pricing"
    >
        <div class="mx-auto max-w-[1200px] px-4 sm:px-6">
            <SectionHeading
                id="pricing-teaser-title"
                :eyebrow="t('marketing.pricing_teaser.eyebrow')"
                :title="t('marketing.pricing_teaser.title')"
                :description="t('marketing.pricing_teaser.subtitle')"
            />
            <ul
                v-if="teaserPlans.length"
                class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
            >
                <li
                    v-for="plan in teaserPlans"
                    :key="plan.key"
                    :class="[
                        'relative flex flex-col gap-4 rounded-card bg-surface p-5',
                        plan.highlighted
                            ? 'border-2 border-primary shadow-clay-lg'
                            : 'border shadow-clay',
                    ]"
                    :data-plan="plan.key"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-h2">{{ plan.name }}</h3>
                        <span
                            v-if="plan.highlighted"
                            class="rounded-chip bg-primary-soft px-2.5 py-0.5 text-xs font-semibold text-primary-strong"
                            >{{ t('marketing.pricing_teaser.popular') }}</span
                        >
                    </div>
                    <p>
                        <span class="text-kpi">{{ planPrice(plan) }}</span>
                        <span
                            v-if="plan.price_monthly_minor > 0"
                            class="text-caption ml-1"
                            >{{ t('marketing.pricing_teaser.per_month') }}</span
                        >
                    </p>
                    <ul class="space-y-2 text-sm">
                        <li class="flex items-start gap-2">
                            <Users
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1">{{
                                limits.label('seats')
                            }}</span>
                            <strong
                                class="font-semibold whitespace-nowrap tabular-nums"
                                >{{ planLimit(plan, 'seats') }}</strong
                            >
                        </li>
                        <li class="flex items-start gap-2">
                            <FileText
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1">{{
                                limits.label('documents')
                            }}</span>
                            <strong
                                class="font-semibold whitespace-nowrap tabular-nums"
                                >{{ planLimit(plan, 'documents') }}</strong
                            >
                        </li>
                    </ul>
                </li>
            </ul>
            <div
                class="mt-8 flex flex-col items-center justify-center gap-3 text-center sm:flex-row"
            >
                <Button as-child>
                    <Link :href="endpoints.pricing()">
                        {{ t('marketing.pricing_teaser.cta') }}
                        <ArrowRight class="size-5" aria-hidden="true" />
                    </Link>
                </Button>
                <p class="text-caption">
                    {{ t('marketing.pricing_teaser.payments_note') }}
                </p>
            </div>
        </div>
    </section>

    <section
        id="faq"
        class="mx-auto max-w-3xl px-4 py-20 sm:px-6 lg:py-24"
        aria-labelledby="faq-title"
        data-test="landing-faq"
    >
        <SectionHeading
            id="faq-title"
            :eyebrow="t('marketing.faq.eyebrow')"
            :title="t('marketing.faq.title')"
        />
        <div class="mt-10">
            <FaqList :items="faq" />
        </div>
    </section>

    <CtaBand
        :title="t('marketing.final_cta.title')"
        :description="t('marketing.final_cta.subtitle')"
    >
        <Button as-child size="lg">
            <Link :href="startHref" data-test="cta-register">
                {{ t('marketing.hero.cta_primary') }}
                <ArrowRight class="size-5" aria-hidden="true" />
            </Link>
        </Button>
        <Button as-child size="lg" variant="secondary">
            <Link :href="endpoints.pricing()">
                {{ t('marketing.final_cta.secondary') }}
            </Link>
        </Button>
    </CtaBand>
</template>

<style scoped>
.scene-enter-active,
.scene-leave-active {
    transition:
        opacity 240ms cubic-bezier(0.2, 0.8, 0.2, 1),
        transform 240ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

.scene-enter-from {
    opacity: 0;
    transform: translateY(12px);
}

.scene-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}
</style>

<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CircleAlert,
    CirclePause,
    FileCheck,
    Forward,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import IntervalToggle from '@/components/billing/IntervalToggle.vue';
import PlanCard from '@/components/billing/PlanCard.vue';
import Reveal from '@/components/fx/Reveal.vue';
import CtaBand from '@/components/marketing/CtaBand.vue';
import FaqList from '@/components/marketing/FaqList.vue';
import SectionHeading from '@/components/marketing/SectionHeading.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import { Button } from '@/components/ui/button';
import { useLimits } from '@/composables/useLimits';
import { isFreePlan, maxYearlySavings } from '@/lib/billing';
import { endpoints, withQuery } from '@/lib/endpoints';
import { listIndices, t } from '@/lib/i18n';
import { register } from '@/routes';
import type { BillingInterval, Plan } from '@/types';
import type { Currency, LimitKey } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        plans?: Plan[];
        canRegister?: boolean;
        currency?: Currency;
        currencies?: Currency[];
    }>(),
    {
        plans: () => [],
        canRegister: true,
        currency: 'USD',
        currencies: () => ['USD', 'EUR', 'RUB'],
    },
);

const currencyModel = computed({
    get: () => props.currency,
    set: (value: Currency) => {
        router.visit(withQuery(endpoints.pricing().url, { currency: value }), {
            only: ['plans', 'currency'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    },
});

const currencyOptions = computed(() =>
    props.currencies.map((value) => ({
        value,
        label: t(`marketing.pricing.currencies.${value}`),
    })),
);

const page = usePage();
const limits = useLimits();
const interval = ref<BillingInterval>('month');
const authed = computed(() => Boolean(page.props.auth?.user));

const ctaHref = computed(() =>
    authed.value
        ? endpoints.settings.billing.show().url
        : props.canRegister
          ? register().url
          : endpoints.home().url,
);

const orderedPlans = computed(() => props.plans);

const limitKeys = computed(() => {
    const keys = new Set<LimitKey>();

    props.plans.forEach((plan) =>
        (Object.keys(plan.limits ?? {}) as LimitKey[]).forEach((key) =>
            keys.add(key),
        ),
    );

    return [...keys];
});

function limitCell(plan: Plan, key: LimitKey): string {
    const value = plan.limits?.[key];

    if (value === null) {
        return t('ui.billing.unlimited');
    }

    return value === undefined ? '—' : limits.value(key, value);
}

const seatPoints = computed(() =>
    Array.from({ length: 4 }, (_, index) =>
        t(`marketing.pricing.seat_points.${index}`),
    ),
);

const limitIcons: Component[] = [CirclePause, FileCheck, Forward];

const limitPoints = computed(() =>
    limitIcons.map((icon, index) => ({
        icon,
        text: t(`marketing.pricing.limits.${index}`),
    })),
);

const faq = computed(() =>
    listIndices('marketing.pricing.faq', 'q').map((index) => ({
        id: `pricing-faq-${index}`,
        question: t(`marketing.pricing.faq.${index}.q`),
        answer: t(`marketing.pricing.faq.${index}.a`),
    })),
);

const comparePlan = ref<string>(
    props.plans.find((plan) => plan.highlighted)?.key ??
        props.plans[0]?.key ??
        '',
);
</script>

<template>
    <SeoHead
        :title="t('marketing.pricing.meta_title')"
        :description="t('marketing.pricing.meta_description')"
    />

    <section class="relative overflow-hidden" aria-labelledby="pricing-hero">
        <div
            class="pointer-events-none absolute -top-48 left-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-[var(--glow-sky)] blur-3xl"
            aria-hidden="true"
        />
        <div
            class="relative mx-auto max-w-[1200px] px-4 pt-14 pb-10 text-center sm:px-6 lg:pt-20"
        >
            <p
                class="inline-flex items-center rounded-chip bg-mint-soft px-3 py-1 text-[0.8125rem] font-semibold text-mint-strong"
            >
                {{ t('marketing.pricing.eyebrow') }}
            </p>
            <h1 id="pricing-hero" class="text-display-l mx-auto mt-4 max-w-3xl">
                {{ t('marketing.pricing.title') }}
            </h1>
            <p
                class="mx-auto mt-4 max-w-2xl text-lg text-pretty text-muted-foreground"
            >
                {{ t('marketing.pricing.subtitle') }}
            </p>
            <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                <div data-test="pricing-interval">
                    <IntervalToggle
                        v-model="interval"
                        :savings="maxYearlySavings(plans)"
                    />
                </div>
                <SegmentedControl
                    v-if="currencies.length > 1"
                    v-model="currencyModel"
                    :options="currencyOptions"
                    :label="t('marketing.pricing.currency_label')"
                    data-test="pricing-currency"
                />
            </div>
        </div>
    </section>

    <section
        class="mx-auto max-w-[1200px] px-4 pb-16 sm:px-6"
        :aria-label="t('marketing.pricing.plans_label')"
    >
        <div
            v-if="orderedPlans.length"
            class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4"
        >
            <Reveal
                v-for="(plan, index) in orderedPlans"
                :key="plan.key"
                :delay="index * 0.05"
                :class="['h-full', plan.highlighted ? 'max-md:-order-1' : '']"
            >
                <PlanCard :plan="plan" :interval="interval" class="h-full">
                    <template #cta>
                        <Button
                            as-child
                            class="mt-6 w-full"
                            :variant="
                                plan.highlighted ? 'default' : 'secondary'
                            "
                        >
                            <Link
                                :href="ctaHref"
                                :data-test="`pricing-${plan.key}`"
                            >
                                {{
                                    authed
                                        ? t('ui.billing.choose_plan')
                                        : isFreePlan(plan)
                                          ? t('ui.billing.start_free')
                                          : t('ui.billing.start_trial')
                                }}
                            </Link>
                        </Button>
                    </template>
                </PlanCard>
            </Reveal>
        </div>
        <div
            v-else
            class="flex flex-col items-center gap-3 rounded-card border bg-surface p-10 text-center shadow-clay"
            role="alert"
        >
            <CircleAlert class="size-6 text-destructive" aria-hidden="true" />
            <p class="text-muted-foreground">
                {{ t('marketing.pricing.unavailable') }}
            </p>
            <Button as-child variant="secondary">
                <Link :href="endpoints.security()">{{
                    t('marketing.footer.security')
                }}</Link>
            </Button>
        </div>
        <p class="text-caption mt-6 text-center">
            {{ t('marketing.pricing.note') }}
            {{ t('marketing.pricing_teaser.payments_note') }}
        </p>
    </section>

    <section class="mx-auto max-w-[1200px] px-4 pb-20 sm:px-6">
        <div class="grid gap-4 lg:grid-cols-2">
            <article
                class="rounded-dialog border bg-surface p-6 shadow-clay"
                data-test="pricing-seat-explainer"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="inline-flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary-soft text-primary-strong"
                        aria-hidden="true"
                    >
                        <Users class="size-5" />
                    </span>
                    <div>
                        <h2 class="text-h2">
                            {{ t('marketing.pricing.seat_title') }}
                        </h2>
                        <p class="mt-1 text-muted-foreground">
                            {{ t('marketing.pricing.seat_body') }}
                        </p>
                    </div>
                </div>
                <ul class="mt-5 space-y-2.5">
                    <li
                        v-for="(point, index) in seatPoints"
                        :key="index"
                        class="flex items-start gap-2.5 text-[0.9375rem]"
                    >
                        <span
                            :class="[
                                'mt-2 size-2 shrink-0 rounded-full',
                                index === 3 ? 'bg-muted-foreground' : 'bg-mint',
                            ]"
                            aria-hidden="true"
                        />
                        {{ point }}
                    </li>
                </ul>
            </article>
            <article class="rounded-dialog border bg-surface p-6 shadow-clay">
                <h2 class="text-h2">
                    {{ t('marketing.pricing.limits_title') }}
                </h2>
                <ul class="mt-5 space-y-4">
                    <li
                        v-for="(point, index) in limitPoints"
                        :key="index"
                        class="flex items-start gap-3"
                    >
                        <span
                            class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-sun-soft text-sun-strong"
                            aria-hidden="true"
                        >
                            <component :is="point.icon" class="size-4" />
                        </span>
                        <p class="pt-1.5 text-[0.9375rem]">{{ point.text }}</p>
                    </li>
                </ul>
            </article>
        </div>
    </section>

    <section
        v-if="plans.length"
        class="bg-surface-2 py-20"
        aria-labelledby="compare-title"
        data-test="pricing-compare"
    >
        <div class="mx-auto max-w-[1200px] px-4 sm:px-6">
            <SectionHeading
                id="compare-title"
                :title="t('marketing.pricing.compare_title')"
            />
            <div
                class="mx-auto mt-8 flex max-w-full scrollbar-none gap-1 overflow-x-auto rounded-chip border bg-surface p-1 md:hidden"
                role="tablist"
                :aria-label="t('marketing.pricing.plans_label')"
            >
                <button
                    v-for="plan in plans"
                    :key="plan.key"
                    type="button"
                    role="tab"
                    :aria-selected="comparePlan === plan.key"
                    :class="[
                        'h-11 flex-1 rounded-chip px-4 text-sm font-semibold whitespace-nowrap',
                        comparePlan === plan.key
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground',
                    ]"
                    @click="comparePlan = plan.key"
                >
                    {{ plan.name }}
                </button>
            </div>
            <div
                class="mt-6 overflow-hidden rounded-dialog border bg-surface shadow-clay md:mt-10"
                role="region"
                aria-labelledby="compare-title"
            >
                <table class="w-full text-[0.9375rem]">
                    <caption class="sr-only">
                        {{
                            t('marketing.pricing.compare_title')
                        }}
                    </caption>
                    <thead>
                        <tr class="bg-surface-2">
                            <th
                                scope="col"
                                class="sticky left-0 w-2/5 bg-surface-2 px-5 py-4 text-left text-sm font-semibold"
                            >
                                {{ t('marketing.pricing.feature') }}
                            </th>
                            <th
                                v-for="plan in plans"
                                :key="plan.key"
                                scope="col"
                                :class="[
                                    'px-4 py-4 text-center text-sm font-semibold',
                                    comparePlan === plan.key
                                        ? ''
                                        : 'max-md:hidden',
                                    plan.highlighted
                                        ? 'text-primary-strong'
                                        : '',
                                ]"
                            >
                                {{ plan.name }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="key in limitKeys" :key="key">
                            <th
                                scope="row"
                                class="sticky left-0 bg-surface px-5 py-3.5 text-left font-medium"
                            >
                                {{ limits.label(key) }}
                            </th>
                            <td
                                v-for="plan in plans"
                                :key="plan.key"
                                :class="[
                                    'px-4 py-3.5 text-center tabular-nums',
                                    comparePlan === plan.key
                                        ? ''
                                        : 'max-md:hidden',
                                    plan.highlighted
                                        ? 'bg-primary-soft/40'
                                        : '',
                                ]"
                            >
                                {{ limitCell(plan, key) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section
        class="mx-auto max-w-3xl px-4 py-20 sm:px-6"
        aria-labelledby="pricing-faq"
        data-test="pricing-faq"
    >
        <SectionHeading
            id="pricing-faq"
            :title="t('marketing.pricing.faq_title')"
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
            <Link :href="ctaHref">
                {{
                    authed
                        ? t('marketing.nav.dashboard')
                        : t('marketing.hero.cta_primary')
                }}
                <ArrowRight class="size-5" aria-hidden="true" />
            </Link>
        </Button>
    </CtaBand>
</template>

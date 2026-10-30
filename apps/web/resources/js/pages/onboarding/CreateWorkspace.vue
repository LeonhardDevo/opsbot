<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    Clock,
    Coins,
    Handshake,
    Info,
    Rocket,
} from '@lucide/vue';
import { useNow } from '@vueuse/core';
import { computed, ref } from 'vue';
import ConsentCheckbox from '@/components/common/ConsentCheckbox.vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import StepIndicator from '@/components/common/StepIndicator.vue';
import InputError from '@/components/InputError.vue';
import Callout from '@/components/opsbot/Callout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/composables/useLocale';
import { endpoints } from '@/lib/endpoints';
import { detectTimeZone } from '@/lib/format';
import { INTL_LOCALE, t } from '@/lib/i18n';
import { countryName, marketFor } from '@/lib/plans';
import { timezoneOptions } from '@/lib/regional';
import { cn } from '@/lib/utils';
import type { CountryOption, Locale } from '@/types';
import type { LegalVersionRef } from '@/types/legal';

const props = withDefaults(
    defineProps<{
        timezones?: string[];
        currencies?: string[];
        dpa?: LegalVersionRef | null;
        countries?: CountryOption[];
        defaultCountry?: string | null;
    }>(),
    {
        timezones: () => [],
        currencies: () => ['USD', 'EUR', 'RUB'],
        dpa: null,
        countries: () => [],
        defaultCountry: null,
    },
);

const page = usePage();
const { locale, locales } = useLocale();

const browserZone = detectTimeZone();

const countryCodes = computed(() =>
    props.countries.map((country) => country.code),
);

function serverMarket(code: string): {
    currency: string;
    gateway: string;
    available: boolean;
} {
    const option = props.countries.find((country) => country.code === code);

    return option
        ? {
              currency: option.currency,
              gateway: option.gateway,
              available: option.available,
          }
        : { ...marketFor(code), available: true };
}

function guessCountry(): string {
    if (
        props.defaultCountry &&
        countryCodes.value.includes(props.defaultCountry)
    ) {
        return props.defaultCountry;
    }

    const fallback = locale.value === 'ru' ? 'RU' : 'US';

    if (typeof navigator === 'undefined') {
        return fallback;
    }

    const region = (navigator.language.split('-')[1] ?? '').toUpperCase();

    return countryCodes.value.includes(region) ? region : fallback;
}

const initialCountry = guessCountry();

const form = useForm({
    name: '',
    timezone: props.timezones.includes(browserZone)
        ? browserZone
        : (props.timezones[0] ?? 'UTC'),
    country: initialCountry,
    currency: serverMarket(initialCountry).currency,
    locale: locale.value as Locale,
    dpa: false,
});

const market = computed(() => serverMarket(form.country));

const marketCaption = computed(() =>
    t('ui.onboarding.market_caption', {
        currency: market.value.currency,
        gateway: t(`ui.billing.gateways.${market.value.gateway}`),
    }),
);

const step = ref(0);
const steps = computed(() => [
    {
        key: 'name',
        title: t('ui.onboarding.steps.name'),
        description: t('ui.onboarding.steps.name_hint'),
    },
    {
        key: 'region',
        title: t('ui.onboarding.steps.region'),
        description: t('ui.onboarding.steps.region_hint'),
    },
    {
        key: 'review',
        title: t('ui.onboarding.steps.review'),
        description: t('ui.onboarding.steps.review_hint'),
    },
]);

const tzOptions = computed(() =>
    timezoneOptions(props.timezones.length ? props.timezones : [browserZone]),
);
const countryOptions = computed(() =>
    countryCodes.value
        .map((code) => ({
            value: code,
            label: countryName(code, INTL_LOCALE[locale.value] ?? 'en-US'),
            hint: serverMarket(code).currency,
        }))
        .sort((a, b) => a.label.localeCompare(b.label)),
);

const nameError = ref<string | null>(null);
const dpaError = ref<string | null>(null);

const dpaHref = computed(() => props.dpa?.url ?? endpoints.legal('dpa').url);

function dpaAccepted(): boolean {
    if (form.dpa) {
        dpaError.value = null;

        return true;
    }

    dpaError.value = t('ui.onboarding.dpa_required');

    return false;
}

function next(): void {
    if (step.value === 0) {
        if (form.name.trim().length < 2) {
            nameError.value = t('ui.onboarding.name_required');

            return;
        }

        nameError.value = null;
    }

    step.value = Math.min(step.value + 1, steps.value.length - 1);
}

function back(): void {
    step.value = Math.max(0, step.value - 1);
}

function validName(): boolean {
    if (form.name.trim().length < 2) {
        nameError.value = t('ui.onboarding.name_required');
        step.value = 0;

        return false;
    }

    nameError.value = null;

    return true;
}

function submit(): void {
    if (!validName() || !dpaAccepted()) {
        return;
    }

    form.currency = market.value.currency;
    form.post(endpoints.onboarding.store().url, {
        onError: (errors) => {
            if (errors.name) {
                step.value = 0;
            } else if (
                errors.timezone ||
                errors.currency ||
                errors.country ||
                errors.locale
            ) {
                step.value = 1;
            } else if (errors.dpa) {
                dpaError.value = errors.dpa;
            }
        },
    });
}

const now = useNow({ interval: 30_000 });

const preview = computed(() => {
    const intl = INTL_LOCALE[form.locale] ?? 'en-US';

    try {
        return {
            money: new Intl.NumberFormat(intl, {
                style: 'currency',
                currency: market.value.currency,
            }).format(4820),
            time: new Intl.DateTimeFormat(intl, {
                timeStyle: 'short',
                timeZone: form.timezone,
            }).format(now.value),
            date: new Intl.DateTimeFormat(intl, {
                dateStyle: 'full',
                timeZone: form.timezone,
            }).format(now.value),
        };
    } catch {
        return { money: `4820 ${market.value.currency}`, time: '—', date: '—' };
    }
});

const initial = computed(() =>
    (form.name.trim().charAt(0) || '?').toUpperCase(),
);
const hasWorkspaces = computed(() => (page.props.workspaces ?? []).length > 0);
</script>

<template>
    <Head :title="t('ui.onboarding.title')" />

    <div
        class="mx-auto grid w-full max-w-5xl flex-1 grid-cols-1 items-center gap-10 py-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]"
    >
        <section
            class="rounded-dialog border bg-surface p-5 shadow-clay-lg sm:p-8"
            aria-labelledby="onboarding-title"
            data-test="onboarding-step"
            :data-step="steps[step]?.key ?? 'name'"
        >
            <p
                class="inline-flex items-center gap-1.5 rounded-chip bg-mint-soft px-3 py-1 text-[0.8125rem] font-semibold text-mint-strong"
            >
                <Building2 class="size-3.5" aria-hidden="true" />
                {{ t('ui.onboarding.eyebrow') }}
            </p>
            <h1 id="onboarding-title" class="text-display-m mt-3">
                {{ t('ui.onboarding.title') }}
            </h1>
            <p class="mt-2 text-[0.9375rem] text-pretty text-muted-foreground">
                {{ t('ui.onboarding.subtitle') }}
            </p>

            <div class="mt-8 grid grid-cols-1 gap-8 md:grid-cols-[13rem_1fr]">
                <StepIndicator
                    :steps="steps"
                    :current="step"
                    data-test="onboarding-stepper"
                    @go="(index) => (step = index)"
                />

                <form class="min-w-0 space-y-6" @submit.prevent="submit">
                    <input
                        type="hidden"
                        name="timezone"
                        :value="form.timezone"
                    />
                    <input
                        type="hidden"
                        name="currency"
                        :value="market.currency"
                    />
                    <input type="hidden" name="country" :value="form.country" />
                    <input type="hidden" name="locale" :value="form.locale" />
                    <div v-if="step === 0" class="grid gap-2">
                        <Label for="workspace-name">{{
                            t('ui.onboarding.name_label')
                        }}</Label>
                        <Input
                            id="workspace-name"
                            v-model="form.name"
                            name="name"
                            v-focus
                            maxlength="80"
                            autocomplete="organization"
                            :placeholder="t('ui.onboarding.name_placeholder')"
                            :aria-invalid="
                                Boolean(nameError || form.errors.name)
                            "
                        />
                        <p class="text-caption">
                            {{ t('ui.onboarding.name_help') }}
                        </p>
                        <InputError :message="nameError ?? form.errors.name" />
                    </div>

                    <div v-else-if="step === 1" class="grid gap-5">
                        <div class="grid gap-2">
                            <Label for="workspace-country">{{
                                t('ui.onboarding.country')
                            }}</Label>
                            <SearchableSelect
                                id="workspace-country"
                                v-model="form.country"
                                :options="countryOptions"
                                :invalid="Boolean(form.errors.country)"
                                data-test="onboarding-country"
                            />
                            <p
                                class="text-caption inline-flex items-center gap-1.5"
                                data-test="onboarding-market-caption"
                            >
                                <Coins
                                    class="size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ marketCaption }}
                            </p>
                            <Callout
                                v-if="!market.available"
                                tone="sun"
                                :icon="Info"
                                role="status"
                                data-test="country-payments-unavailable"
                            >
                                {{ t('ui.onboarding.country_unavailable') }}
                            </Callout>
                            <InputError
                                :message="
                                    form.errors.country ?? form.errors.currency
                                "
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="workspace-timezone">{{
                                t('ui.fields.timezone')
                            }}</Label>
                            <SearchableSelect
                                id="workspace-timezone"
                                v-model="form.timezone"
                                :options="tzOptions"
                                :search-placeholder="
                                    t('ui.fields.timezone_search')
                                "
                                :invalid="Boolean(form.errors.timezone)"
                            />
                            <p class="text-caption">
                                {{
                                    t('ui.onboarding.timezone_detected', {
                                        zone: browserZone,
                                    })
                                }}
                            </p>
                            <InputError :message="form.errors.timezone" />
                        </div>
                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-[0.9375rem] font-semibold">
                                {{ t('ui.settings.workspace.locale') }}
                            </legend>
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    v-for="option in locales"
                                    :key="option.code"
                                    :class="
                                        cn(
                                            'press flex min-h-11 cursor-pointer items-center gap-2 rounded-control border px-3 py-2.5 text-[0.9375rem] font-medium has-[:focus-visible]:outline-3 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-ring',
                                            form.locale === option.code
                                                ? 'border-primary bg-primary-soft text-primary-strong'
                                                : 'bg-surface hover:border-primary',
                                        )
                                    "
                                >
                                    <input
                                        v-model="form.locale"
                                        type="radio"
                                        name="locale"
                                        :value="option.code"
                                        class="sr-only"
                                    />
                                    <span
                                        class="font-mono text-xs text-muted-foreground uppercase"
                                        >{{ option.code }}</span
                                    >
                                    {{ option.label }}
                                </label>
                            </div>
                            <InputError :message="form.errors.locale" />
                        </fieldset>
                    </div>

                    <div v-else class="grid gap-3">
                        <dl
                            class="divide-y overflow-hidden rounded-card border"
                        >
                            <div
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <dt class="text-sm text-muted-foreground">
                                    {{ t('ui.onboarding.name_label') }}
                                </dt>
                                <dd class="truncate text-sm font-semibold">
                                    {{ form.name }}
                                </dd>
                            </div>
                            <div
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <dt class="text-sm text-muted-foreground">
                                    {{ t('ui.onboarding.country') }}
                                </dt>
                                <dd class="text-right text-sm font-semibold">
                                    {{
                                        countryName(
                                            form.country,
                                            INTL_LOCALE[locale] ?? 'en-US',
                                        )
                                    }}
                                    <span
                                        class="block text-xs font-normal text-muted-foreground"
                                        >{{ marketCaption }}</span
                                    >
                                </dd>
                            </div>
                            <div
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <dt class="text-sm text-muted-foreground">
                                    {{ t('ui.fields.timezone') }}
                                </dt>
                                <dd class="truncate text-sm font-semibold">
                                    {{ form.timezone }}
                                </dd>
                            </div>
                            <div
                                class="flex items-center justify-between gap-4 px-4 py-3"
                            >
                                <dt class="text-sm text-muted-foreground">
                                    {{ t('ui.settings.workspace.locale') }}
                                </dt>
                                <dd class="text-sm font-semibold">
                                    {{
                                        locales.find(
                                            (l) => l.code === form.locale,
                                        )?.label
                                    }}
                                </dd>
                            </div>
                        </dl>
                        <p class="text-caption">
                            {{ t('ui.onboarding.review_note') }}
                        </p>
                    </div>

                    <div
                        class="grid gap-3 rounded-card border bg-surface-2 p-4"
                        data-test="onboarding-dpa"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-surface text-primary-strong shadow-clay-sm"
                                aria-hidden="true"
                            >
                                <Handshake class="size-[1.125rem]" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">
                                    {{ t('ui.legal.docs.dpa') }}
                                    <span
                                        v-if="dpa"
                                        class="ml-1 font-mono text-xs font-normal text-muted-foreground"
                                        >{{
                                            t('ui.legal.accept.version', {
                                                version: dpa.version,
                                            })
                                        }}</span
                                    >
                                </p>
                                <p
                                    class="mt-0.5 text-[0.8125rem] text-pretty text-muted-foreground"
                                >
                                    {{ t('ui.onboarding.dpa_body') }}
                                </p>
                            </div>
                        </div>
                        <ConsentCheckbox
                            id="dpa"
                            v-model="form.dpa"
                            name="dpa"
                            test-id="onboarding-accept-dpa"
                            :text="t('ui.onboarding.accept_dpa')"
                            :links="{
                                dpa: {
                                    href: dpaHref,
                                    label: t('ui.legal.dpa'),
                                },
                            }"
                            :error="dpaError ?? form.errors.dpa ?? null"
                            @update:model-value="dpaError = null"
                        />
                    </div>

                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <Button
                            v-if="step > 0"
                            type="button"
                            variant="ghost"
                            data-test="onboarding-back"
                            @click="back"
                        >
                            <ArrowLeft class="size-4" aria-hidden="true" />
                            {{ t('ui.common.back') }}
                        </Button>
                        <span v-else />
                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                v-if="step < steps.length - 1"
                                type="button"
                                variant="secondary"
                                data-test="onboarding-next"
                                @click="next"
                            >
                                {{
                                    step === 0
                                        ? t('ui.onboarding.customize')
                                        : t('ui.common.continue')
                                }}
                                <ArrowRight class="size-4" aria-hidden="true" />
                            </Button>
                            <Button
                                type="submit"
                                :disabled="form.processing"
                                data-test="onboarding-submit"
                            >
                                <Spinner v-if="form.processing" />
                                <Rocket
                                    v-else
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ t('ui.onboarding.create') }}
                            </Button>
                        </div>
                    </div>
                    <p v-if="step === 0" class="text-caption">
                        {{
                            t('ui.onboarding.defaults_hint', {
                                zone: form.timezone,
                                currency: market.currency,
                            })
                        }}
                    </p>
                </form>
            </div>
        </section>

        <aside class="hidden lg:block" :aria-label="t('ui.onboarding.preview')">
            <div class="relative mx-auto max-w-sm">
                <div
                    class="absolute -inset-6 rounded-[2rem] bg-[var(--glow-mint)] blur-2xl"
                    aria-hidden="true"
                />
                <div
                    class="relative space-y-4 rounded-dialog border bg-surface p-6 shadow-clay-lg"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="inline-flex size-12 items-center justify-center rounded-2xl bg-mint text-xl font-semibold text-mint-foreground shadow-clay-sm"
                        >
                            {{ initial }}
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-lg font-semibold">
                                {{
                                    form.name ||
                                    t('ui.onboarding.name_placeholder')
                                }}
                            </p>
                            <p class="text-caption">
                                {{ t('ui.onboarding.preview_caption') }}
                            </p>
                        </div>
                    </div>
                    <dl class="grid grid-cols-2 gap-3">
                        <div class="rounded-2xl bg-surface-2 p-3">
                            <dt
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Coins class="size-3.5" aria-hidden="true" />{{
                                    t('ui.fields.currency')
                                }}
                            </dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums">
                                {{ preview.money }}
                            </dd>
                        </div>
                        <div class="rounded-2xl bg-surface-2 p-3">
                            <dt
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Clock class="size-3.5" aria-hidden="true" />{{
                                    t('ui.onboarding.local_time')
                                }}
                            </dt>
                            <dd class="mt-1 text-lg font-semibold tabular-nums">
                                {{ preview.time }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Building2 class="size-4" aria-hidden="true" />
                        {{ preview.date }}
                    </p>
                    <p
                        class="flex items-center gap-2 rounded-2xl bg-primary-soft px-3 py-2 text-[0.8125rem] font-medium text-primary-strong"
                    >
                        <Coins class="size-4 shrink-0" aria-hidden="true" />
                        {{ marketCaption }}
                    </p>
                </div>
                <p
                    v-if="!hasWorkspaces"
                    class="mt-6 text-center text-sm text-muted-foreground"
                >
                    {{ t('ui.onboarding.first_workspace') }}
                </p>
            </div>
        </aside>
    </div>
</template>

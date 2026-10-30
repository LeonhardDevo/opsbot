<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { useLimits } from '@/composables/useLimits';
import { isFreePlan, monthlyEquivalent, yearlySavings } from '@/lib/billing';
import type { PlanAction } from '@/lib/billing';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BillingInterval, Plan } from '@/types';

const props = withDefaults(
    defineProps<{
        plan: Plan;
        interval: BillingInterval;
        action?: PlanAction | 'signup' | null;
        processing?: boolean;
        disabled?: boolean;
    }>(),
    { action: null, processing: false, disabled: false },
);

const emit = defineEmits<{ select: [plan: Plan] }>();

const fmt = useFormat();
const limits = useLimits();

const price = computed(() => monthlyEquivalent(props.plan, props.interval));
const isFree = computed(() => isFreePlan(props.plan));
const savings = computed(() => yearlySavings(props.plan));

const ctaLabel = computed(() => {
    switch (props.action) {
        case 'current':
            return t('ui.billing.current_plan');
        case 'upgrade':
            return t('ui.billing.upgrade_to', { plan: props.plan.name });
        case 'downgrade':
            return t('ui.billing.downgrade_to', { plan: props.plan.name });
        case 'switch-interval':
            return props.interval === 'year'
                ? t('ui.billing.switch_yearly')
                : t('ui.billing.switch_monthly');
        case 'signup':
            return isFree.value
                ? t('ui.billing.start_free')
                : t('ui.billing.start_trial');
        default:
            return '';
    }
});

const limitEntries = computed(() =>
    props.plan.features.length ? [] : Object.entries(props.plan.limits ?? {}),
);
</script>

<template>
    <article
        :class="
            cn(
                'relative flex h-full flex-col rounded-card bg-card p-6',
                plan.highlighted
                    ? 'border-2 border-primary shadow-clay-lg'
                    : 'border shadow-clay',
            )
        "
        :aria-labelledby="`plan-${plan.key}-name`"
        :data-test="`plan-card-${plan.key}`"
    >
        <div class="flex items-center justify-between gap-2">
            <h3 :id="`plan-${plan.key}-name`" class="text-h2">
                {{ plan.name }}
            </h3>
            <span
                v-if="plan.highlighted"
                class="inline-flex items-center rounded-chip bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary-strong"
            >
                {{ t('ui.billing.popular') }}
            </span>
        </div>
        <p class="mt-1.5 min-h-10 text-sm text-muted-foreground">
            {{ plan.description }}
        </p>

        <div class="mt-5 flex items-baseline gap-1.5">
            <span class="text-[2rem] leading-none font-[650] tabular-nums">
                {{
                    fmt.money(price, plan.currency, {
                        fractionDigits: Number.isInteger(price) ? 0 : 2,
                    })
                }}
            </span>
            <span v-if="!isFree" class="text-sm text-muted-foreground">{{
                t('ui.billing.per_month')
            }}</span>
        </div>
        <p class="mt-1 min-h-5 text-xs text-muted-foreground">
            <template v-if="isFree">{{
                t('ui.billing.free_forever')
            }}</template>
            <template v-else-if="interval === 'year'">
                {{
                    t('ui.billing.billed_yearly', {
                        amount: fmt.money(
                            plan.price_yearly_minor / 100,
                            plan.currency,
                        ),
                    })
                }}
                <span v-if="savings > 0" class="font-semibold text-mint-strong">
                    ·
                    {{
                        t('ui.billing.save', { percent: fmt.percent(savings) })
                    }}
                </span>
            </template>
            <template v-else>{{ t('ui.billing.billed_monthly') }}</template>
        </p>

        <div v-if="action" class="mt-6">
            <Button
                class="w-full"
                :variant="
                    action === 'current'
                        ? 'outline'
                        : plan.highlighted || action === 'signup'
                          ? 'default'
                          : 'secondary'
                "
                :disabled="disabled || processing || action === 'current'"
                :data-test="`plan-subscribe-${plan.key}`"
                @click="emit('select', plan)"
            >
                <Spinner v-if="processing" />
                {{ ctaLabel }}
            </Button>
        </div>
        <slot name="cta" />
        <ul class="mt-6 space-y-2.5 border-t pt-5 text-sm">
            <li
                v-for="feature in plan.features"
                :key="feature"
                class="flex gap-2.5"
            >
                <Check
                    class="mt-0.5 size-4 shrink-0 text-success"
                    aria-hidden="true"
                />
                <span>{{ feature }}</span>
            </li>
            <li
                v-for="[key, value] in limitEntries"
                :key="key"
                class="flex gap-2.5 text-muted-foreground"
            >
                <Check
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground/70"
                    aria-hidden="true"
                />
                <span>
                    {{ limits.label(key) }}:
                    <strong class="font-semibold text-foreground">{{
                        value === null
                            ? t('ui.billing.unlimited')
                            : limits.value(key, value)
                    }}</strong>
                </span>
            </li>
        </ul>
    </article>
</template>

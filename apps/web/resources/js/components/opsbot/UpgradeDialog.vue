<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, CircleAlert, CircleArrowUp } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { useUpgrade } from '@/composables/useUpgrade';
import { endpoints, withQuery } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';
import {
    minorToAmount,
    monthlyMinor,
    planBlockers,
    planRank,
    yearlyDiscountMonths,
} from '@/lib/plans';
import { cn } from '@/lib/utils';
import type {
    BillingInterval,
    LimitEntry,
    LimitKey,
    Plan,
    PlanKey,
} from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        plans?: Plan[];
        currentKey?: PlanKey | null;
        usage?: Partial<Record<LimitKey, LimitEntry>>;
        initialInterval?: BillingInterval;
        canManage?: boolean;
        trialing?: boolean;
        managed?: boolean;
        currentInterval?: BillingInterval | null;
    }>(),
    {
        plans: () => [],
        currentKey: null,
        usage: () => ({}),
        initialInterval: 'month',
        canManage: false,
        trialing: false,
        managed: false,
        currentInterval: null,
    },
);

const page = usePage();
const fmt = useFormat();
const { open, focusKey } = useUpgrade();

const interval = ref<BillingInterval>(props.initialInterval);
const selected = ref<PlanKey | null>(null);
const submitting = ref(false);

const current = computed<PlanKey>(
    () => props.currentKey ?? (page.props.workspace?.plan as PlanKey) ?? 'free',
);

const trialing = computed(() => props.trialing);

const owned = computed<PlanKey | null>(() =>
    trialing.value ? null : current.value,
);

function isOwned(key: PlanKey): boolean {
    if (key !== owned.value) {
        return false;
    }

    return !props.managed || interval.value === props.currentInterval;
}

const paidPlans = computed(() =>
    [...props.plans]
        .filter((plan) => plan.key !== 'free')
        .sort((a, b) => planRank(a.key) - planRank(b.key)),
);

const focusMessage = computed(() => {
    const key = focusKey.value;

    if (!key) {
        return null;
    }

    const entry = props.usage[key];

    if (entry && entry.limit !== null) {
        return t('ui.upgrade.focus', {
            limit: tOr(`ui.billing.usage.${key}`, key),
            used: fmt.value.number(entry.used),
            total: fmt.value.number(entry.limit),
        });
    }

    return (
        page.props.flash?.error ??
        tOr(`ui.upgrade.reason.${key}`, t('ui.upgrade.body'))
    );
});

watch(open, (value) => {
    if (value) {
        interval.value = props.initialInterval;
        const next = paidPlans.value.find(
            (plan) =>
                (trialing.value
                    ? planRank(plan.key) >= planRank(current.value)
                    : planRank(plan.key) > planRank(current.value)) &&
                planBlockers(plan, props.usage).length === 0,
        );
        selected.value =
            props.managed &&
            props.currentInterval !== null &&
            interval.value !== props.currentInterval &&
            current.value !== 'free'
                ? current.value
                : (next?.key ?? null);
    }
});

function disabledReason(plan: Plan): string | null {
    const blockers = planBlockers(plan, props.usage);

    if (!blockers.length) {
        return null;
    }

    return blockers
        .map((blocker) =>
            t('ui.upgrade.blocked', {
                limit: tOr(`ui.billing.usage.${blocker.key}`, blocker.key),
                used: fmt.value.number(blocker.used),
            }),
        )
        .join(' · ');
}

function price(plan: Plan): string {
    return fmt.value.money(
        minorToAmount(monthlyMinor(plan, interval.value)),
        plan.currency,
    );
}

function submit(): void {
    if (!selected.value || isOwned(selected.value)) {
        return;
    }

    submitting.value = true;
    router.post(
        props.managed
            ? endpoints.settings.billing.changePlan().url
            : endpoints.settings.billing.checkout().url,
        { plan: selected.value, interval: interval.value },
        {
            preserveScroll: true,
            onFinish: () => (submitting.value = false),
        },
    );
}

const billingHref = computed(() =>
    withQuery(endpoints.settings.billing.show().url, {
        upgrade: focusKey.value ?? 1,
    }),
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90svh] overflow-y-auto sm:max-w-3xl"
            data-test="upgrade-dialog"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <CircleArrowUp
                        class="size-5 text-primary-strong"
                        aria-hidden="true"
                    />
                    {{ t('ui.upgrade.title') }}
                </DialogTitle>
                <DialogDescription>{{
                    t('ui.upgrade.body')
                }}</DialogDescription>
            </DialogHeader>

            <p
                v-if="focusMessage"
                class="flex items-start gap-2 rounded-2xl bg-sun-soft px-3 py-2.5 text-sm"
            >
                <CircleAlert
                    class="mt-0.5 size-4 shrink-0 text-sun-strong"
                    aria-hidden="true"
                />
                {{ focusMessage }}
            </p>

            <template v-if="paidPlans.length">
                <SegmentedControl
                    v-model="interval"
                    :label="t('ui.billing.interval')"
                    :options="[
                        { value: 'month', label: t('ui.billing.monthly') },
                        { value: 'year', label: t('ui.billing.yearly') },
                    ]"
                    class="self-start"
                />
                <div
                    role="radiogroup"
                    :aria-label="t('ui.upgrade.choose')"
                    class="grid gap-3 sm:grid-cols-3"
                >
                    <button
                        v-for="plan in paidPlans"
                        :key="plan.key"
                        type="button"
                        role="radio"
                        :aria-checked="selected === plan.key"
                        :disabled="
                            !canManage ||
                            isOwned(plan.key) ||
                            Boolean(disabledReason(plan))
                        "
                        :class="
                            cn(
                                'press flex flex-col gap-2 rounded-card border bg-surface p-4 text-left shadow-clay-sm disabled:cursor-not-allowed disabled:opacity-60',
                                selected === plan.key
                                    ? 'border-primary ring-2 ring-primary/40'
                                    : 'hover:border-primary',
                            )
                        "
                        data-test="upgrade-plan-option"
                        :data-plan="plan.key"
                        @click="selected = plan.key"
                    >
                        <span class="flex items-center gap-2">
                            <span class="font-semibold">{{ plan.name }}</span>
                            <span
                                v-if="plan.key === current"
                                class="rounded-chip bg-primary-soft px-2 text-xs font-semibold text-primary-strong"
                                >{{
                                    trialing
                                        ? t('ui.billing.status.trialing')
                                        : t('ui.billing.current_plan')
                                }}</span
                            >
                            <span
                                v-else-if="plan.highlighted"
                                class="rounded-chip bg-mint-soft px-2 text-xs font-semibold text-mint-strong"
                                >{{ t('ui.billing.popular') }}</span
                            >
                        </span>
                        <span class="text-kpi">{{ price(plan) }}</span>
                        <span class="text-caption">{{
                            interval === 'year'
                                ? t('ui.billing.per_month_yearly')
                                : t('ui.billing.per_month')
                        }}</span>
                        <span
                            v-if="
                                interval === 'year' &&
                                yearlyDiscountMonths(plan) > 0
                            "
                            class="text-xs font-semibold text-mint-strong"
                            >{{ t('ui.billing.months_free') }}</span
                        >
                        <ul class="space-y-1 text-sm">
                            <li
                                v-for="feature in plan.features.slice(0, 4)"
                                :key="feature"
                                class="flex gap-1.5"
                            >
                                <Check
                                    class="mt-1 size-3.5 shrink-0 text-success"
                                    aria-hidden="true"
                                />
                                <span class="text-pretty">{{ feature }}</span>
                            </li>
                        </ul>
                        <span
                            v-if="disabledReason(plan)"
                            class="text-xs font-medium text-coral-strong"
                            >{{ disabledReason(plan) }}</span
                        >
                    </button>
                </div>
                <p class="text-caption">
                    {{
                        managed
                            ? t('ui.billing.change_confirm_body')
                            : t('ui.upgrade.downgrade_note')
                    }}
                </p>
            </template>

            <DialogFooter>
                <Button variant="ghost" @click="open = false">{{
                    t('ui.common.cancel')
                }}</Button>
                <Button
                    v-if="paidPlans.length && canManage"
                    :disabled="!selected || isOwned(selected) || submitting"
                    data-test="upgrade-submit"
                    :data-mode="managed ? 'change' : 'checkout'"
                    @click="submit"
                >
                    <Spinner v-if="submitting" />
                    {{
                        managed
                            ? t('ui.billing.change_confirm')
                            : t('ui.upgrade.checkout')
                    }}
                </Button>
                <Button v-else-if="!paidPlans.length" as-child>
                    <Link :href="billingHref" @click="open = false">{{
                        t('ui.upgrade.cta')
                    }}</Link>
                </Button>
                <p v-else class="text-sm text-muted-foreground">
                    {{ t('ui.billing.read_only') }}
                </p>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

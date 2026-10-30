<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    AlarmClock,
    CalendarClock,
    CircleArrowUp,
    CircleCheck,
    CirclePause,
    CreditCard,
    ExternalLink,
    FileText,
    Globe,
    HardDrive,
    Info,
    MessagesSquare,
    Receipt,
    RotateCcw,
    Sparkles,
    Users,
    CircleX,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import BillingMeter from '@/components/opsbot/BillingMeter.vue';
import Callout from '@/components/opsbot/Callout.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import UpgradeDialog from '@/components/opsbot/UpgradeDialog.vue';
import SegmentedControl from '@/components/common/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useCountdown } from '@/composables/useCountdown';
import { useFormat } from '@/composables/useFormat';
import { usePageUpgradeDialog, useUpgrade } from '@/composables/useUpgrade';
import { endpoints } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';
import { minorToAmount, nextPlan, planPriceMinor } from '@/lib/plans';
import type { BillingInterval, BillingProps, LimitKey } from '@/types/opsbot';

const props = withDefaults(defineProps<BillingProps>(), {
    checkout: null,
    subscription: null,
    market: null,
    capabilities: () => ({ portal: false, manage: false }),
});

usePageUpgradeDialog();

const fmt = useFormat();
const upgrade = useUpgrade();

const interval = ref<BillingInterval>(props.interval);
const upgradeInterval = ref<BillingInterval>(props.interval);

watch(interval, (value) => {
    if (value !== props.interval) {
        upgradeInterval.value = value;
        upgrade.show(null);
    }
});

watch(upgrade.open, (open) => {
    if (!open) {
        interval.value = props.interval;
    }
});

const trial = useCountdown(() => props.trial_ends_at);
const grace = useCountdown(() => props.paused_at);

const banner = computed(() => {
    if (props.subscriptionState === 'paused') {
        return 'paused';
    }

    if (props.subscriptionState === 'grace') {
        return 'grace';
    }

    if (props.cancel_at_period_end) {
        return 'canceling';
    }

    if (props.status === 'trialing') {
        return 'trialing';
    }

    return null;
});

const price = computed(() =>
    fmt.value.money(
        minorToAmount(planPriceMinor(props.plan, props.interval)),
        props.plan.currency,
    ),
);

const meterIcons: Partial<Record<LimitKey, typeof Users>> = {
    seats: Users,
    documents: FileText,
    storage_mb: HardDrive,
    ai_queries_monthly: MessagesSquare,
};

const meterHints = computed<Partial<Record<LimitKey, string>>>(() => ({
    seats: t('ui.billing.hints.seats', {
        web: props.seats_breakdown.web_members,
        telegram:
            props.seats_breakdown.employees_active +
            props.seats_breakdown.employees_invited,
        invited: props.seats_breakdown.employees_invited,
    }),
    documents: t('ui.billing.hints.documents'),
    ai_queries_monthly: t('ui.billing.hints.ai', {
        start: fmt.value.date(`${props.ai_month.starts_on}T12:00:00`),
        reset: fmt.value.date(`${props.ai_month.resets_on}T12:00:00`),
        zone: props.ai_month.timezone,
    }),
}));

const following = computed(() => nextPlan(props.plans, props.plan.key));

const managed = computed(
    () =>
        props.can.manage &&
        props.capabilities.manage &&
        props.subscription !== null &&
        ['active', 'past_due'].includes(props.subscription.status),
);

const scheduledPlan = computed(() => {
    const key = props.subscription?.scheduled_plan ?? null;

    return key ? (props.plans.find((plan) => plan.key === key) ?? null) : null;
});

const lifecycleBusy = ref<'cancel' | 'resume' | null>(null);
const cancelOpen = ref(false);

function lifecycle(action: 'cancel' | 'resume'): void {
    const endpoint =
        action === 'cancel'
            ? endpoints.settings.billing.cancel()
            : endpoints.settings.billing.resume();

    router.post(
        endpoint.url,
        {},
        {
            preserveScroll: true,
            onStart: () => (lifecycleBusy.value = action),
            onFinish: () => {
                lifecycleBusy.value = null;
                cancelOpen.value = false;
            },
        },
    );
}

const activating = ref(false);
const updating = ref(false);
const poll = usePoll(
    2000,
    {
        only: [
            'plan',
            'status',
            'subscriptionState',
            'usage',
            'trial_ends_at',
            'current_period_end',
            'cancel_at_period_end',
            'subscription',
        ],
    },
    { autoStart: false },
);

onMounted(() => {
    const params = new URLSearchParams(window.location.search);

    if (props.checkout === 'success') {
        activating.value = true;
        poll.start();
        setTimeout(() => {
            poll.stop();
            activating.value = false;
        }, 20000);
    } else if (props.checkout === 'updated') {
        updating.value = true;
        poll.start();
        setTimeout(() => {
            poll.stop();
            updating.value = false;
        }, 8000);
    }

    const focus = params.get('upgrade');

    if (focus) {
        upgrade.show(focus === '1' ? null : (focus as LimitKey));
    }
});

watch(
    () => props.plan.key,
    () => {
        if (activating.value) {
            activating.value = false;
            poll.stop();
        }
    },
);

onBeforeUnmount(() => poll.stop());

const portalBusy = ref(false);

function openPortal(): void {
    portalBusy.value = true;
    router.post(
        endpoints.settings.billing.portal().url,
        {},
        {
            preserveScroll: true,
            onFinish: () => (portalBusy.value = false),
        },
    );
}

const statusTone = computed(() => {
    switch (props.status) {
        case 'active':
            return 'success';
        case 'trialing':
            return 'mint';
        case 'past_due':
        case 'incomplete':
            return 'coral';
        default:
            return 'neutral';
    }
});

const gatewayCaption = computed(() => {
    if (props.gateway === 'fake') {
        return t('ui.billing.gateway_fake');
    }

    if (props.market) {
        return t('ui.billing.market_line', {
            country: props.market.country_name,
            currency: props.plan.currency,
            gateway: t(`ui.billing.gateways.${props.gateway}`),
        });
    }

    return t('ui.billing.gateway_line', {
        gateway: t(`ui.billing.gateways.${props.gateway}`),
        currency: props.plan.currency,
    });
});

const marketUnavailable = computed(
    () => props.market !== null && !props.market.available && !managed.value,
);
</script>

<template>
    <Head :title="t('ui.billing.title')" />

    <div class="flex flex-col gap-6">
        <header class="space-y-1">
            <h1 class="text-h1">{{ t('ui.billing.title') }}</h1>
            <p
                class="flex items-center gap-1.5 text-sm text-muted-foreground"
                data-test="billing-market"
                :data-gateway="gateway"
            >
                <Globe class="size-4 shrink-0" aria-hidden="true" />
                {{ gatewayCaption }}
            </p>
        </header>

        <Callout
            v-if="marketUnavailable"
            tone="neutral"
            :icon="Info"
            data-test="billing-market-unavailable"
            >{{ t('ui.billing.gateway_unavailable') }}</Callout
        >

        <Callout v-if="activating" tone="info" :icon="Sparkles">{{
            t('ui.billing.activating')
        }}</Callout>
        <Callout
            v-else-if="updating"
            tone="info"
            :icon="RotateCcw"
            role="status"
            data-test="billing-updating"
            >{{ t('ui.billing.updating') }}</Callout
        >
        <Callout
            v-else-if="checkout === 'cancelled'"
            tone="neutral"
            :icon="Info"
            >{{ t('ui.billing.checkout_cancelled') }}</Callout
        >

        <Callout
            v-if="banner === 'trialing'"
            tone="mint"
            :icon="Sparkles"
            :title="
                t('ui.billing.banner.trial_title', {
                    left: trial.label.value ?? '—',
                })
            "
            data-test="billing-state-banner"
            data-state="trialing"
        >
            <span data-test="billing-countdown">{{
                t('ui.billing.banner.trial_body', {
                    date: fmt.date(trial_ends_at),
                })
            }}</span>
            <template v-if="can.manage" #actions>
                <Button size="sm" @click="upgrade.show(null)">{{
                    t('ui.billing.choose_plan')
                }}</Button>
            </template>
        </Callout>
        <Callout
            v-else-if="banner === 'grace'"
            tone="sun"
            :icon="AlarmClock"
            :title="t('ui.billing.banner.grace_title')"
            data-test="billing-state-banner"
            data-state="grace"
        >
            <span data-test="billing-countdown">{{
                t('ui.billing.banner.grace_body', {
                    left: grace.label.value ?? '—',
                })
            }}</span>
            <p
                v-if="gateway === 'yookassa'"
                class="mt-1"
                data-test="billing-grace-retry"
            >
                {{ t('ui.billing.banner.grace_retry') }}
            </p>
            <template v-if="can.portal" #actions>
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="portalBusy"
                    data-test="billing-grace-portal"
                    @click="openPortal"
                    >{{ t('ui.billing.update_payment') }}</Button
                >
            </template>
        </Callout>
        <Callout
            v-else-if="banner === 'paused'"
            tone="coral"
            :icon="CirclePause"
            :title="t('ui.billing.banner.paused_title')"
            data-test="billing-state-banner"
            data-state="paused"
        >
            {{ t('ui.billing.banner.paused_body') }}
            <ul class="mt-1 list-disc pl-5">
                <li
                    v-for="item in [
                        'incidents',
                        'tasks',
                        'reminders',
                        'notifications',
                    ]"
                    :key="item"
                >
                    {{ t(`ui.billing.banner.still_${item}`) }}
                </li>
            </ul>
            <template v-if="can.manage" #actions>
                <Button size="sm" @click="upgrade.show(null)">{{
                    t('ui.billing.pay')
                }}</Button>
            </template>
        </Callout>
        <Callout
            v-else-if="banner === 'canceling'"
            tone="neutral"
            :icon="CalendarClock"
            :title="
                t('ui.billing.banner.cancel_title', {
                    date: fmt.date(current_period_end),
                })
            "
            data-test="billing-state-banner"
            data-state="canceling"
        >
            {{ t('ui.billing.banner.cancel_body') }}
            <template v-if="managed || can.portal" #actions>
                <Button
                    v-if="managed"
                    size="sm"
                    variant="secondary"
                    :disabled="lifecycleBusy !== null"
                    data-test="billing-resume"
                    @click="lifecycle('resume')"
                >
                    <Spinner v-if="lifecycleBusy === 'resume'" />
                    <RotateCcw v-else class="size-4" aria-hidden="true" />
                    {{ t('ui.billing.resume') }}
                </Button>
                <Button
                    v-else
                    size="sm"
                    variant="secondary"
                    :disabled="portalBusy"
                    @click="openPortal"
                    >{{ t('ui.billing.resume') }}</Button
                >
            </template>
        </Callout>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="flex min-w-0 flex-col gap-6">
                <SectionCard
                    data-test="billing-plan-card"
                    :data-plan="plan.key"
                    :data-status="status ?? 'none'"
                >
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-h2">{{ plan.name }}</h2>
                                <OpsBadge
                                    v-if="status"
                                    :label="
                                        tOr(
                                            `billing.statuses.${status}`,
                                            status,
                                        )
                                    "
                                    :tone="statusTone"
                                />
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{ plan.description }}
                            </p>
                            <p class="pt-2">
                                <span class="text-kpi">{{ price }}</span>
                                <span class="text-sm text-muted-foreground">
                                    {{
                                        interval === 'year'
                                            ? t('ui.billing.per_year')
                                            : t('ui.billing.per_month')
                                    }}
                                </span>
                            </p>
                            <p
                                v-if="scheduledPlan && current_period_end"
                                class="flex items-center gap-1.5 text-sm font-medium text-primary-strong"
                                data-test="billing-scheduled-change"
                            >
                                <CalendarClock
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {{
                                    t('ui.billing.scheduled_change', {
                                        plan: scheduledPlan.name,
                                        date: fmt.date(current_period_end),
                                    })
                                }}
                            </p>
                            <p v-if="current_period_end" class="text-caption">
                                {{
                                    cancel_at_period_end
                                        ? t('ui.billing.cancels_on', {
                                              date: fmt.date(
                                                  current_period_end,
                                              ),
                                          })
                                        : t('ui.billing.renews_on', {
                                              date: fmt.date(
                                                  current_period_end,
                                              ),
                                          })
                                }}
                            </p>
                        </div>
                        <div
                            class="flex flex-col items-start gap-3 sm:items-end"
                        >
                            <SegmentedControl
                                v-if="can.manage && plan.key !== 'free'"
                                v-model="interval"
                                :label="t('ui.billing.interval')"
                                :options="[
                                    {
                                        value: 'month',
                                        label: t('ui.billing.monthly'),
                                    },
                                    {
                                        value: 'year',
                                        label: t('ui.billing.yearly_gift'),
                                    },
                                ]"
                                data-test="billing-interval"
                            />
                            <div v-if="can.manage" class="flex flex-wrap gap-2">
                                <Button
                                    data-test="billing-change-plan"
                                    @click="upgrade.show(null)"
                                >
                                    <CircleArrowUp
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ t('ui.billing.change_plan') }}
                                </Button>
                                <Button
                                    v-if="can.portal && gateway === 'stripe'"
                                    variant="secondary"
                                    :disabled="portalBusy"
                                    data-test="billing-portal"
                                    @click="openPortal"
                                >
                                    <Spinner v-if="portalBusy" />
                                    <CreditCard
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ t('ui.billing.manage') }}
                                </Button>
                                <Button
                                    v-if="managed && !cancel_at_period_end"
                                    variant="ghost"
                                    data-test="billing-cancel"
                                    @click="cancelOpen = true"
                                >
                                    <CircleX
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    {{ t('ui.billing.cancel') }}
                                </Button>
                            </div>
                            <p
                                v-if="can.manage && gateway === 'yookassa'"
                                class="text-caption max-w-xs sm:text-right"
                                data-test="billing-yookassa-note"
                            >
                                {{ t('ui.billing.yookassa_note') }}
                            </p>
                            <p v-else class="text-sm text-muted-foreground">
                                {{ t('ui.billing.read_only') }}
                            </p>
                        </div>
                    </div>
                </SectionCard>

                <SectionCard
                    :title="t('ui.billing.usage_title')"
                    :description="t('ui.billing.usage_description')"
                >
                    <div class="divide-y">
                        <BillingMeter
                            v-for="key in [
                                'seats',
                                'documents',
                                'storage_mb',
                                'ai_queries_monthly',
                            ] as const"
                            :key="key"
                            :limit-key="key"
                            :label="t(`ui.billing.usage.${key}`)"
                            :entry="usage[key]"
                            :icon="meterIcons[key]"
                            :hint="meterHints[key] ?? null"
                            :can-upgrade="can.manage"
                            @upgrade="upgrade.show"
                        />
                    </div>
                    <p class="text-caption mt-3">
                        {{
                            t('ui.billing.static_limits', {
                                pages: fmt.number(
                                    plan.limits.max_pages_per_document ?? 0,
                                ),
                                size: fmt.number(plan.limits.max_file_mb ?? 0),
                            })
                        }}
                    </p>
                </SectionCard>

                <SectionCard
                    :title="t('ui.billing.at_limit_title')"
                    :icon="Info"
                >
                    <ul class="grid gap-2 text-sm">
                        <li
                            v-for="item in ['creation', 'answers', 'escalate']"
                            :key="item"
                            class="flex gap-2"
                        >
                            <CircleCheck
                                class="mt-0.5 size-4 shrink-0 text-success"
                                aria-hidden="true"
                            />
                            {{ t(`ui.billing.at_limit.${item}`) }}
                        </li>
                    </ul>
                </SectionCard>

                <SectionCard
                    :title="t('ui.billing.invoices_title')"
                    :icon="Receipt"
                    data-test="billing-invoices"
                >
                    <p
                        v-if="!invoices.length"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('ui.billing.invoices_empty') }}
                    </p>
                    <ul v-else class="divide-y">
                        <li
                            v-for="invoice in invoices"
                            :key="invoice.id"
                            class="flex flex-wrap items-center gap-3 py-2.5 text-sm"
                            data-test="billing-invoice-row"
                        >
                            <span class="w-28">{{
                                fmt.date(invoice.date)
                            }}</span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ invoice.number ?? '—' }}</span
                            >
                            <span class="ml-auto font-semibold tabular-nums">{{
                                fmt.money(
                                    minorToAmount(invoice.amount_minor),
                                    invoice.currency,
                                )
                            }}</span>
                            <OpsBadge
                                :label="
                                    t(
                                        `ui.billing.invoice_status.${invoice.status}`,
                                    )
                                "
                                :tone="
                                    invoice.status === 'paid'
                                        ? 'success'
                                        : invoice.status === 'open'
                                          ? 'sun'
                                          : 'neutral'
                                "
                            />
                            <a
                                v-if="invoice.url"
                                :href="invoice.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex size-11 items-center justify-center rounded-xl hover:bg-surface-2"
                                :aria-label="t('ui.billing.open_invoice')"
                            >
                                <ExternalLink
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </a>
                        </li>
                    </ul>
                </SectionCard>
            </div>

            <aside class="min-w-0">
                <SectionCard :title="t('ui.billing.compare_title')">
                    <template v-if="following">
                        <p class="mb-3 text-sm font-semibold">
                            {{
                                t('ui.billing.next_plan', {
                                    plan: following.name,
                                })
                            }}
                        </p>
                        <dl
                            class="grid grid-cols-[1fr_auto_auto] gap-x-3 gap-y-2 text-sm"
                        >
                            <template
                                v-for="key in [
                                    'seats',
                                    'documents',
                                    'ai_queries_monthly',
                                ] as const"
                                :key="key"
                            >
                                <dt class="text-muted-foreground">
                                    {{ t(`ui.billing.usage.${key}`) }}
                                </dt>
                                <dd class="tabular-nums">
                                    {{
                                        plan.limits[key] === null
                                            ? '∞'
                                            : fmt.number(plan.limits[key])
                                    }}
                                </dd>
                                <dd
                                    class="font-semibold text-primary-strong tabular-nums"
                                >
                                    {{
                                        following.limits[key] === null
                                            ? '∞'
                                            : fmt.number(following.limits[key])
                                    }}
                                </dd>
                            </template>
                        </dl>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('ui.billing.top_plan') }}
                    </p>
                    <template #footer>
                        <Button size="sm" variant="secondary" as-child>
                            <Link :href="endpoints.pricing()">{{
                                t('ui.billing.all_plans')
                            }}</Link>
                        </Button>
                    </template>
                </SectionCard>
            </aside>
        </div>
    </div>

    <UpgradeDialog
        :plans="plans"
        :current-key="plan.key"
        :usage="usage"
        :initial-interval="upgradeInterval"
        :can-manage="can.manage"
        :trialing="status === 'trialing'"
        :managed="managed"
        :current-interval="managed ? props.interval : null"
    />

    <ConfirmDialog
        v-if="managed"
        v-model:open="cancelOpen"
        destructive
        :processing="lifecycleBusy === 'cancel'"
        :title="t('ui.billing.cancel_confirm_title')"
        :description="
            t('ui.billing.cancel_confirm_body', {
                date: current_period_end ? fmt.date(current_period_end) : '—',
            })
        "
        :confirm-label="t('ui.billing.cancel')"
        confirm-test="billing-cancel-confirm"
        @confirm="lifecycle('cancel')"
    />
</template>

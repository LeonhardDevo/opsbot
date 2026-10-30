<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { AlarmClock, CreditCard, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useCan } from '@/composables/useCan';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';

const page = usePage();
const fmt = useFormat();
const { can } = useCan();
const { currentUrl } = useCurrentUrl();

const billingPath = toUrl(endpoints.settings.billing.show());

const alert = computed(() =>
    currentUrl.value.startsWith(billingPath)
        ? null
        : (page.props.billing?.alert ?? null),
);
const canManage = computed(() => can('billing.manage'));
const portalBusy = ref(false);
const pastDue = computed(() => alert.value?.status === 'past_due');
const portalAvailable = computed(
    () => pastDue.value && page.props.billing?.driver === 'stripe',
);

function openPortal(): void {
    router.post(
        endpoints.settings.billing.portal().url,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                portalBusy.value = true;
            },
            onFinish: () => {
                portalBusy.value = false;
            },
        },
    );
}
</script>

<template>
    <div
        v-if="alert"
        :class="
            cn(
                'flex flex-col gap-3 border-b px-4 py-3 text-sm text-foreground sm:flex-row sm:items-center md:mx-2 md:mb-2 md:rounded-card md:border-0 md:shadow-clay-sm print:hidden',
                pastDue ? 'bg-sun-soft' : 'bg-mint-soft',
            )
        "
        :role="pastDue ? 'alert' : 'status'"
        data-test="billing-alert"
        :data-status="alert.status"
    >
        <div class="flex min-w-0 flex-1 items-start gap-3">
            <span
                :class="
                    cn(
                        'inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-surface shadow-clay-sm',
                        pastDue ? 'text-sun-strong' : 'text-mint-strong',
                    )
                "
                aria-hidden="true"
            >
                <AlarmClock v-if="pastDue" class="size-[1.125rem]" />
                <Sparkles v-else class="size-[1.125rem]" />
            </span>
            <div class="min-w-0">
                <p class="font-semibold">
                    {{
                        pastDue
                            ? t('ui.billing.alert.past_due_title')
                            : t('ui.billing.alert.trial_ending_title')
                    }}
                </p>
                <p class="text-pretty text-muted-foreground">
                    {{
                        pastDue
                            ? t('ui.billing.alert.past_due_body')
                            : t('ui.billing.alert.trial_ending_body', {
                                  date: alert.at
                                      ? fmt.date(alert.at, 'long')
                                      : '',
                              })
                    }}
                </p>
            </div>
        </div>
        <div v-if="canManage" class="flex shrink-0 flex-wrap gap-2 sm:pl-0">
            <Button
                v-if="portalAvailable"
                size="sm"
                :disabled="portalBusy"
                data-test="billing-alert-portal"
                @click="openPortal"
            >
                <Spinner v-if="portalBusy" />
                <CreditCard v-else class="size-4" aria-hidden="true" />
                {{ t('ui.billing.alert.update_payment') }}
            </Button>
            <Button
                v-else
                size="sm"
                :variant="pastDue ? 'default' : 'secondary'"
                as-child
            >
                <Link
                    :href="endpoints.settings.billing.show()"
                    data-test="billing-alert-review"
                >
                    {{
                        pastDue
                            ? t('ui.billing.alert.review')
                            : t('ui.billing.alert.choose_plan')
                    }}
                </Link>
            </Button>
        </div>
    </div>
</template>

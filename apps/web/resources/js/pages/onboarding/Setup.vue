<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BookOpen,
    Bot,
    Building2,
    CircleCheck,
    FileCheck,
    QrCode,
    Upload,
    UserPlus,
    Users,
} from '@lucide/vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, nextTick, ref } from 'vue';
import BotTokenForm from '@/components/opsbot/BotTokenForm.vue';
import BillingMeter from '@/components/opsbot/BillingMeter.vue';
import Callout from '@/components/opsbot/Callout.vue';
import EmployeeQrCard from '@/components/opsbot/EmployeeQrCard.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import LinkPrintSheet from '@/components/opsbot/LinkPrintSheet.vue';
import StarterPackForm from '@/components/opsbot/StarterPackForm.vue';
import TelegramStatus from '@/components/opsbot/TelegramStatus.vue';
import BotStatusPill from '@/components/shell/BotStatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { endpoints, withQuery } from '@/lib/endpoints';
import { jsonRequest } from '@/lib/http';
import { t, tc } from '@/lib/i18n';
import { fetchPartial } from '@/lib/partial';
import { printLinks } from '@/lib/print';
import { marketFor } from '@/lib/plans';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/models';
import type {
    DepartmentRef,
    Employee,
    EmployeeLinkOut,
    LimitUsage,
    OnboardingStep,
    OnboardingStepKey,
    StarterPack,
    TelegramBot,
} from '@/types/opsbot';

const props = defineProps<{
    steps: OnboardingStep[];
    bot: TelegramBot | null;
    starterPacks: StarterPack[];
    departments: DepartmentRef[];
    employeesCount: number;
    limits: LimitUsage;
    driver: 'auto' | 'fake';
}>();

const page = usePage();
const order: OnboardingStepKey[] = ['workspace', 'bot', 'knowledge', 'team'];

const firstOpen =
    props.steps.find((step) => step.state === 'current')?.key ?? 'bot';
const active = ref<OnboardingStepKey | 'finish'>(
    props.steps.every(
        (step) => step.state !== 'current' && step.state !== 'todo',
    )
        ? 'finish'
        : firstOpen,
);
const titleRef = ref<HTMLElement | null>(null);

const index = computed(() =>
    active.value === 'finish' ? order.length : order.indexOf(active.value),
);

const stateOf = (key: OnboardingStepKey) =>
    props.steps.find((step) => step.state && step.key === key)?.state ?? 'todo';

async function go(key: OnboardingStepKey | 'finish'): Promise<void> {
    active.value = key;
    await nextTick();
    titleRef.value?.focus();
}

function nextStep(): void {
    const next = order[index.value + 1];
    void go(next ?? 'finish');
}

function previous(): void {
    const prev = order[index.value - 1];

    if (prev && prev !== 'workspace') {
        void go(prev);
    }
}

function skip(): void {
    if (active.value === 'finish' || active.value === 'workspace') {
        return;
    }

    router.post(
        endpoints.onboarding.skip().url,
        { step: active.value },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['steps'],
            onSuccess: nextStep,
        },
    );
}

const market = computed(() => marketFor(page.props.workspace?.country ?? null));

const pack = computed(() => props.starterPacks[0] ?? null);
const knowledgeDone = computed(() => stateOf('knowledge') === 'done');

const employeeForm = useForm<{
    name: string;
    department_ids: number[];
    primary_department_id: number | null;
    locale: 'ru' | 'en';
}>({
    name: '',
    department_ids: [],
    primary_department_id: null,
    locale: page.props.locale === 'en' ? 'en' : 'ru',
});

const department = computed({
    get: () => employeeForm.primary_department_id,
    set: (value: string | number | null | undefined) => {
        const id = value ? Number(value) : null;
        employeeForm.primary_department_id = id;
        employeeForm.department_ids = id ? [id] : [];
    },
});

const added = ref<Employee[]>([]);
const currentLink = ref<{ employee: Employee; link: EmployeeLinkOut } | null>(
    null,
);
const linkError = ref<string | null>(null);

async function findEmployee(name: string): Promise<Employee | null> {
    const list = await fetchPartial<Paginated<Employee>>(
        withQuery(endpoints.employees.index().url, { q: name }),
        'employees/Index',
        'employees',
        page.version,
    );

    return (
        list?.data
            .filter(
                (employee) =>
                    employee.name === name && employee.kind === 'telegram_only',
            )
            .sort((a, b) => b.created_at.localeCompare(a.created_at))[0] ?? null
    );
}

function createEmployee(): void {
    const name = employeeForm.name.trim();

    employeeForm
        .transform((data) => ({ ...data, name, phone: null, position: null }))
        .post(endpoints.employees.store().url, {
            preserveScroll: true,
            only: ['steps', 'employeesCount', 'limits'],
            onSuccess: async () => {
                employeeForm.reset('name');
                linkError.value = null;
                const employee = await findEmployee(name);

                if (!employee) {
                    linkError.value = t('ui.setup.team.link_later');

                    return;
                }

                try {
                    const link = await jsonRequest<EmployeeLinkOut>(
                        endpoints.employees.link(employee.id).url,
                        'POST',
                    );
                    added.value = [
                        employee,
                        ...added.value.filter(
                            (item) => item.id !== employee.id,
                        ),
                    ];
                    currentLink.value = { employee, link };
                } catch {
                    linkError.value = t('ui.employees.link.error');
                }
            },
        });
}

async function refreshAdded(): Promise<void> {
    if (!added.value.length) {
        return;
    }

    const list = await fetchPartial<Paginated<Employee>>(
        withQuery(endpoints.employees.index().url, { telegram: null }),
        'employees/Index',
        'employees',
        page.version,
    );

    if (!list) {
        return;
    }

    added.value = added.value.map(
        (employee) =>
            list.data.find((item) => item.id === employee.id) ?? employee,
    );
}

useIntervalFn(() => {
    if (active.value === 'team') {
        void refreshAdded();
    }
}, 5000);

const renewing = ref(false);

async function renew(): Promise<void> {
    if (!currentLink.value) {
        return;
    }

    renewing.value = true;

    try {
        const link = await jsonRequest<EmployeeLinkOut>(
            endpoints.employees.link(currentLink.value.employee.id).url,
            'POST',
        );
        currentLink.value = { ...currentLink.value, link };
    } finally {
        renewing.value = false;
    }
}

const printable = computed(() =>
    currentLink.value
        ? [{ ...currentLink.value.link, name: currentLink.value.employee.name }]
        : [],
);

const finishing = ref(false);

function finish(): void {
    finishing.value = true;
    router.post(
        endpoints.onboarding.finish().url,
        {},
        { onFinish: () => (finishing.value = false) },
    );
}

const stepIcons = {
    workspace: Building2,
    bot: Bot,
    knowledge: BookOpen,
    team: Users,
} as const;
</script>

<template>
    <Head :title="t('ui.setup.title')" />

    <div class="mx-auto flex w-full max-w-[880px] flex-col gap-6 py-6">
        <div class="flex items-center justify-between gap-3">
            <p
                class="text-xs font-semibold tracking-wider text-primary-strong uppercase"
            >
                {{ t('ui.setup.eyebrow') }}
            </p>
            <Link
                :href="endpoints.dashboard()"
                class="inline-flex min-h-11 items-center text-sm font-medium text-muted-foreground hover:text-foreground"
            >
                {{ t('ui.setup.exit') }}
            </Link>
        </div>

        <nav
            :aria-label="t('ui.setup.progress')"
            data-test="onboarding-stepper"
        >
            <p class="mb-2 text-sm font-semibold sm:hidden">
                {{
                    t('ui.setup.step_of', {
                        current: Math.min(index + 1, order.length),
                        total: order.length,
                    })
                }}
            </p>
            <div
                class="mb-3 h-1.5 overflow-hidden rounded-chip bg-surface-2 sm:hidden"
            >
                <div
                    class="h-full rounded-chip bg-primary"
                    :style="{
                        width: `${(Math.min(index + 1, order.length) / order.length) * 100}%`,
                    }"
                />
            </div>
            <ol class="hidden gap-2 sm:grid sm:grid-cols-4">
                <li v-for="(key, position) in order" :key="key">
                    <button
                        type="button"
                        :disabled="key === 'workspace'"
                        :aria-current="active === key ? 'step' : undefined"
                        :class="
                            cn(
                                'flex w-full items-center gap-2.5 rounded-2xl border bg-surface px-3 py-2.5 text-left disabled:cursor-default',
                                active === key
                                    ? 'border-primary ring-2 ring-primary/30'
                                    : '',
                            )
                        "
                        data-test="onboarding-step"
                        :data-step="key"
                        :data-state="stateOf(key)"
                        @click="go(key)"
                    >
                        <span
                            :class="
                                cn(
                                    'inline-flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-sm',
                                    stateOf(key) === 'done'
                                        ? 'bg-success-soft text-success'
                                        : active === key
                                          ? 'bg-primary text-primary-foreground'
                                          : 'bg-neutral-soft text-neutral-foreground',
                                )
                            "
                        >
                            <CircleCheck
                                v-if="stateOf(key) === 'done'"
                                class="size-4"
                                aria-hidden="true"
                            />
                            <template v-else>{{ position + 1 }}</template>
                        </span>
                        <span class="min-w-0">
                            <span
                                class="block truncate text-sm font-semibold"
                                >{{ t(`ui.setup.steps.${key}`) }}</span
                            >
                            <span class="block text-xs text-muted-foreground">{{
                                t(`ui.setup.state.${stateOf(key)}`)
                            }}</span>
                        </span>
                    </button>
                </li>
            </ol>
        </nav>

        <section class="rounded-card border bg-surface p-5 shadow-clay sm:p-8">
            <template v-if="active === 'bot'">
                <h1
                    ref="titleRef"
                    tabindex="-1"
                    class="text-display-m focus:outline-none"
                >
                    {{ t('ui.setup.bot.title') }}
                </h1>
                <p class="mt-2 text-pretty text-muted-foreground">
                    {{ t('ui.setup.bot.body') }}
                </p>
                <div class="mt-6 grid gap-6 md:grid-cols-2">
                    <ol class="grid content-start gap-2">
                        <li
                            v-for="(step, n) in [
                                'open',
                                'name',
                                'username',
                                'token',
                            ]"
                            :key="step"
                            class="flex gap-3 rounded-2xl bg-surface-2 px-3 py-2.5 text-sm"
                        >
                            <span
                                class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary font-mono text-xs text-primary-foreground"
                                >{{ n + 1 }}</span
                            >
                            <span>{{ t(`ui.bot_page.steps.${step}`) }}</span>
                        </li>
                    </ol>
                    <div class="grid content-start gap-4">
                        <div
                            v-if="bot"
                            class="flex flex-wrap items-center gap-2"
                            data-test="onboarding-bot-status"
                            :data-state="bot.status"
                        >
                            <BotStatusPill
                                :username="bot.username"
                                :state="bot.status"
                            />
                        </div>
                        <BotTokenForm
                            v-if="!bot || bot.status !== 'active'"
                            :last-error="bot?.last_error ?? null"
                            :driver="driver"
                            input-test="onboarding-bot-token"
                            submit-test="onboarding-bot-connect"
                            :only="['bot', 'steps']"
                        />
                        <Callout v-else tone="mint" :icon="CircleCheck">{{
                            t('ui.setup.bot.connected')
                        }}</Callout>
                    </div>
                </div>
            </template>

            <template v-else-if="active === 'knowledge'">
                <h1
                    ref="titleRef"
                    tabindex="-1"
                    class="text-display-m focus:outline-none"
                >
                    {{ t('ui.setup.knowledge.title') }}
                </h1>
                <p class="mt-2 text-pretty text-muted-foreground">
                    {{ t('ui.setup.knowledge.body') }}
                </p>
                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    <div
                        v-if="pack"
                        class="rounded-card border p-4"
                        data-test="onboarding-pack-card"
                    >
                        <StarterPackForm
                            :pack="pack"
                            import-test="onboarding-pack-import"
                        />
                    </div>
                    <div
                        class="grid content-start gap-3 rounded-card border p-4"
                    >
                        <p class="font-semibold">
                            {{ t('ui.setup.knowledge.own_title') }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ t('ui.setup.knowledge.own_body') }}
                        </p>
                        <p class="text-caption">
                            {{
                                t('ui.setup.knowledge.limits', {
                                    documents: limits.documents?.limit ?? '∞',
                                })
                            }}
                        </p>
                        <Button
                            variant="secondary"
                            as-child
                            class="justify-self-start"
                        >
                            <Link
                                :href="
                                    withQuery(endpoints.knowledge.index().url, {
                                        upload: 1,
                                    })
                                "
                                data-test="onboarding-upload"
                            >
                                <Upload class="size-4" aria-hidden="true" />
                                {{ t('ui.setup.knowledge.upload') }}
                            </Link>
                        </Button>
                    </div>
                </div>
                <Callout
                    v-if="knowledgeDone || pack?.imported"
                    tone="sun"
                    :icon="FileCheck"
                    class="mt-4"
                >
                    {{ t('ui.setup.knowledge.drafts') }}
                    <template #actions>
                        <Button size="sm" as-child>
                            <Link
                                :href="
                                    withQuery(endpoints.knowledge.index().url, {
                                        approval: 'draft',
                                    })
                                "
                                data-test="onboarding-approve-all"
                            >
                                {{ t('ui.setup.knowledge.review') }}
                            </Link>
                        </Button>
                    </template>
                </Callout>
            </template>

            <template v-else-if="active === 'team'">
                <h1
                    ref="titleRef"
                    tabindex="-1"
                    class="text-display-m focus:outline-none"
                >
                    {{ t('ui.setup.team.title') }}
                </h1>
                <p class="mt-2 text-pretty text-muted-foreground">
                    {{ t('ui.setup.team.body') }}
                </p>
                <div class="mt-4 rounded-2xl border px-4">
                    <BillingMeter
                        limit-key="seats"
                        :label="t('ui.employees.seats')"
                        :entry="limits.seats"
                        :icon="Users"
                    />
                </div>
                <form
                    class="mt-5 grid gap-4 sm:grid-cols-[1fr_14rem_auto] sm:items-end"
                    @submit.prevent="createEmployee"
                >
                    <div class="grid gap-2">
                        <Label for="setup-employee-name">{{
                            t('ui.employees.form.name')
                        }}</Label>
                        <Input
                            id="setup-employee-name"
                            v-model="employeeForm.name"
                            maxlength="120"
                            required
                            autocomplete="off"
                            data-test="onboarding-employee-name"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="setup-employee-department">{{
                            t('ui.common.department')
                        }}</Label>
                        <FilterSelect
                            id="setup-employee-department"
                            v-model="department"
                            :label="t('ui.common.department')"
                            :all-label="t('ui.common.no_department')"
                            :options="
                                departments.map((d) => ({
                                    value: d.id,
                                    label: d.name,
                                }))
                            "
                            class="sm:w-full"
                        />
                    </div>
                    <Button
                        type="submit"
                        :disabled="
                            employeeForm.processing ||
                            employeeForm.name.trim().length < 2
                        "
                        data-test="onboarding-employee-create"
                    >
                        <Spinner v-if="employeeForm.processing" />
                        <UserPlus v-else class="size-4" aria-hidden="true" />
                        {{ t('ui.employees.form.create') }}
                    </Button>
                </form>
                <p
                    v-if="employeeForm.errors.name || linkError"
                    class="mt-2 text-sm text-destructive"
                >
                    {{ employeeForm.errors.name ?? linkError }}
                </p>

                <div
                    v-if="currentLink"
                    class="mt-6 rounded-card border p-4"
                    data-test="onboarding-employee-qr"
                >
                    <p class="mb-3 font-semibold">
                        {{
                            t('ui.employees.link.title', {
                                name: currentLink.employee.name,
                            })
                        }}
                    </p>
                    <EmployeeQrCard
                        :link="currentLink.link"
                        :name="currentLink.employee.name"
                        :renewing="renewing"
                        @renew="renew"
                        @print="printLinks"
                    />
                </div>

                <ul
                    v-if="added.length"
                    class="mt-6 divide-y rounded-card border"
                >
                    <li
                        v-for="employee in added"
                        :key="employee.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <span class="min-w-0 flex-1 truncate font-medium">{{
                            employee.name
                        }}</span>
                        <TelegramStatus :telegram="employee.telegram" />
                    </li>
                </ul>
                <div
                    v-else-if="!currentLink"
                    class="mt-6 flex flex-col items-center gap-2 rounded-card bg-surface-2 p-6 text-center text-sm text-muted-foreground"
                >
                    <QrCode
                        class="size-8 text-primary-strong"
                        aria-hidden="true"
                    />
                    {{
                        employeesCount
                            ? tc('ui.setup.team.count', employeesCount)
                            : t('ui.setup.team.empty')
                    }}
                </div>
                <Link
                    :href="endpoints.settings.members.index()"
                    class="mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-primary-strong hover:underline"
                >
                    {{ t('ui.setup.team.invite_web') }}
                </Link>
            </template>

            <template v-else>
                <h1
                    ref="titleRef"
                    tabindex="-1"
                    class="text-display-m focus:outline-none"
                >
                    {{ t('ui.setup.finish.title') }}
                </h1>
                <p class="mt-2 text-muted-foreground">
                    {{ t('ui.setup.finish.body') }}
                </p>
                <ul class="mt-6 grid gap-2 sm:grid-cols-3">
                    <li
                        v-for="key in ['bot', 'knowledge', 'team'] as const"
                        :key="key"
                        class="flex items-center gap-2 rounded-2xl bg-surface-2 px-3 py-3 text-sm"
                    >
                        <component
                            :is="stepIcons[key]"
                            class="size-4 text-primary-strong"
                            aria-hidden="true"
                        />
                        <span class="flex-1 font-medium">{{
                            t(`ui.setup.steps.${key}`)
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            t(`ui.setup.state.${stateOf(key)}`)
                        }}</span>
                    </li>
                </ul>
                <p class="text-caption mt-4">
                    {{
                        t('ui.onboarding.market_caption', {
                            currency: market.currency,
                            gateway: t(`ui.billing.gateways.${market.gateway}`),
                        })
                    }}
                </p>
            </template>

            <footer
                class="mt-8 flex flex-wrap items-center justify-between gap-2 border-t pt-5"
            >
                <Button
                    v-if="index > 1"
                    variant="ghost"
                    data-test="onboarding-back"
                    @click="active === 'finish' ? go('team') : previous()"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    {{ t('ui.common.back') }}
                </Button>
                <span v-else />
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="active !== 'finish'"
                        variant="ghost"
                        data-test="onboarding-skip"
                        @click="skip"
                        >{{ t('ui.setup.skip') }}</Button
                    >
                    <Button v-if="active !== 'finish'" @click="nextStep">{{
                        t('ui.common.continue')
                    }}</Button>
                    <Button
                        v-else
                        :disabled="finishing"
                        data-test="onboarding-finish"
                        @click="finish"
                    >
                        <Spinner v-if="finishing" />
                        {{ t('ui.setup.finish.open') }}
                    </Button>
                </div>
            </footer>
        </section>
    </div>

    <LinkPrintSheet :links="printable" />
</template>

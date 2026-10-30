<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Bot,
    CircleArrowUp,
    Download,
    Ellipsis,
    FilterX,
    Link2,
    Pencil,
    Printer,
    QrCode,
    RotateCcw,
    UserMinus,
    UserPlus,
    Users,
    UserX,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import BillingMeter from '@/components/opsbot/BillingMeter.vue';
import Callout from '@/components/opsbot/Callout.vue';
import DepartmentChips from '@/components/opsbot/DepartmentChips.vue';
import EmployeeQrCard from '@/components/opsbot/EmployeeQrCard.vue';
import EmployeeSheet from '@/components/opsbot/EmployeeSheet.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import LinkPrintSheet from '@/components/opsbot/LinkPrintSheet.vue';
import type { PrintableLink } from '@/components/opsbot/LinkPrintSheet.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import TelegramStatus from '@/components/opsbot/TelegramStatus.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCan } from '@/composables/useCan';
import { useFilters } from '@/composables/useFilters';
import { useFormat } from '@/composables/useFormat';
import { useUpgrade } from '@/composables/useUpgrade';
import { endpoints } from '@/lib/endpoints';
import { jsonRequest, JsonRequestError } from '@/lib/http';
import { t, tc } from '@/lib/i18n';
import { meterLevel } from '@/lib/opsbot';
import { printLinks } from '@/lib/print';
import type { Paginated } from '@/types/models';
import type {
    BotStatus,
    DepartmentRef,
    Employee,
    EmployeeFilters,
    EmployeeLinkOut,
} from '@/types/opsbot';

const props = defineProps<{
    employees: Paginated<Employee>;
    filters: EmployeeFilters;
    departments: DepartmentRef[];
    seats: { used: number; limit: number | null };
    bot: { status: BotStatus; username: string | null } | null;
    pendingRelink: number;
}>();

const page = usePage();
const fmt = useFormat();
const { can } = useCan();
const upgrade = useUpgrade();

const { filters, reset } = useFilters(
    () => endpoints.employees.index().url,
    {
        q: props.filters.q ?? null,
        department_id: props.filters.department_id ?? null,
        status: props.filters.status ?? null,
        telegram: props.filters.telegram ?? null,
        needs_relink: props.filters.needs_relink ? 1 : null,
    },
    ['employees', 'filters', 'seats', 'pendingRelink'],
);

const filtered = computed(() =>
    Object.values(filters).some((value) => value !== null && value !== ''),
);

const seatsFull = computed(() => meterLevel(props.seats) === 'full');
const botActive = computed(() => props.bot?.status === 'active');
const privileged = computed(() => {
    const role = page.props.workspace?.role;

    return role === 'owner' || role === 'admin';
});

const selected = ref<number[]>([]);
const selectable = computed(() =>
    props.employees.data.filter(
        (employee) =>
            employee.kind === 'telegram_only' &&
            employee.status !== 'offboarded',
    ),
);
const allSelected = computed(
    () =>
        selectable.value.length > 0 &&
        selectable.value.every((employee) =>
            selected.value.includes(employee.id),
        ),
);

function toggleAll(value: boolean): void {
    selected.value = value
        ? selectable.value.map((employee) => employee.id)
        : [];
}

function toggleOne(id: number, value: boolean): void {
    selected.value = value
        ? [...new Set([...selected.value, id])]
        : selected.value.filter((item) => item !== id);
}

const sheetOpen = ref(false);
const editing = ref<Employee | null>(null);

function addEmployee(): void {
    if (seatsFull.value) {
        upgrade.show('seats');

        return;
    }

    editing.value = null;
    sheetOpen.value = true;
}

function editEmployee(employee: Employee): void {
    editing.value = employee;
    sheetOpen.value = true;
}

const linkFor = ref<{
    employee: Pick<Employee, 'id' | 'name'>;
    link: EmployeeLinkOut;
} | null>(null);
const linkOpen = computed({
    get: () => linkFor.value !== null,
    set: (value: boolean) => {
        if (!value) {
            linkFor.value = null;
        }
    },
});
const issuing = ref<number | null>(null);

function linkError(error: unknown): void {
    const message =
        error instanceof JsonRequestError &&
        error.message &&
        !/^\d+$/.test(error.message)
            ? error.message
            : t('ui.employees.link.error');
    toast.error(message);
}

async function issueLink(
    employee: Pick<Employee, 'id' | 'name'>,
    reactivate = false,
): Promise<void> {
    issuing.value = employee.id;

    try {
        const link = await jsonRequest<EmployeeLinkOut>(
            reactivate
                ? endpoints.employees.reactivate(employee.id).url
                : endpoints.employees.link(employee.id).url,
            'POST',
        );
        linkFor.value = { employee, link };
        router.reload({ only: ['employees', 'seats', 'pendingRelink'] });
    } catch (error) {
        if (error instanceof JsonRequestError && error.status === 402) {
            upgrade.show('seats');
        } else {
            linkError(error);
        }
    } finally {
        issuing.value = null;
    }
}

function onCreated(name: string): void {
    router.reload({
        only: ['employees', 'seats', 'pendingRelink'],
        onSuccess: () => {
            const match = props.employees.data
                .filter(
                    (employee) =>
                        employee.name === name &&
                        employee.telegram.state === 'not_linked',
                )
                .sort((a, b) => b.created_at.localeCompare(a.created_at))[0];

            if (match) {
                void issueLink(match);
            }
        },
    });
}

const printable = ref<PrintableLink[]>([]);
const printing = ref(false);

async function bulkLinks(ids: number[]): Promise<void> {
    if (!ids.length) {
        return;
    }

    printing.value = true;

    try {
        const result = await jsonRequest<{ links: EmployeeLinkOut[] }>(
            endpoints.employees.bulkLinks().url,
            'POST',
            { user_ids: ids.slice(0, 50) },
        );
        const names = new Map(
            props.employees.data.map((employee) => [
                employee.id,
                employee.name,
            ]),
        );
        printable.value = result.links.map((link) => ({
            ...link,
            name: names.get(link.user_id) ?? '',
        }));
        router.reload({ only: ['employees', 'pendingRelink'] });
        setTimeout(printLinks, 150);
    } catch (error) {
        linkError(error);
    } finally {
        printing.value = false;
    }
}

function printSingle(): void {
    if (!linkFor.value) {
        return;
    }

    printable.value = [
        { ...linkFor.value.link, name: linkFor.value.employee.name },
    ];
    setTimeout(printLinks, 150);
}

const relinkAll = computed(() =>
    props.employees.data
        .filter((employee) => employee.needs_relink)
        .map((employee) => employee.id),
);

const offboarding = ref<Employee | null>(null);
const exporting = ref<Employee | null>(null);
const erasing = ref<Employee | null>(null);
const processing = ref(false);
const password = ref('');
const passwordError = ref<string | null>(null);

function dialogModel(target: typeof offboarding) {
    return computed({
        get: () => target.value !== null,
        set: (value: boolean) => {
            if (!value) {
                target.value = null;
            }
        },
    });
}

const offboardOpen = dialogModel(offboarding);
const exportOpen = dialogModel(exporting);
const eraseOpen = dialogModel(erasing);

function confirmOffboard(): void {
    if (!offboarding.value) {
        return;
    }

    processing.value = true;
    router.delete(endpoints.employees.offboard(offboarding.value.id).url, {
        preserveScroll: true,
        onSuccess: () => (offboarding.value = null),
        onFinish: () => (processing.value = false),
    });
}

function confirmExport(): void {
    if (!exporting.value) {
        return;
    }

    processing.value = true;
    router.get(
        endpoints.employees.export(exporting.value.id).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (exporting.value = null),
            onFinish: () => (processing.value = false),
        },
    );
}

async function confirmErase(): Promise<void> {
    if (!erasing.value) {
        return;
    }

    processing.value = true;
    passwordError.value = null;

    try {
        await jsonRequest(endpoints.confirmPassword().url, 'POST', {
            password: password.value,
        });
    } catch {
        passwordError.value = t('ui.employees.erase.password_wrong');
        processing.value = false;

        return;
    }

    router.post(
        endpoints.employees.erase(erasing.value.id).url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                erasing.value = null;
                password.value = '';
            },
            onFinish: () => (processing.value = false),
        },
    );
}

const statusTone = {
    invited: 'primary',
    active: 'success',
    offboarded: 'neutral',
} as const;
</script>

<template>
    <Head :title="t('ui.employees.title')" />

    <div
        class="mx-auto flex w-full max-w-[1440px] flex-col gap-5 px-4 py-6 md:px-6"
    >
        <PageHeader
            :title="t('ui.employees.title')"
            :description="t('ui.employees.subtitle')"
            :icon="Users"
        >
            <template #actions>
                <Button v-if="can('members.manage')" variant="ghost" as-child>
                    <Link :href="endpoints.settings.members.index()">{{
                        t('ui.employees.invite_manager')
                    }}</Link>
                </Button>
                <Button
                    variant="secondary"
                    :disabled="!selected.length || printing"
                    data-test="employees-bulk-links"
                    @click="bulkLinks(selected)"
                >
                    <Printer class="size-4" aria-hidden="true" />
                    {{
                        selected.length
                            ? tc('ui.employees.print_links_n', selected.length)
                            : t('ui.employees.print_links')
                    }}
                </Button>
                <Button
                    :title="
                        seatsFull ? t('ui.employees.seats_full') : undefined
                    "
                    data-test="employees-add"
                    @click="addEmployee"
                >
                    <UserPlus class="size-4" aria-hidden="true" />
                    {{ t('ui.employees.add') }}
                </Button>
            </template>
        </PageHeader>

        <div
            class="rounded-card border bg-surface px-4 shadow-clay-sm"
            data-test="employees-seat-bar"
        >
            <BillingMeter
                limit-key="seats"
                :label="t('ui.employees.seats')"
                :entry="seats"
                :icon="Users"
                :can-upgrade="can('billing.view')"
                @upgrade="upgrade.show"
            />
        </div>

        <Callout v-if="!botActive" tone="info" :icon="Bot">
            {{
                bot
                    ? t('ui.employees.bot_inactive')
                    : t('ui.employees.bot_missing')
            }}
            <template v-if="can('bot.manage')" #actions>
                <Button size="sm" variant="secondary" as-child>
                    <Link :href="endpoints.bot.index()">{{
                        t('ui.employees.open_bot')
                    }}</Link>
                </Button>
            </template>
        </Callout>
        <Callout v-if="pendingRelink > 0" tone="sun" :icon="Link2">
            {{ tc('ui.employees.relink_needed', pendingRelink) }}
            <template #actions>
                <Button
                    size="sm"
                    variant="secondary"
                    @click="
                        filters.needs_relink = filters.needs_relink ? null : 1
                    "
                >
                    {{ t('ui.employees.show_relink') }}
                </Button>
                <Button
                    size="sm"
                    :disabled="!relinkAll.length || printing"
                    @click="bulkLinks(relinkAll)"
                >
                    {{ t('ui.employees.relink_all') }}
                </Button>
            </template>
        </Callout>

        <div class="flex flex-wrap items-center gap-2">
            <SearchInput
                v-model="filters.q"
                :placeholder="t('ui.employees.search')"
                class="w-full sm:w-64"
            />
            <FilterSelect
                v-if="departments.length"
                v-model="filters.department_id"
                :label="t('ui.common.department')"
                :all-label="t('ui.common.all_departments')"
                :options="
                    departments.map((d) => ({ value: d.id, label: d.name }))
                "
            />
            <FilterSelect
                v-model="filters.status"
                :label="t('ui.common.status')"
                :all-label="t('ui.common.all_statuses')"
                :options="
                    (['invited', 'active', 'offboarded'] as const).map((s) => ({
                        value: s,
                        label: t(`employees.status.${s}`),
                    }))
                "
            />
            <FilterSelect
                v-model="filters.telegram"
                :label="t('ui.employees.telegram')"
                :all-label="t('ui.employees.telegram_any')"
                :options="
                    (['linked', 'not_linked', 'blocked'] as const).map((s) => ({
                        value: s,
                        label: t(`employees.telegram.${s}`),
                    }))
                "
            />
            <Button v-if="filtered" variant="ghost" @click="reset">
                <FilterX class="size-4" aria-hidden="true" />
                {{ t('ui.common.clear_filters') }}
            </Button>
        </div>

        <EmptyState
            v-if="!employees.data.length && !filtered"
            :icon="QrCode"
            scene="people"
            tone="ai"
            :title="t('ui.employees.empty_title')"
            :description="t('ui.employees.empty_body')"
            class="rounded-card border bg-surface shadow-clay-sm"
        >
            <Button @click="addEmployee">
                <UserPlus class="size-4" aria-hidden="true" />
                {{ t('ui.employees.add') }}
            </Button>
        </EmptyState>
        <EmptyState
            v-else-if="!employees.data.length"
            :icon="FilterX"
            scene="search"
            tone="muted"
            :title="t('ui.common.no_results')"
            :description="t('ui.common.try_other_filters')"
            compact
            class="rounded-card border bg-surface"
        />

        <div
            v-else
            class="overflow-hidden rounded-card border bg-card shadow-clay"
        >
            <div class="scrollbar-thin overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        {{
                            t('ui.employees.title')
                        }}
                    </caption>
                    <thead class="max-md:hidden">
                        <tr
                            class="h-11 bg-surface-2 text-left text-muted-foreground"
                        >
                            <th scope="col" class="w-10 px-4">
                                <Checkbox
                                    :model-value="allSelected"
                                    :aria-label="t('ui.common.select_all')"
                                    @update:model-value="
                                        (value) => toggleAll(value === true)
                                    "
                                />
                            </th>
                            <th scope="col" class="px-3 font-semibold">
                                {{ t('ui.employees.fields.name') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 font-semibold max-lg:hidden"
                            >
                                {{ t('ui.employees.fields.departments') }}
                            </th>
                            <th scope="col" class="px-3 font-semibold">
                                {{ t('ui.employees.telegram') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 font-semibold max-xl:hidden"
                            >
                                {{ t('ui.employees.fields.link') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 font-semibold max-xl:hidden"
                            >
                                {{ t('ui.employees.fields.last_question') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 text-right font-semibold max-lg:hidden"
                            >
                                {{ t('ui.employees.fields.open_tasks') }}
                            </th>
                            <th scope="col" class="px-3 font-semibold">
                                {{ t('ui.common.status') }}
                            </th>
                            <th scope="col" class="px-3">
                                <span class="sr-only">{{
                                    t('ui.common.actions')
                                }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="employee in employees.data"
                            :key="employee.id"
                            class="grid grid-cols-[auto_1fr_auto] gap-x-3 gap-y-2 p-4 hover:bg-surface-2 md:table-row md:h-[52px] md:p-0"
                            data-test="employees-row"
                            :data-user-id="employee.id"
                            :data-telegram-state="employee.telegram.state"
                            :data-status="employee.status"
                        >
                            <td class="md:px-4">
                                <Checkbox
                                    v-if="
                                        employee.kind === 'telegram_only' &&
                                        employee.status !== 'offboarded'
                                    "
                                    :model-value="
                                        selected.includes(employee.id)
                                    "
                                    :aria-label="
                                        t('ui.employees.select', {
                                            name: employee.name,
                                        })
                                    "
                                    @update:model-value="
                                        (value) =>
                                            toggleOne(
                                                employee.id,
                                                value === true,
                                            )
                                    "
                                />
                            </td>
                            <td class="min-w-0 md:px-3 md:py-2">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        :class="[
                                            'font-semibold',
                                            employee.status === 'offboarded'
                                                ? 'text-muted-foreground'
                                                : '',
                                        ]"
                                        >{{ employee.name }}</span
                                    >
                                    <OpsBadge
                                        v-if="employee.is_you"
                                        :label="t('ui.employees.you')"
                                        tone="primary"
                                    />
                                    <OpsBadge
                                        :label="
                                            employee.kind === 'web'
                                                ? t('ui.employees.kind.web')
                                                : t(
                                                      'ui.employees.kind.telegram',
                                                  )
                                        "
                                    />
                                    <OpsBadge
                                        v-if="employee.role !== 'employee'"
                                        :label="t(`roles.${employee.role}`)"
                                        tone="mint"
                                    />
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        [
                                            employee.position,
                                            employee.phone_masked,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') ||
                                        employee.email ||
                                        ''
                                    }}
                                </p>
                                <div class="mt-1 lg:hidden">
                                    <DepartmentChips
                                        :departments="employee.departments"
                                        :primary-id="
                                            employee.primary_department_id
                                        "
                                    />
                                </div>
                            </td>
                            <td class="px-3 max-lg:hidden">
                                <DepartmentChips
                                    :departments="employee.departments"
                                    :primary-id="employee.primary_department_id"
                                    :empty-label="
                                        t('ui.employees.no_department')
                                    "
                                />
                            </td>
                            <td class="col-start-2 md:px-3">
                                <TelegramStatus :telegram="employee.telegram" />
                            </td>
                            <td class="px-3 text-xs max-xl:hidden">
                                <template v-if="employee.link.active">
                                    {{
                                        t('ui.employees.link.active_until', {
                                            date: fmt.date(
                                                employee.link.expires_at,
                                            ),
                                        })
                                    }}
                                </template>
                                <span
                                    v-else-if="employee.needs_relink"
                                    class="font-semibold text-sun-strong"
                                    >{{ t('ui.employees.link.needed') }}</span
                                >
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-3 text-xs max-xl:hidden">
                                <RelativeTime
                                    :value="employee.last_question_at"
                                />
                            </td>
                            <td
                                class="px-3 text-right tabular-nums max-lg:hidden"
                            >
                                {{ employee.open_tasks }}
                            </td>
                            <td class="col-start-2 md:px-3">
                                <OpsBadge
                                    :label="
                                        t(`employees.status.${employee.status}`)
                                    "
                                    :tone="statusTone[employee.status]"
                                />
                            </td>
                            <td
                                class="col-start-3 row-start-1 text-right md:px-3"
                            >
                                <div
                                    class="flex items-center justify-end gap-1"
                                >
                                    <Button
                                        v-if="
                                            employee.kind === 'telegram_only' &&
                                            employee.status !== 'offboarded' &&
                                            employee.telegram.state !== 'linked'
                                        "
                                        size="sm"
                                        variant="secondary"
                                        :disabled="issuing === employee.id"
                                        class="max-sm:hidden"
                                        @click="issueLink(employee)"
                                    >
                                        <QrCode
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{ t('ui.employees.link.issue') }}
                                    </Button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                size="icon-sm"
                                                variant="ghost"
                                                :aria-label="
                                                    t(
                                                        'ui.employees.actions_for',
                                                        { name: employee.name },
                                                    )
                                                "
                                            >
                                                <Ellipsis
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-60"
                                        >
                                            <DropdownMenuItem
                                                v-if="
                                                    employee.status !==
                                                    'offboarded'
                                                "
                                                @select="editEmployee(employee)"
                                            >
                                                <Pencil
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{ t('ui.common.edit') }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="
                                                    employee.kind ===
                                                        'telegram_only' &&
                                                    employee.status !==
                                                        'offboarded'
                                                "
                                                @select="issueLink(employee)"
                                            >
                                                <QrCode
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    t('ui.employees.link.renew')
                                                }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="
                                                    employee.status ===
                                                    'offboarded'
                                                "
                                                data-test="employee-reactivate"
                                                @select="
                                                    seatsFull
                                                        ? upgrade.show('seats')
                                                        : issueLink(
                                                              employee,
                                                              true,
                                                          )
                                                "
                                            >
                                                <RotateCcw
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    t('ui.employees.reactivate')
                                                }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                data-test="employee-export"
                                                @select="exporting = employee"
                                            >
                                                <Download
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    t(
                                                        'ui.employees.export.action',
                                                    )
                                                }}
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator
                                                v-if="!employee.is_you"
                                            />
                                            <DropdownMenuItem
                                                v-if="
                                                    employee.status !==
                                                        'offboarded' &&
                                                    !employee.is_you
                                                "
                                                class="text-destructive"
                                                data-test="employee-offboard"
                                                @select="offboarding = employee"
                                            >
                                                <UserMinus
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    t(
                                                        'ui.employees.offboard.action',
                                                    )
                                                }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="
                                                    employee.status ===
                                                        'offboarded' &&
                                                    privileged
                                                "
                                                class="text-destructive"
                                                data-test="employee-erase"
                                                @select="erasing = employee"
                                            >
                                                <UserX
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    t(
                                                        'ui.employees.erase.action',
                                                    )
                                                }}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <LaravelPagination
            v-if="employees.meta.last_page > 1"
            :links="employees.links"
            :meta="employees.meta"
            :count="employees.data.length"
            :only="['employees']"
        />

        <p
            v-if="seatsFull"
            class="flex items-center gap-1.5 text-sm text-coral-strong"
        >
            <CircleArrowUp class="size-4" aria-hidden="true" />
            {{ t('ui.employees.seats_full') }}
        </p>
    </div>

    <EmployeeSheet
        v-model:open="sheetOpen"
        :employee="editing"
        :departments="departments"
        @created="onCreated"
    />

    <Dialog v-model:open="linkOpen">
        <DialogContent class="sm:max-w-xl" data-test="employee-link-dialog">
            <DialogHeader>
                <DialogTitle>{{
                    t('ui.employees.link.title', {
                        name: linkFor?.employee.name ?? '',
                    })
                }}</DialogTitle>
                <DialogDescription>{{
                    t('ui.employees.link.once')
                }}</DialogDescription>
            </DialogHeader>
            <EmployeeQrCard
                v-if="linkFor"
                :link="linkFor.link"
                :name="linkFor.employee.name"
                :renewing="issuing === linkFor.employee.id"
                @renew="linkFor && issueLink(linkFor.employee)"
                @print="printSingle"
            />
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="offboardOpen"
        :title="
            t('ui.employees.offboard.title', { name: offboarding?.name ?? '' })
        "
        :description="
            offboarding
                ? t('ui.employees.offboard.body') +
                  ' ' +
                  tc('ui.employees.offboard.tasks', offboarding.open_tasks)
                : null
        "
        :confirm-label="t('ui.employees.offboard.confirm')"
        destructive
        :processing="processing"
        confirm-test="employee-offboard-confirm"
        @confirm="confirmOffboard"
    />

    <ConfirmDialog
        v-model:open="exportOpen"
        :title="t('ui.employees.export.title', { name: exporting?.name ?? '' })"
        :description="t('ui.employees.export.body')"
        :confirm-label="t('ui.employees.export.confirm')"
        :processing="processing"
        confirm-test="employee-export-confirm"
        @confirm="confirmExport"
    >
        <ul class="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
            <li>{{ t('ui.employees.export.item_profile') }}</li>
            <li>{{ t('ui.employees.export.item_questions') }}</li>
            <li>{{ t('ui.employees.export.item_records') }}</li>
            <li>{{ t('ui.employees.export.item_views') }}</li>
        </ul>
    </ConfirmDialog>

    <ConfirmDialog
        v-model:open="eraseOpen"
        :title="t('ui.employees.erase.title', { name: erasing?.name ?? '' })"
        :description="t('ui.employees.erase.body')"
        :confirm-label="t('ui.employees.erase.confirm')"
        :confirm-text="erasing?.name ?? null"
        :blocked="!password"
        destructive
        :processing="processing"
        confirm-test="employee-erase-confirm"
        @confirm="confirmErase"
    >
        <div class="grid gap-2">
            <Label for="erase-password">{{
                t('ui.employees.erase.password')
            }}</Label>
            <Input
                id="erase-password"
                v-model="password"
                type="password"
                autocomplete="current-password"
                :aria-invalid="Boolean(passwordError)"
                data-test="employee-erase-password"
            />
            <p v-if="passwordError" class="text-xs text-destructive">
                {{ passwordError }}
            </p>
        </div>
    </ConfirmDialog>

    <LinkPrintSheet :links="printable" />
</template>

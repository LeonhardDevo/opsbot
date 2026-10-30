<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Archive,
    Building,
    ChevronDown,
    Ellipsis,
    FileText,
    Pencil,
    Plus,
    Route,
    Users,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import Callout from '@/components/opsbot/Callout.vue';
import DepartmentDialog from '@/components/opsbot/DepartmentDialog.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { endpoints } from '@/lib/endpoints';
import { t, tc } from '@/lib/i18n';
import {
    cellKey,
    cellsToRules,
    resolveRoute,
    ROUTING_CATEGORIES,
    rulesToCells,
} from '@/lib/opsbot';
import { categoryIcon } from '@/lib/opsbot-icons';
import type {
    Department,
    RoutingCategory,
    RoutingRule,
    UserRef,
} from '@/types/opsbot';

const props = defineProps<{
    departments: Department[];
    rules: RoutingRule[];
    categories: Array<{ value: RoutingCategory; label: string }>;
    managers: UserRef[];
    fallbackOwner: UserRef | null;
}>();

const live = computed(() =>
    props.departments.filter((department) => !department.archived_at),
);
const archived = computed(() =>
    props.departments.filter((department) => department.archived_at),
);

const categories = computed(() =>
    props.categories.length
        ? props.categories
        : ROUTING_CATEGORIES.map((value) => ({
              value,
              label: t(`routing.category.${value}`),
          })),
);

const initial = computed(() => rulesToCells(props.rules));
const cells = ref<Record<string, number | null>>({ ...initial.value });

watch(initial, (value) => {
    cells.value = { ...value };
});

const dirty = computed(() => {
    const keys = new Set([
        ...Object.keys(initial.value),
        ...Object.keys(cells.value),
    ]);

    return [...keys].some(
        (key) => (initial.value[key] ?? null) !== (cells.value[key] ?? null),
    );
});

const rows = computed(() => [
    { id: null as number | null, name: t('ui.departments.any_department') },
    ...live.value.map((department) => ({
        id: department.id as number | null,
        name: department.name,
    })),
]);

const managerName = (id: number | null | undefined): string | null =>
    id ? (props.managers.find((user) => user.id === id)?.name ?? null) : null;

function effective(
    departmentId: number | null,
    category: RoutingCategory,
): string {
    const currentRules = cellsToRules(cells.value).map((rule) => ({
        department_id: rule.department_id,
        category: rule.category,
        manager: {
            id: rule.manager_user_id,
            name: managerName(rule.manager_user_id) ?? '',
        },
    }));
    const resolved = resolveRoute(
        currentRules.filter(
            (rule) =>
                !(
                    rule.department_id === departmentId &&
                    rule.category === category
                ),
        ),
        props.departments,
        props.fallbackOwner,
        departmentId,
        category,
    );

    return resolved.user
        ? t('ui.departments.fallback_to', { name: resolved.user.name })
        : t('ui.departments.default');
}

function setCell(
    departmentId: number | null,
    category: RoutingCategory,
    value: string,
): void {
    cells.value = {
        ...cells.value,
        [cellKey(departmentId, category)]: value ? Number(value) : null,
    };
}

const saving = ref(false);

function save(): void {
    saving.value = true;
    router.put(
        endpoints.departments.routing().url,
        { rules: cellsToRules(cells.value) },
        { preserveScroll: true, onFinish: () => (saving.value = false) },
    );
}

function discard(): void {
    cells.value = { ...initial.value };
}

const dialogOpen = ref(false);
const editing = ref<Department | null>(null);

function create(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function edit(department: Department): void {
    editing.value = department;
    dialogOpen.value = true;
}

const archiving = ref<Department | null>(null);
const archiveOpen = computed({
    get: () => archiving.value !== null,
    set: (value: boolean) => {
        if (!value) {
            archiving.value = null;
        }
    },
});
const processing = ref(false);

function confirmArchive(): void {
    if (!archiving.value) {
        return;
    }

    processing.value = true;
    router.delete(endpoints.departments.archive(archiving.value.id).url, {
        preserveScroll: true,
        onSuccess: () => (archiving.value = null),
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <Head :title="t('ui.departments.title')" />

    <div
        class="mx-auto flex w-full max-w-[1440px] flex-col gap-6 px-4 py-6 pb-28 md:px-6"
    >
        <PageHeader
            :title="t('ui.departments.title')"
            :description="t('ui.departments.subtitle')"
            :icon="Building"
        >
            <template #actions>
                <Button data-test="departments-add" @click="create">
                    <Plus class="size-4" aria-hidden="true" />
                    {{ t('ui.departments.add') }}
                </Button>
            </template>
        </PageHeader>

        <EmptyState
            v-if="!live.length"
            :icon="Building"
            scene="people"
            :title="t('ui.departments.empty_title')"
            :description="t('ui.departments.empty_body')"
            class="rounded-card border bg-surface shadow-clay-sm"
        >
            <Button @click="create">
                <Plus class="size-4" aria-hidden="true" />
                {{ t('ui.departments.add') }}
            </Button>
        </EmptyState>

        <div v-else class="grid gap-4 min-[1440px]:grid-cols-3 md:grid-cols-2">
            <article
                v-for="department in live"
                :key="department.id"
                class="space-y-3 rounded-card border bg-surface p-5 shadow-clay"
                data-test="department-card"
                :data-code="department.code"
            >
                <div class="flex items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-h2 [overflow-wrap:anywhere]">
                            {{ department.name }}
                        </h2>
                        <p
                            class="font-mono text-[0.8125rem] text-muted-foreground"
                        >
                            {{ department.code }}
                        </p>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                :aria-label="
                                    t('ui.departments.actions_for', {
                                        name: department.name,
                                    })
                                "
                            >
                                <Ellipsis class="size-4" aria-hidden="true" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem @select="edit(department)">
                                <Pencil class="size-4" aria-hidden="true" />
                                {{ t('ui.common.edit') }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                class="text-destructive"
                                data-test="department-archive"
                                @select="archiving = department"
                            >
                                <Archive class="size-4" aria-hidden="true" />
                                {{ t('ui.departments.archive') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <template v-if="department.manager">
                        <UserAvatar :name="department.manager.name" size="xs" />
                        <span>{{ department.manager.name }}</span>
                    </template>
                    <OpsBadge
                        v-else
                        tone="sun"
                        :label="t('ui.departments.no_manager')"
                    />
                </div>
                <p
                    class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <Users class="size-4" aria-hidden="true" />
                        {{
                            tc(
                                'ui.departments.members',
                                department.members_count,
                            )
                        }}
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <FileText class="size-4" aria-hidden="true" />
                        {{
                            tc(
                                'ui.departments.documents',
                                department.documents_count,
                            )
                        }}
                    </span>
                </p>
            </article>
        </div>

        <Collapsible v-if="archived.length">
            <CollapsibleTrigger as-child>
                <Button variant="ghost" class="self-start">
                    <ChevronDown class="size-4" aria-hidden="true" />
                    {{ tc('ui.departments.archived', archived.length) }}
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ul class="mt-2 grid gap-2 md:grid-cols-3">
                    <li
                        v-for="department in archived"
                        :key="department.id"
                        class="rounded-2xl border border-dashed px-4 py-3 text-sm text-muted-foreground"
                    >
                        {{ department.name }}
                        <span class="font-mono text-xs"
                            >· {{ department.code }}</span
                        >
                    </li>
                </ul>
            </CollapsibleContent>
        </Collapsible>

        <SectionCard
            :title="t('ui.departments.routing_title')"
            :description="t('ui.departments.routing_hint')"
            :icon="Route"
            data-test="routing-matrix"
        >
            <Callout tone="info" :icon="Route" class="mb-4">
                {{ t('ui.departments.fallback_explainer') }}
            </Callout>

            <div class="hidden scrollbar-thin overflow-x-auto lg:block">
                <table class="w-full min-w-[960px] text-sm">
                    <caption class="sr-only">
                        {{
                            t('ui.departments.routing_title')
                        }}
                    </caption>
                    <thead>
                        <tr class="text-left">
                            <th
                                scope="col"
                                class="w-44 px-2 py-2 font-semibold text-muted-foreground"
                            >
                                {{ t('ui.common.department') }}
                            </th>
                            <th
                                v-for="category in categories"
                                :key="category.value"
                                scope="col"
                                class="px-1 py-2 align-bottom font-semibold"
                            >
                                <span
                                    class="flex flex-col items-start gap-1 text-xs"
                                >
                                    <component
                                        :is="categoryIcon[category.value]"
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    {{ category.label }}
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in rows" :key="row.id ?? 'any'">
                            <th
                                scope="row"
                                class="px-2 py-2 text-left font-semibold"
                            >
                                {{ row.name }}
                            </th>
                            <td
                                v-for="category in categories"
                                :key="category.value"
                                class="px-1 py-1.5"
                            >
                                <select
                                    class="h-11 w-full min-w-28 rounded-[12px] border border-input bg-surface px-2 text-[0.8125rem]"
                                    :value="
                                        cells[
                                            cellKey(row.id, category.value)
                                        ] ?? ''
                                    "
                                    :aria-label="`${category.label} — ${row.name}`"
                                    data-test="routing-cell"
                                    :data-department="row.id ?? 'any'"
                                    :data-category="category.value"
                                    @change="
                                        setCell(
                                            row.id,
                                            category.value,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="">
                                        {{ effective(row.id, category.value) }}
                                    </option>
                                    <option
                                        v-for="manager in managers"
                                        :key="manager.id"
                                        :value="manager.id"
                                    >
                                        {{ manager.name }}
                                    </option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Accordion type="multiple" class="lg:hidden">
                <AccordionItem
                    v-for="row in rows"
                    :key="row.id ?? 'any'"
                    :value="String(row.id ?? 'any')"
                >
                    <AccordionTrigger>{{ row.name }}</AccordionTrigger>
                    <AccordionContent>
                        <div class="grid gap-3">
                            <label
                                v-for="category in categories"
                                :key="category.value"
                                class="grid gap-1 text-sm"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 font-medium"
                                >
                                    <component
                                        :is="categoryIcon[category.value]"
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    {{ category.label }}
                                </span>
                                <select
                                    class="h-11 w-full rounded-[12px] border border-input bg-surface px-2 text-[0.9375rem]"
                                    :value="
                                        cells[
                                            cellKey(row.id, category.value)
                                        ] ?? ''
                                    "
                                    data-test="routing-cell"
                                    :data-department="row.id ?? 'any'"
                                    :data-category="category.value"
                                    @change="
                                        setCell(
                                            row.id,
                                            category.value,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="">
                                        {{ effective(row.id, category.value) }}
                                    </option>
                                    <option
                                        v-for="manager in managers"
                                        :key="manager.id"
                                        :value="manager.id"
                                    >
                                        {{ manager.name }}
                                    </option>
                                </select>
                            </label>
                        </div>
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
        </SectionCard>
    </div>

    <div
        v-if="dirty"
        class="fixed inset-x-0 bottom-16 z-20 flex justify-center px-4 md:bottom-4 md:pl-[88px]"
    >
        <div
            class="flex items-center gap-3 rounded-card border bg-popover px-4 py-3 shadow-clay-lg"
        >
            <span class="text-sm font-medium">{{
                t('ui.departments.unsaved')
            }}</span>
            <Button variant="ghost" size="sm" @click="discard">{{
                t('ui.common.cancel')
            }}</Button>
            <Button
                size="sm"
                :disabled="saving"
                data-test="routing-save"
                @click="save"
            >
                <Spinner v-if="saving" />
                {{ t('ui.departments.save_routing') }}
            </Button>
        </div>
    </div>

    <DepartmentDialog
        v-model:open="dialogOpen"
        :department="editing"
        :managers="managers"
    />

    <ConfirmDialog
        v-model:open="archiveOpen"
        :title="
            t('ui.departments.archive_title', { name: archiving?.name ?? '' })
        "
        :description="t('ui.departments.archive_body')"
        :confirm-label="t('ui.departments.archive')"
        destructive
        :processing="processing"
        @confirm="confirmArchive"
    />
</template>

<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import {
    Archive,
    BookOpen,
    CalendarClock,
    Ellipsis,
    Eye,
    FileCheck,
    FilterX,
    Package,
    RefreshCw,
    ScanText,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import EmptyState from '@/components/common/EmptyState.vue';
import LaravelPagination from '@/components/common/LaravelPagination.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SearchInput from '@/components/common/SearchInput.vue';
import ApprovalBadge from '@/components/knowledge/ApprovalBadge.vue';
import DocumentStatusBadge from '@/components/knowledge/DocumentStatusBadge.vue';
import UploadDialog from '@/components/knowledge/UploadDialog.vue';
import DepartmentChips from '@/components/opsbot/DepartmentChips.vue';
import FilterSelect from '@/components/opsbot/FilterSelect.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import StarterPackForm from '@/components/opsbot/StarterPackForm.vue';
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
import { t, tc } from '@/lib/i18n';
import { meterLevel } from '@/lib/opsbot';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/models';
import type {
    DepartmentRef,
    KbDocument,
    KnowledgeFilters,
    LimitUsage,
    StarterPack,
} from '@/types/opsbot';

const props = defineProps<{
    documents: Paginated<KbDocument>;
    filters: KnowledgeFilters;
    departments: DepartmentRef[];
    roles: Array<{ value: string; label: string }>;
    counts: {
        draft: number;
        approved: number;
        archived: number;
        review_overdue: number;
    };
    starterPacks: StarterPack[];
    limits: LimitUsage;
    accept: string[];
}>();

const fmt = useFormat();
const { can } = useCan();
const upgrade = useUpgrade();

const canManage = computed(() => can('knowledge.manage'));
const canApprove = computed(() => can('knowledge.approve'));

const { filters, reset } = useFilters(
    () => endpoints.knowledge.index().url,
    {
        q: props.filters.q ?? null,
        status: props.filters.status ?? null,
        approval: props.filters.approval ?? null,
        department_id: props.filters.department_id ?? null,
        review: props.filters.review ?? null,
    },
    ['documents', 'filters', 'counts', 'limits'],
);

type Tab = 'all' | 'draft' | 'approved' | 'archived' | 'review';

const tab = computed<Tab>(() =>
    filters.review === 'overdue'
        ? 'review'
        : ((filters.approval as Tab | null) ?? 'all'),
);

function setTab(value: Tab): void {
    selected.value = [];
    filters.review = value === 'review' ? 'overdue' : null;
    filters.approval = value === 'review' || value === 'all' ? null : value;
}

const tabs = computed(() => [
    {
        value: 'all' as Tab,
        label: t('ui.knowledge.tabs.all'),
        count:
            props.counts.draft + props.counts.approved + props.counts.archived,
    },
    {
        value: 'draft' as Tab,
        label: t('ui.knowledge.tabs.draft'),
        count: props.counts.draft,
    },
    {
        value: 'approved' as Tab,
        label: t('ui.knowledge.tabs.approved'),
        count: props.counts.approved,
    },
    {
        value: 'archived' as Tab,
        label: t('ui.knowledge.tabs.archived'),
        count: props.counts.archived,
    },
    {
        value: 'review' as Tab,
        label: t('ui.knowledge.tabs.review'),
        count: props.counts.review_overdue,
    },
]);

const filtered = computed(() =>
    Boolean(filters.q || filters.status || filters.department_id),
);
const total = computed(
    () => props.counts.draft + props.counts.approved + props.counts.archived,
);

const inFlight = computed(() =>
    props.documents.data.some(
        (doc) => doc.status === 'queued' || doc.status === 'processing',
    ),
);
const poll = usePoll(
    3000,
    { only: ['documents', 'counts', 'limits'] },
    { autoStart: false },
);

function syncPoll(): void {
    if (inFlight.value && document.visibilityState === 'visible') {
        poll.start();
    } else {
        poll.stop();
    }
}

watch(inFlight, syncPoll, { immediate: true });
onMounted(() => document.addEventListener('visibilitychange', syncPoll));
onBeforeUnmount(() => {
    poll.stop();
    document.removeEventListener('visibilitychange', syncPoll);
});

const documentsFull = computed(
    () => meterLevel(props.limits.documents) === 'full',
);
const pack = computed(
    () => props.starterPacks.find((item) => !item.imported) ?? null,
);

const uploadOpen = ref(false);
const packOpen = ref(false);

function openUpload(): void {
    if (documentsFull.value) {
        upgrade.show('documents');

        return;
    }

    uploadOpen.value = true;
}

onMounted(() => {
    if (
        canManage.value &&
        new URLSearchParams(window.location.search).get('upload') === '1'
    ) {
        openUpload();
    }
});

const selected = ref<number[]>([]);
const approvable = computed(() =>
    props.documents.data.filter(
        (doc) => doc.approval_status === 'draft' && doc.status === 'indexed',
    ),
);

function toggle(id: number, value: boolean): void {
    selected.value = value
        ? [...new Set([...selected.value, id])]
        : selected.value.filter((item) => item !== id);
}

const bulkOpen = ref(false);
const bulkDate = ref(new Date().toISOString().slice(0, 10));
const bulkBusy = ref(false);

function approveBulk(): void {
    bulkBusy.value = true;
    router.post(
        endpoints.knowledge.approveBulk().url,
        {
            document_ids: selected.value,
            effective_date: bulkDate.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                bulkOpen.value = false;
                selected.value = [];
            },
            onFinish: () => (bulkBusy.value = false),
        },
    );
}

const target = ref<{ doc: KbDocument; action: 'archive' | 'delete' } | null>(
    null,
);
const targetOpen = computed({
    get: () => target.value !== null,
    set: (value: boolean) => {
        if (!value) {
            target.value = null;
        }
    },
});
const processing = ref(false);

function confirmTarget(): void {
    if (!target.value) {
        return;
    }

    const { doc, action } = target.value;
    processing.value = true;
    const options = {
        preserveScroll: true,
        onSuccess: () => (target.value = null),
        onFinish: () => (processing.value = false),
    };

    if (action === 'archive') {
        router.post(endpoints.knowledge.archive(doc.id).url, {}, options);
    } else {
        router.delete(endpoints.knowledge.destroy(doc.id).url, options);
    }
}

function retry(doc: KbDocument): void {
    router.post(
        endpoints.knowledge.retry(doc.id).url,
        {},
        { preserveScroll: true },
    );
}

const today = new Date().toISOString().slice(0, 10);

function futureEffective(doc: KbDocument): boolean {
    return (
        doc.approval_status === 'approved' &&
        Boolean(doc.effective_date && doc.effective_date > today)
    );
}

const supersedable = computed(() =>
    props.documents.data
        .filter((doc) => doc.approval_status === 'approved')
        .map((doc) => ({ id: doc.id, title: doc.title, version: doc.version })),
);
</script>

<template>
    <Head :title="t('ui.knowledge.title')" />

    <div
        class="mx-auto flex w-full max-w-[1440px] flex-col gap-5 px-4 py-6 md:px-6"
    >
        <PageHeader
            :title="t('ui.knowledge.title')"
            :description="t('ui.knowledge.subtitle')"
            :icon="BookOpen"
        >
            <template v-if="canManage" #actions>
                <Button
                    v-if="pack"
                    variant="secondary"
                    data-test="knowledge-starter-pack"
                    @click="packOpen = true"
                >
                    <Package class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.pack.open') }}
                </Button>
                <Button data-test="knowledge-upload" @click="openUpload">
                    <Upload class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.upload.open') }}
                </Button>
            </template>
        </PageHeader>

        <p class="text-caption flex flex-wrap gap-x-4 gap-y-1">
            <span v-if="limits.documents">
                {{
                    t('ui.knowledge.limits.documents', {
                        used: fmt.number(limits.documents.used),
                        limit:
                            limits.documents.limit === null
                                ? '∞'
                                : fmt.number(limits.documents.limit),
                    })
                }}
            </span>
            <span v-if="limits.storage_mb">
                {{
                    t('ui.knowledge.limits.storage', {
                        used: fmt.bytes(limits.storage_mb.used * 1048576),
                        limit:
                            limits.storage_mb.limit === null
                                ? '∞'
                                : fmt.bytes(limits.storage_mb.limit * 1048576),
                    })
                }}
            </span>
            <span v-if="limits.max_pages_per_document?.limit">
                {{
                    t('ui.knowledge.limits.pages', {
                        limit: fmt.number(limits.max_pages_per_document.limit),
                    })
                }}
            </span>
        </p>

        <div
            role="tablist"
            :aria-label="t('ui.knowledge.tabs.label')"
            class="flex scrollbar-none gap-1 overflow-x-auto rounded-control border bg-surface-2 p-1"
        >
            <button
                v-for="item in tabs"
                :key="item.value"
                type="button"
                role="tab"
                :aria-selected="tab === item.value"
                :class="
                    cn(
                        'inline-flex min-h-10 shrink-0 items-center gap-1.5 rounded-[10px] px-3 text-sm font-medium whitespace-nowrap',
                        tab === item.value
                            ? 'bg-surface shadow-clay-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )
                "
                data-test="knowledge-tab"
                :data-tab="item.value"
                @click="setTab(item.value)"
            >
                {{ item.label }}
                <span
                    :class="[
                        'rounded-chip px-1.5 font-mono text-xs',
                        item.value === 'review' && item.count > 0
                            ? 'bg-sun-soft text-sun-strong'
                            : 'bg-surface-2',
                    ]"
                    >{{ item.count }}</span
                >
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <SearchInput
                v-model="filters.q"
                :placeholder="t('ui.knowledge.search')"
                class="w-full sm:w-64"
            />
            <FilterSelect
                v-model="filters.status"
                :label="t('ui.knowledge.processing')"
                :all-label="t('ui.common.all_statuses')"
                :options="
                    (
                        ['queued', 'processing', 'indexed', 'failed'] as const
                    ).map((s) => ({
                        value: s,
                        label: t(`documents.status.${s}`),
                    }))
                "
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
            <Button v-if="filtered" variant="ghost" @click="reset">
                <FilterX class="size-4" aria-hidden="true" />
                {{ t('ui.common.clear_filters') }}
            </Button>
        </div>

        <div
            v-if="selected.length && canApprove"
            class="flex flex-wrap items-center gap-3 rounded-card bg-primary-soft px-4 py-3"
        >
            <span class="text-sm font-semibold">{{
                tc('ui.knowledge.selected', selected.length)
            }}</span>
            <Button
                size="sm"
                class="ml-auto"
                data-test="knowledge-bulk-approve"
                @click="bulkOpen = true"
            >
                <FileCheck class="size-4" aria-hidden="true" />
                {{
                    t('ui.knowledge.approve_selected', {
                        count: selected.length,
                    })
                }}
            </Button>
            <Button size="sm" variant="ghost" @click="selected = []">{{
                t('ui.common.clear_selection')
            }}</Button>
        </div>

        <EmptyState
            v-if="total === 0"
            :icon="BookOpen"
            scene="documents"
            :title="t('ui.knowledge.empty_title')"
            :description="t('ui.knowledge.empty_body')"
            class="rounded-card border bg-surface shadow-clay-sm"
        >
            <template v-if="canManage">
                <Button @click="openUpload">
                    <Upload class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.upload.open') }}
                </Button>
                <Button
                    v-if="pack"
                    variant="secondary"
                    @click="packOpen = true"
                >
                    <Package class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.pack.open') }}
                </Button>
            </template>
        </EmptyState>

        <EmptyState
            v-else-if="!documents.data.length"
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
                            t('ui.knowledge.title')
                        }}
                    </caption>
                    <thead class="max-md:hidden">
                        <tr
                            class="h-11 bg-surface-2 text-left text-muted-foreground"
                        >
                            <th scope="col" class="w-10 px-4">
                                <span class="sr-only">{{
                                    t('ui.common.select')
                                }}</span>
                            </th>
                            <th scope="col" class="px-3 font-semibold">
                                {{ t('ui.knowledge.fields.title') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 font-semibold max-xl:hidden"
                            >
                                {{ t('ui.knowledge.fields.departments') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 font-semibold max-2xl:hidden"
                            >
                                {{ t('ui.knowledge.fields.roles') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 font-semibold max-lg:hidden"
                            >
                                {{ t('ui.knowledge.fields.version') }}
                            </th>
                            <th scope="col" class="px-3 font-semibold">
                                {{ t('ui.knowledge.status_col') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 font-semibold max-lg:hidden"
                            >
                                {{ t('ui.knowledge.effective') }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 text-right font-semibold max-xl:hidden"
                            >
                                {{ t('ui.knowledge.cited') }}
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
                            v-for="doc in documents.data"
                            :key="doc.id"
                            class="grid grid-cols-[auto_1fr_auto] gap-x-3 gap-y-2 p-4 hover:bg-surface-2 md:table-row md:h-[52px] md:p-0"
                            data-test="knowledge-row"
                            :data-document-id="doc.id"
                            :data-approval="doc.approval_status"
                            :data-status="doc.status"
                        >
                            <td class="md:px-4">
                                <Checkbox
                                    v-if="
                                        canApprove &&
                                        doc.approval_status === 'draft' &&
                                        doc.status === 'indexed'
                                    "
                                    :model-value="selected.includes(doc.id)"
                                    :aria-label="
                                        t('ui.knowledge.select_doc', {
                                            title: doc.title,
                                        })
                                    "
                                    data-test="knowledge-row-select"
                                    @update:model-value="
                                        (value) =>
                                            toggle(doc.id, value === true)
                                    "
                                />
                            </td>
                            <td class="min-w-0 md:px-3 md:py-2">
                                <Link
                                    :href="endpoints.knowledge.show(doc.id)"
                                    :class="[
                                        'font-semibold [overflow-wrap:anywhere] hover:underline',
                                        doc.approval_status === 'archived'
                                            ? 'text-muted-foreground'
                                            : '',
                                    ]"
                                    >{{ doc.title }}</Link
                                >
                                <p
                                    class="flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <ScanText
                                        v-if="doc.ocr_used"
                                        class="size-3.5"
                                        :aria-label="t('ui.knowledge.ocr_used')"
                                    />
                                    <span class="truncate">{{
                                        doc.original_name
                                    }}</span>
                                </p>
                                <p
                                    v-if="doc.status === 'failed'"
                                    class="mt-1 text-xs text-coral-strong"
                                >
                                    {{
                                        doc.error_code
                                            ? t(
                                                  `documents.errors.${doc.error_code}`,
                                              )
                                            : doc.error
                                    }}
                                </p>
                                <div
                                    class="mt-1.5 flex flex-wrap gap-1 md:hidden"
                                >
                                    <DocumentStatusBadge :document="doc" />
                                    <ApprovalBadge
                                        :status="doc.approval_status"
                                    />
                                </div>
                            </td>
                            <td class="px-3 max-xl:hidden">
                                <DepartmentChips
                                    :departments="doc.departments"
                                    :max="2"
                                />
                            </td>
                            <td class="px-3 text-xs max-2xl:hidden">
                                {{
                                    doc.roles.length
                                        ? doc.roles
                                              .map((role) => t(`roles.${role}`))
                                              .join(', ')
                                        : t('ui.knowledge.all_roles')
                                }}
                            </td>
                            <td class="px-3 font-mono text-xs max-lg:hidden">
                                {{ doc.version ?? '—' }}
                            </td>
                            <td class="px-3 max-md:hidden">
                                <div class="flex flex-col items-start gap-1">
                                    <DocumentStatusBadge
                                        v-if="doc.status !== 'indexed'"
                                        :document="doc"
                                    />
                                    <ApprovalBadge
                                        :status="doc.approval_status"
                                    />
                                    <OpsBadge
                                        v-if="doc.review_overdue"
                                        tone="sun"
                                        :icon="CalendarClock"
                                        :label="
                                            t('ui.knowledge.review_overdue')
                                        "
                                    />
                                </div>
                            </td>
                            <td class="px-3 text-xs max-lg:hidden">
                                <OpsBadge
                                    v-if="futureEffective(doc)"
                                    tone="primary"
                                    :label="
                                        t('ui.knowledge.from_date', {
                                            date: fmt.date(
                                                `${doc.effective_date}T12:00:00`,
                                            ),
                                        })
                                    "
                                />
                                <span v-else>{{
                                    doc.effective_date
                                        ? fmt.date(
                                              `${doc.effective_date}T12:00:00`,
                                          )
                                        : '—'
                                }}</span>
                            </td>
                            <td
                                class="px-3 text-right tabular-nums max-xl:hidden"
                            >
                                {{ doc.cited_30d }}
                            </td>
                            <td
                                class="col-start-3 row-start-1 text-right md:px-3"
                            >
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            size="icon-sm"
                                            variant="ghost"
                                            :aria-label="
                                                t('ui.knowledge.actions_for', {
                                                    title: doc.title,
                                                })
                                            "
                                        >
                                            <Ellipsis
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem as-child>
                                            <Link
                                                :href="
                                                    endpoints.knowledge.show(
                                                        doc.id,
                                                    )
                                                "
                                            >
                                                <Eye
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{ t('ui.common.view') }}
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                canApprove &&
                                                doc.approval_status ===
                                                    'draft' &&
                                                doc.status === 'indexed'
                                            "
                                            as-child
                                        >
                                            <Link
                                                :href="
                                                    endpoints.knowledge.show(
                                                        doc.id,
                                                    )
                                                "
                                                data-test="knowledge-approve"
                                            >
                                                <FileCheck
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{ t('ui.knowledge.approve') }}
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                canManage &&
                                                doc.status === 'failed'
                                            "
                                            @select="retry(doc)"
                                        >
                                            <RefreshCw
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            {{ t('ui.knowledge.retry') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator
                                            v-if="canApprove"
                                        />
                                        <DropdownMenuItem
                                            v-if="
                                                canApprove &&
                                                doc.approval_status !==
                                                    'archived'
                                            "
                                            data-test="knowledge-archive"
                                            @select="
                                                target = {
                                                    doc,
                                                    action: 'archive',
                                                }
                                            "
                                        >
                                            <Archive
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            {{ t('ui.knowledge.archive') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                canApprove &&
                                                doc.approval_status !==
                                                    'approved'
                                            "
                                            class="text-destructive"
                                            @select="
                                                target = {
                                                    doc,
                                                    action: 'delete',
                                                }
                                            "
                                        >
                                            <Trash2
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            {{ t('ui.common.delete') }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <LaravelPagination
            v-if="documents.meta.last_page > 1"
            :links="documents.links"
            :meta="documents.meta"
            :count="documents.data.length"
            :only="['documents']"
        />
    </div>

    <UploadDialog
        v-model:open="uploadOpen"
        :accept="accept"
        :max-size-mb="limits.max_file_mb?.limit ?? 25"
        :departments="departments"
        :roles="roles"
        :supersedable="supersedable"
        :limit-reached="documentsFull"
        :lock-departments="!can('knowledge.approve')"
    />

    <Dialog v-model:open="packOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('ui.knowledge.pack.title') }}</DialogTitle>
                <DialogDescription>{{
                    t('ui.knowledge.pack.hint')
                }}</DialogDescription>
            </DialogHeader>
            <StarterPackForm
                v-if="pack"
                :pack="pack"
                @imported="packOpen = false"
            />
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="bulkOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    t('ui.knowledge.bulk_title', { count: selected.length })
                }}</DialogTitle>
                <DialogDescription>{{
                    t('ui.knowledge.bulk_hint')
                }}</DialogDescription>
            </DialogHeader>
            <ul
                class="max-h-48 list-disc space-y-1 overflow-y-auto pl-5 text-sm"
            >
                <li
                    v-for="doc in approvable.filter((d) =>
                        selected.includes(d.id),
                    )"
                    :key="doc.id"
                >
                    {{ doc.title }}
                </li>
            </ul>
            <div class="grid gap-2">
                <Label for="bulk-effective">{{
                    t('ui.knowledge.effective')
                }}</Label>
                <Input
                    id="bulk-effective"
                    v-model="bulkDate"
                    type="date"
                    class="w-48"
                />
            </div>
            <div class="flex justify-end gap-2">
                <Button variant="ghost" @click="bulkOpen = false">{{
                    t('ui.common.cancel')
                }}</Button>
                <Button
                    :disabled="bulkBusy"
                    data-test="knowledge-approve-confirm"
                    @click="approveBulk"
                >
                    <FileCheck class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.approve') }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="targetOpen"
        :title="
            target?.action === 'archive'
                ? t('ui.knowledge.archive_title', {
                      title: target?.doc.title ?? '',
                  })
                : t('ui.knowledge.delete_title', {
                      title: target?.doc.title ?? '',
                  })
        "
        :description="
            target?.action === 'archive'
                ? t('ui.knowledge.archive_body')
                : t('ui.knowledge.delete_body')
        "
        :confirm-label="
            target?.action === 'archive'
                ? t('ui.knowledge.archive')
                : t('ui.common.delete')
        "
        destructive
        :processing="processing"
        @confirm="confirmTarget"
    />
</template>

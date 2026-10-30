<script setup lang="ts">
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import {
    Archive,
    ArrowLeft,
    CircleAlert,
    Download,
    Eye,
    FileCheck,
    FileSearch,
    GitBranch,
    Layers,
    Pencil,
    RefreshCw,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import ApprovalBadge from '@/components/knowledge/ApprovalBadge.vue';
import type { DocumentMeta } from '@/components/knowledge/DocumentMetaFields.vue';
import DocumentMetaFields from '@/components/knowledge/DocumentMetaFields.vue';
import DocumentStatusBadge from '@/components/knowledge/DocumentStatusBadge.vue';
import DepartmentChips from '@/components/opsbot/DepartmentChips.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useCan } from '@/composables/useCan';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { jsonRequest, JsonRequestError } from '@/lib/http';
import { t, tc } from '@/lib/i18n';
import { fetchPartial } from '@/lib/partial';
import type { ApprovalStatus, DepartmentRef, KbDocument } from '@/types/opsbot';

const props = defineProps<{
    document: KbDocument;
    chunksPreview: Array<{
        chunk_id: number;
        page: number | null;
        section: string | null;
        content: string;
    }>;
    versions: Array<{
        id: number;
        title: string;
        version: string | null;
        approval_status: ApprovalStatus;
        effective_date: string | null;
    }>;
    citedRecently: Array<{
        query_id: number;
        question: string;
        created_at: string;
    }>;
    can: { approve: boolean; archive: boolean; edit: boolean; test: boolean };
}>();

const page = usePage();
const { can: allowed } = useCan();
const fmt = useFormat();

const inFlight = computed(
    () =>
        props.document.status === 'queued' ||
        props.document.status === 'processing',
);
const poll = usePoll(
    3000,
    { only: ['document', 'chunksPreview', 'can'] },
    { autoStart: false },
);

watch(inFlight, (value) => (value ? poll.start() : poll.stop()), {
    immediate: true,
});
onBeforeUnmount(() => poll.stop());

const shownChunks = ref(20);

const plusYear = (date: string) => {
    const value = new Date(`${date}T12:00:00`);
    value.setFullYear(value.getFullYear() + 1);

    return value.toISOString().slice(0, 10);
};

const todayIso = new Date().toISOString().slice(0, 10);

const approveOpen = ref(false);
const approveForm = useForm({
    effective_date: todayIso,
    review_due_at: plusYear(todayIso),
});

watch(
    () => approveForm.effective_date,
    (value) => {
        if (value) {
            approveForm.review_due_at = plusYear(value);
        }
    },
);

function approve(): void {
    approveForm.post(endpoints.knowledge.approve(props.document.id).url, {
        preserveScroll: true,
        onSuccess: () => (approveOpen.value = false),
    });
}

const confirmAction = ref<'archive' | 'delete' | null>(null);
const confirmOpen = computed({
    get: () => confirmAction.value !== null,
    set: (value: boolean) => {
        if (!value) {
            confirmAction.value = null;
        }
    },
});
const processing = ref(false);

function runConfirm(): void {
    processing.value = true;
    const options = {
        preserveScroll: true,
        onFinish: () => (processing.value = false),
        onSuccess: () => (confirmAction.value = null),
    };

    if (confirmAction.value === 'archive') {
        router.post(
            endpoints.knowledge.archive(props.document.id).url,
            {},
            options,
        );
    } else {
        router.delete(
            endpoints.knowledge.destroy(props.document.id).url,
            options,
        );
    }
}

function retry(): void {
    router.post(
        endpoints.knowledge.retry(props.document.id).url,
        {},
        { preserveScroll: true },
    );
}

const editOpen = ref(false);
const departments = ref<DepartmentRef[]>([]);
const roles = ref<Array<{ value: string; label: string }>>([]);
const meta = ref<DocumentMeta>({
    title: '',
    department_ids: [],
    roles: [],
    tags: [],
    version: '',
    language: 'auto',
    supersedes_document_id: null,
});
const editForm = useForm({});
const reviewDue = ref('');

async function openEdit(): Promise<void> {
    meta.value = {
        title: props.document.title,
        department_ids: [...props.document.department_ids],
        roles: [...props.document.roles],
        tags: [...props.document.tags],
        version: props.document.version ?? '',
        language: props.document.language ?? 'auto',
        supersedes_document_id: null,
    };
    reviewDue.value = props.document.review_due_at ?? '';
    editOpen.value = true;

    const [deps, roleList] = await Promise.all([
        fetchPartial<DepartmentRef[]>(
            endpoints.knowledge.index().url,
            'knowledge/Index',
            'departments',
            page.version,
        ),
        fetchPartial<Array<{ value: string; label: string }>>(
            endpoints.knowledge.index().url,
            'knowledge/Index',
            'roles',
            page.version,
        ),
    ]);
    departments.value = deps ?? props.document.departments;
    roles.value = roleList ?? [];
}

function saveMeta(): void {
    editForm
        .transform(() => ({
            title: meta.value.title.trim(),
            department_ids: meta.value.department_ids,
            roles: meta.value.roles,
            tags: meta.value.tags,
            version: meta.value.version.trim() || null,
            language:
                meta.value.language === 'auto' ? null : meta.value.language,
            ...(props.document.approval_status === 'approved'
                ? { review_due_at: reviewDue.value || null }
                : {}),
        }))
        .patch(endpoints.knowledge.update(props.document.id).url, {
            preserveScroll: true,
            onSuccess: () => (editOpen.value = false),
        });
}

type Hit = {
    chunk_id: number;
    page: number | null;
    section: string | null;
    snippet: string;
    confidence: number;
};
const question = ref('');
const testing = ref(false);
const testResult = ref<{ confidence: number; hits: Hit[] } | null>(null);
const testError = ref<string | null>(null);

async function runTest(): Promise<void> {
    if (question.value.trim().length < 2) {
        return;
    }

    testing.value = true;
    testError.value = null;

    try {
        testResult.value = await jsonRequest<{
            confidence: number;
            hits: Hit[];
        }>(endpoints.knowledge.test(props.document.id).url, 'POST', {
            question: question.value.trim(),
        });
    } catch (error) {
        testResult.value = null;
        testError.value =
            error instanceof JsonRequestError &&
            typeof error.body?.message === 'string'
                ? error.body.message
                : t('ui.errors.generic');
    } finally {
        testing.value = false;
    }
}

function band(value: number): string {
    return value >= 0.7
        ? 'bg-mint-strong'
        : value >= 0.4
          ? 'bg-warning'
          : 'bg-destructive';
}

function highlight(snippet: string): Array<{ text: string; mark: boolean }> {
    const terms = question.value
        .toLocaleLowerCase()
        .split(/[^\p{L}\p{N}]+/u)
        .filter((term) => term.length > 3);

    if (!terms.length) {
        return [{ text: snippet, mark: false }];
    }

    const pattern = new RegExp(
        `(${terms.map((term) => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`,
        'giu',
    );

    return snippet.split(pattern).map((part) => ({
        text: part,
        mark: terms.includes(part.toLocaleLowerCase()),
    }));
}

const futureEffective = computed(
    () =>
        props.document.approval_status === 'approved' &&
        Boolean(
            props.document.effective_date &&
            props.document.effective_date > todayIso,
        ),
);
</script>

<template>
    <Head :title="document.title" />

    <div
        class="mx-auto flex w-full max-w-[1280px] flex-col gap-5 px-4 py-6 md:px-6"
    >
        <Link
            :href="endpoints.knowledge.index()"
            class="inline-flex min-h-11 items-center gap-1.5 self-start text-sm font-medium text-muted-foreground hover:text-foreground"
        >
            <ArrowLeft class="size-4" aria-hidden="true" />
            {{ t('ui.knowledge.back') }}
        </Link>

        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <div class="min-w-0 space-y-2">
                <h1 class="text-h1 text-balance [overflow-wrap:anywhere]">
                    {{ document.title }}
                </h1>
                <div class="flex flex-wrap items-center gap-1.5">
                    <DocumentStatusBadge :document="document" />
                    <ApprovalBadge :status="document.approval_status" />
                    <OpsBadge
                        v-if="document.review_overdue"
                        tone="sun"
                        :label="t('ui.knowledge.review_overdue')"
                    />
                    <OpsBadge
                        v-if="futureEffective && document.effective_date"
                        tone="primary"
                        :label="
                            t('ui.knowledge.from_date', {
                                date: fmt.date(
                                    `${document.effective_date}T12:00:00`,
                                ),
                            })
                        "
                    />
                </div>
                <p
                    v-if="document.status === 'failed'"
                    class="flex items-center gap-1.5 text-sm text-coral-strong"
                >
                    <CircleAlert class="size-4" aria-hidden="true" />
                    {{
                        document.error_code
                            ? t(`documents.errors.${document.error_code}`)
                            : document.error
                    }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="can.approve"
                    data-test="knowledge-approve"
                    @click="approveOpen = true"
                >
                    <FileCheck class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.approve') }}
                </Button>
                <Button v-if="can.edit" variant="secondary" @click="openEdit">
                    <Pencil class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.edit_meta') }}
                </Button>
                <Button
                    v-if="document.status === 'failed'"
                    variant="secondary"
                    @click="retry"
                >
                    <RefreshCw class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.retry') }}
                </Button>
                <Button variant="ghost" as-child>
                    <a :href="endpoints.knowledge.download(document.id).url">
                        <Download class="size-4" aria-hidden="true" />
                        {{ t('ui.common.download') }}
                    </a>
                </Button>
                <Button
                    v-if="can.archive"
                    variant="ghost"
                    data-test="knowledge-archive"
                    @click="confirmAction = 'archive'"
                >
                    <Archive class="size-4" aria-hidden="true" />
                    {{ t('ui.knowledge.archive') }}
                </Button>
                <Button
                    v-if="
                        can.archive !== undefined &&
                        document.approval_status !== 'approved' &&
                        allowed('knowledge.approve')
                    "
                    variant="ghost"
                    class="text-destructive"
                    @click="confirmAction = 'delete'"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                    {{ t('ui.common.delete') }}
                </Button>
            </div>
        </header>

        <div class="grid gap-6 lg:grid-cols-12">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-8">
                <SectionCard
                    v-if="can.test"
                    :title="t('ui.knowledge.test_title')"
                    :description="t('ui.knowledge.test_hint')"
                    :icon="FileSearch"
                >
                    <form
                        class="flex flex-col gap-2 sm:flex-row"
                        @submit.prevent="runTest"
                    >
                        <Label for="test-question" class="sr-only">{{
                            t('ui.knowledge.test_title')
                        }}</Label>
                        <Input
                            id="test-question"
                            v-model="question"
                            maxlength="500"
                            :placeholder="t('ui.knowledge.test_placeholder')"
                            data-test="knowledge-test-input"
                        />
                        <Button
                            type="submit"
                            :disabled="testing || question.trim().length < 2"
                            data-test="knowledge-test-submit"
                        >
                            <Spinner v-if="testing" />
                            <Search v-else class="size-4" aria-hidden="true" />
                            {{ t('ui.knowledge.test_run') }}
                        </Button>
                    </form>
                    <div aria-live="polite" class="mt-4 space-y-3">
                        <p v-if="testError" class="text-sm text-destructive">
                            {{ testError }}
                        </p>
                        <template v-else-if="testResult">
                            <p class="text-sm font-semibold">
                                {{
                                    t('ui.knowledge.test_confidence', {
                                        percent: fmt.percent(
                                            testResult.confidence,
                                        ),
                                    })
                                }}
                            </p>
                            <p
                                v-if="!testResult.hits.length"
                                class="text-sm text-muted-foreground"
                            >
                                {{ t('ui.knowledge.test_no_hits') }}
                            </p>
                            <article
                                v-for="hit in testResult.hits"
                                :key="hit.chunk_id"
                                class="space-y-2 rounded-2xl border p-3"
                                data-test="knowledge-test-hit"
                            >
                                <div
                                    class="flex items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <span v-if="hit.section" class="truncate">{{
                                        hit.section
                                    }}</span>
                                    <span v-if="hit.page"
                                        >·
                                        {{
                                            t('ui.viewer.page', {
                                                page: hit.page,
                                            })
                                        }}</span
                                    >
                                    <span
                                        class="ml-auto inline-flex items-center gap-1.5 font-semibold text-foreground tabular-nums"
                                    >
                                        <span
                                            class="h-1.5 w-16 overflow-hidden rounded-chip bg-neutral-soft"
                                        >
                                            <span
                                                :class="[
                                                    'block h-full rounded-chip',
                                                    band(hit.confidence),
                                                ]"
                                                :style="{
                                                    width: `${Math.round(hit.confidence * 100)}%`,
                                                }"
                                            />
                                        </span>
                                        {{ fmt.percent(hit.confidence) }}
                                    </span>
                                </div>
                                <p class="text-sm leading-relaxed">
                                    <template
                                        v-for="(part, index) in highlight(
                                            hit.snippet,
                                        )"
                                        :key="index"
                                    >
                                        <mark
                                            v-if="part.mark"
                                            class="rounded bg-mark px-0.5 text-foreground"
                                            >{{ part.text }}</mark
                                        >
                                        <template v-else>{{
                                            part.text
                                        }}</template>
                                    </template>
                                </p>
                            </article>
                        </template>
                    </div>
                </SectionCard>

                <SectionCard
                    :title="t('ui.knowledge.chunks_title')"
                    :description="
                        document.chunks
                            ? tc('ui.knowledge.chunks', document.chunks)
                            : null
                    "
                    :icon="Layers"
                >
                    <p
                        v-if="!chunksPreview.length"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            inFlight
                                ? t('ui.knowledge.chunks_processing')
                                : t('ui.knowledge.chunks_empty')
                        }}
                    </p>
                    <ol v-else class="space-y-3">
                        <li
                            v-for="chunk in chunksPreview.slice(0, shownChunks)"
                            :key="chunk.chunk_id"
                            class="rounded-2xl bg-surface-2 p-3"
                        >
                            <p class="mb-1 text-xs text-muted-foreground">
                                <template v-if="chunk.section">{{
                                    chunk.section
                                }}</template>
                                <template v-if="chunk.page">
                                    ·
                                    {{
                                        t('ui.viewer.page', {
                                            page: chunk.page,
                                        })
                                    }}</template
                                >
                            </p>
                            <p class="line-clamp-6 text-sm whitespace-pre-line">
                                {{ chunk.content }}
                            </p>
                        </li>
                    </ol>
                    <Button
                        v-if="chunksPreview.length > shownChunks"
                        variant="ghost"
                        class="mt-3"
                        @click="shownChunks += 20"
                        >{{ t('ui.common.show_more') }}</Button
                    >
                </SectionCard>
            </div>

            <aside class="flex min-w-0 flex-col gap-6 lg:col-span-4">
                <SectionCard :title="t('ui.knowledge.details_title')">
                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm"
                    >
                        <dt class="text-caption">
                            {{ t('ui.knowledge.fields.departments') }}
                        </dt>
                        <dd>
                            <DepartmentChips
                                :departments="document.departments"
                            />
                        </dd>
                        <dt class="text-caption">
                            {{ t('ui.knowledge.fields.roles') }}
                        </dt>
                        <dd>
                            {{
                                document.roles.length
                                    ? document.roles
                                          .map((r) => t(`roles.${r}`))
                                          .join(', ')
                                    : t('ui.knowledge.all_roles')
                            }}
                        </dd>
                        <dt class="text-caption">
                            {{ t('ui.knowledge.fields.tags') }}
                        </dt>
                        <dd>{{ document.tags.join(', ') || '—' }}</dd>
                        <dt class="text-caption">
                            {{ t('ui.knowledge.fields.language') }}
                        </dt>
                        <dd>
                            {{
                                document.language
                                    ? t(
                                          `ui.knowledge.languages.${document.language}`,
                                      )
                                    : '—'
                            }}
                        </dd>
                        <dt class="text-caption">
                            {{ t('ui.knowledge.fields.version') }}
                        </dt>
                        <dd class="font-mono">{{ document.version ?? '—' }}</dd>
                        <dt class="text-caption">
                            {{ t('ui.knowledge.uploaded') }}
                        </dt>
                        <dd>
                            {{ document.uploaded_by?.name ?? '—' }} ·
                            {{ fmt.date(document.created_at) }}
                        </dd>
                        <template v-if="document.approved_at">
                            <dt class="text-caption">
                                {{ t('ui.knowledge.approved') }}
                            </dt>
                            <dd>
                                {{ document.approved_by?.name ?? '—' }} ·
                                {{ fmt.date(document.approved_at) }}
                            </dd>
                        </template>
                        <dt class="text-caption">
                            {{ t('ui.knowledge.effective') }}
                        </dt>
                        <dd>
                            {{
                                document.effective_date
                                    ? fmt.date(
                                          `${document.effective_date}T12:00:00`,
                                      )
                                    : '—'
                            }}
                        </dd>
                        <dt class="text-caption">
                            {{ t('ui.knowledge.review_due') }}
                        </dt>
                        <dd>
                            {{
                                document.review_due_at
                                    ? fmt.date(
                                          `${document.review_due_at}T12:00:00`,
                                      )
                                    : '—'
                            }}
                        </dd>
                        <dt class="text-caption">
                            {{ t('ui.knowledge.size') }}
                        </dt>
                        <dd>
                            {{ fmt.bytes(document.size_bytes)
                            }}<template v-if="document.pages">
                                ·
                                {{
                                    tc('ui.knowledge.pages', document.pages)
                                }}</template
                            >
                        </dd>
                    </dl>
                </SectionCard>

                <SectionCard
                    :title="t('ui.knowledge.versions')"
                    :icon="GitBranch"
                    data-test="knowledge-versions"
                >
                    <ol class="space-y-2">
                        <li
                            v-for="version in versions"
                            :key="version.id"
                            class="flex items-start gap-2 text-sm"
                        >
                            <span
                                :class="[
                                    'mt-1.5 size-2 shrink-0 rounded-full',
                                    version.id === document.id
                                        ? 'bg-primary'
                                        : 'bg-border',
                                ]"
                                aria-hidden="true"
                            />
                            <div class="min-w-0 flex-1">
                                <Link
                                    v-if="version.id !== document.id"
                                    :href="endpoints.knowledge.show(version.id)"
                                    class="font-medium hover:underline"
                                    >{{ version.title }}</Link
                                >
                                <span v-else class="font-semibold">{{
                                    version.title
                                }}</span>
                                <p
                                    class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                                >
                                    <span class="font-mono">{{
                                        version.version ?? '—'
                                    }}</span>
                                    <ApprovalBadge
                                        :status="version.approval_status"
                                    />
                                    <span v-if="version.effective_date">{{
                                        fmt.date(
                                            `${version.effective_date}T12:00:00`,
                                        )
                                    }}</span>
                                </p>
                            </div>
                        </li>
                    </ol>
                </SectionCard>

                <SectionCard
                    :title="t('ui.knowledge.cited_recently')"
                    :description="t('ui.knowledge.cited_audited')"
                    :icon="Eye"
                >
                    <ul v-if="citedRecently.length" class="divide-y">
                        <li
                            v-for="item in citedRecently"
                            :key="item.query_id"
                            class="py-2 text-sm"
                        >
                            <p class="line-clamp-2">{{ item.question }}</p>
                            <p class="text-xs text-muted-foreground">
                                <RelativeTime :value="item.created_at" />
                            </p>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('ui.knowledge.never_cited') }}
                    </p>
                </SectionCard>
            </aside>
        </div>
    </div>

    <Dialog v-model:open="approveOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('ui.knowledge.approve_title') }}</DialogTitle>
                <DialogDescription>{{
                    t('ui.knowledge.approve_hint')
                }}</DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="approve">
                <div class="grid gap-2">
                    <Label for="approve-effective">{{
                        t('ui.knowledge.effective')
                    }}</Label>
                    <Input
                        id="approve-effective"
                        v-model="approveForm.effective_date"
                        type="date"
                    />
                    <p
                        v-if="approveForm.errors.effective_date"
                        class="text-xs text-destructive"
                    >
                        {{ approveForm.errors.effective_date }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="approve-review">{{
                        t('ui.knowledge.review_due')
                    }}</Label>
                    <Input
                        id="approve-review"
                        v-model="approveForm.review_due_at"
                        type="date"
                    />
                    <p
                        v-if="approveForm.errors.review_due_at"
                        class="text-xs text-destructive"
                    >
                        {{ approveForm.errors.review_due_at }}
                    </p>
                </div>
                <p
                    v-if="document.supersedes"
                    class="rounded-2xl bg-surface-2 px-3 py-2 text-sm"
                >
                    {{
                        t('ui.knowledge.supersede_note', {
                            title: document.supersedes.title,
                        })
                    }}
                </p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="approveOpen = false"
                        >{{ t('ui.common.cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="approveForm.processing"
                        data-test="knowledge-approve-confirm"
                    >
                        <Spinner v-if="approveForm.processing" />
                        {{ t('ui.knowledge.approve') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Sheet v-model:open="editOpen">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto sm:max-w-lg"
        >
            <SheetHeader class="px-5 pt-5">
                <SheetTitle>{{ t('ui.knowledge.edit_meta') }}</SheetTitle>
                <SheetDescription>{{
                    document.approval_status === 'approved'
                        ? t('ui.knowledge.edit_approved_hint')
                        : t('ui.knowledge.edit_draft_hint')
                }}</SheetDescription>
            </SheetHeader>
            <form
                id="meta-form"
                class="grid gap-5 px-5 py-4"
                @submit.prevent="saveMeta"
            >
                <DocumentMetaFields
                    v-model="meta"
                    id-prefix="edit"
                    :departments="departments"
                    :roles="roles"
                    :errors="editForm.errors"
                />
                <div
                    v-if="document.approval_status === 'approved'"
                    class="grid gap-2"
                >
                    <Label for="edit-review">{{
                        t('ui.knowledge.review_due')
                    }}</Label>
                    <Input
                        id="edit-review"
                        v-model="reviewDue"
                        type="date"
                        class="w-48"
                    />
                </div>
            </form>
            <SheetFooter class="border-t px-5 py-4">
                <Button variant="ghost" @click="editOpen = false">{{
                    t('ui.common.cancel')
                }}</Button>
                <Button
                    type="submit"
                    form="meta-form"
                    :disabled="editForm.processing"
                >
                    <Spinner v-if="editForm.processing" />
                    {{ t('ui.common.save') }}
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="
            confirmAction === 'archive'
                ? t('ui.knowledge.archive_title', { title: document.title })
                : t('ui.knowledge.delete_title', { title: document.title })
        "
        :description="
            confirmAction === 'archive'
                ? t('ui.knowledge.archive_body')
                : t('ui.knowledge.delete_body')
        "
        :confirm-label="
            confirmAction === 'archive'
                ? t('ui.knowledge.archive')
                : t('ui.common.delete')
        "
        destructive
        :processing="processing"
        @confirm="runConfirm"
    />
</template>

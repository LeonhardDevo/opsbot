<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CalendarDays,
    ChevronDown,
    History,
    ListTree,
    Printer,
    TriangleAlert,
    UsersRound,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import SeoHead from '@/components/marketing/SeoHead.vue';
import Callout from '@/components/opsbot/Callout.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { legalDocIcon, legalDocLabelKey, PUBLIC_LEGAL_DOCS } from '@/lib/legal';
import { cn } from '@/lib/utils';
import type { LegalDoc, LegalVersion } from '@/types/legal';

const props = withDefaults(
    defineProps<{
        doc: LegalDoc;
        versions?: LegalVersion[];
        reviewed?: boolean;
    }>(),
    { versions: () => [], reviewed: false },
);

const fmt = useFormat();

const icon = computed(() => legalDocIcon(props.doc.key));

const toc = computed(() => {
    let section = 0;

    return props.doc.toc
        .filter((entry) => entry.level === 2 || entry.level === 3)
        .map((entry) => {
            if (entry.level === 2) {
                section += 1;
            }

            return {
                ...entry,
                index:
                    entry.level === 2 ? String(section).padStart(2, '0') : null,
            };
        });
});

const related = computed(() =>
    PUBLIC_LEGAL_DOCS.filter((key) => key !== props.doc.key),
);

const sortedVersions = computed(() =>
    [...props.versions].sort((a, b) =>
        b.effective_date.localeCompare(a.effective_date),
    ),
);
const latest = computed(
    () => sortedVersions.value[0]?.version ?? props.doc.version,
);
const isArchived = computed(() => props.doc.version !== latest.value);
const isEmployeeNotice = computed(() => props.doc.key === 'employee-notice');

const selectedVersion = ref(props.doc.version);

watch(
    () => props.doc.version,
    (value) => (selectedVersion.value = value),
);

function switchVersion(value: unknown): void {
    if (typeof value !== 'string' || value === props.doc.version) {
        return;
    }

    router.visit(
        endpoints.legalVersion(
            props.doc.key,
            value === latest.value ? null : value,
        ),
        { preserveScroll: false },
    );
}

const activeId = ref<string | null>(toc.value[0]?.id ?? null);
const mobileToc = ref<HTMLDetailsElement | null>(null);
let observer: IntersectionObserver | null = null;
const visible = new Map<string, number>();

function observeHeadings(): void {
    observer?.disconnect();
    visible.clear();

    if (typeof IntersectionObserver === 'undefined') {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) {
                    visible.set(entry.target.id, entry.boundingClientRect.top);
                } else {
                    visible.delete(entry.target.id);
                }
            }

            if (visible.size > 0) {
                const [first] = [...visible.entries()].sort(
                    (a, b) => a[1] - b[1],
                );
                activeId.value = first[0];
            }
        },
        { rootMargin: '-96px 0px -65% 0px', threshold: [0, 1] },
    );

    for (const entry of toc.value) {
        const element = document.getElementById(entry.id);

        if (element) {
            observer.observe(element);
        }
    }
}

onMounted(observeHeadings);
watch(
    () => props.doc.html,
    () => requestAnimationFrame(observeHeadings),
);
onBeforeUnmount(() => observer?.disconnect());

function jumpTo(event: MouseEvent, id: string): void {
    const element = document.getElementById(id);

    if (!element) {
        return;
    }

    event.preventDefault();
    activeId.value = id;

    if (mobileToc.value) {
        mobileToc.value.open = false;
    }

    element.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'auto'
            : 'smooth',
        block: 'start',
    });
    history.replaceState(history.state, '', `#${id}`);
}

function print(): void {
    window.print();
}

function tocClass(entry: { id: string; level: number }): string {
    return cn(
        'flex min-h-10 items-start gap-2 rounded-xl px-2 py-2 leading-snug transition-colors',
        entry.level === 3 ? 'pl-9 text-[0.8125rem]' : '',
        activeId.value === entry.id
            ? 'bg-primary-soft font-medium text-primary-strong'
            : 'text-muted-foreground hover:bg-surface-2 hover:text-foreground',
    );
}
</script>

<template>
    <SeoHead
        :title="doc.title"
        :description="
            t('marketing.legal.meta_description', { title: doc.title })
        "
    />

    <div class="relative">
        <div
            class="pointer-events-none absolute -top-40 -left-32 size-[30rem] rounded-full bg-[var(--glow-sky)] blur-3xl print:hidden"
            aria-hidden="true"
        />
        <div
            class="legal-page relative mx-auto grid max-w-[1200px] grid-cols-1 gap-8 px-4 py-10 sm:px-6 lg:grid-cols-[16rem_minmax(0,1fr)] lg:py-16"
            data-test="legal-page"
            :data-doc="doc.key"
        >
            <aside class="hidden lg:block print:hidden">
                <nav
                    :aria-label="t('marketing.legal.toc')"
                    class="sticky top-24 flex max-h-[calc(100svh-8rem)] flex-col rounded-card border bg-surface p-2.5 text-sm shadow-clay"
                    data-test="legal-toc"
                >
                    <p
                        class="text-caption flex items-center gap-2 px-2 pt-1.5 pb-2"
                    >
                        <ListTree class="size-3.5" aria-hidden="true" />
                        {{ t('marketing.legal.toc') }}
                    </p>
                    <div class="min-h-0 flex-1 scrollbar-thin overflow-y-auto">
                        <a
                            v-for="entry in toc"
                            :key="entry.id"
                            :href="`#${entry.id}`"
                            :aria-current="
                                activeId === entry.id ? 'location' : undefined
                            "
                            :class="tocClass(entry)"
                            @click="jumpTo($event, entry.id)"
                        >
                            <span
                                v-if="entry.index"
                                class="w-5 shrink-0 font-mono text-xs leading-5"
                                aria-hidden="true"
                                >{{ entry.index }}</span
                            >
                            <span class="min-w-0">{{ entry.text }}</span>
                        </a>
                    </div>
                    <Link
                        :href="endpoints.legalPages.index()"
                        class="mt-2 flex min-h-11 items-center gap-2 rounded-xl border-t px-2 pt-2 font-semibold text-primary hover:text-primary-strong"
                    >
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        {{ t('marketing.legal.all_documents') }}
                    </Link>
                </nav>
            </aside>

            <article
                class="min-w-0 rounded-dialog border bg-surface px-5 py-7 shadow-clay sm:px-10 sm:py-10 print:border-0 print:p-0 print:shadow-none"
            >
                <header class="border-b pb-7">
                    <Link
                        :href="endpoints.legalPages.index()"
                        class="mb-4 inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-primary lg:hidden print:hidden"
                    >
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        {{ t('marketing.legal.all_documents') }}
                    </Link>
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <p
                            class="inline-flex items-center gap-1.5 rounded-chip bg-mint-soft px-3 py-1 text-[0.8125rem] font-semibold text-mint-strong"
                        >
                            <component
                                :is="icon"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            {{ t('marketing.legal.eyebrow') }}
                        </p>
                        <Button
                            variant="ghost"
                            size="sm"
                            class="print:hidden"
                            data-test="legal-print"
                            @click="print"
                        >
                            <Printer class="size-4" aria-hidden="true" />
                            {{ t('marketing.legal.print') }}
                        </Button>
                    </div>
                    <h1
                        class="text-display-l mt-4 break-words hyphens-auto"
                        data-test="legal-title"
                    >
                        {{ doc.title }}
                    </h1>

                    <dl class="mt-5 flex flex-wrap items-center gap-2 text-sm">
                        <div
                            class="inline-flex h-8 items-center gap-1.5 rounded-chip bg-surface-2 px-3"
                        >
                            <dt
                                class="inline-flex items-center gap-1.5 text-muted-foreground"
                            >
                                <CalendarDays
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ t('marketing.legal.effective') }}
                            </dt>
                            <dd class="font-semibold">
                                <time :datetime="doc.effective_date">{{
                                    fmt.date(doc.effective_date, 'long')
                                }}</time>
                            </dd>
                        </div>
                        <div
                            class="inline-flex h-8 items-center gap-1.5 rounded-chip bg-surface-2 px-3"
                        >
                            <dt class="text-muted-foreground">
                                {{ t('marketing.legal.version') }}
                            </dt>
                            <dd
                                class="font-mono text-[0.8125rem] font-medium"
                                data-test="legal-version"
                            >
                                {{ doc.version }}
                            </dd>
                        </div>
                    </dl>

                    <div
                        v-if="sortedVersions.length > 1"
                        class="mt-4 flex flex-wrap items-center gap-2 print:hidden"
                    >
                        <label
                            for="legal-version"
                            class="inline-flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <History class="size-4" aria-hidden="true" />
                            {{ t('marketing.legal.versions') }}
                        </label>
                        <Select
                            v-model="selectedVersion"
                            @update:model-value="switchVersion"
                        >
                            <SelectTrigger
                                id="legal-version"
                                class="w-auto min-w-48"
                                data-test="legal-version-switcher"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="(item, index) in sortedVersions"
                                    :key="item.version"
                                    :value="item.version"
                                >
                                    {{
                                        t(
                                            index === 0
                                                ? 'marketing.legal.version_current'
                                                : 'marketing.legal.version_option',
                                            {
                                                version: item.version,
                                                date: fmt.date(
                                                    item.effective_date,
                                                ),
                                            },
                                        )
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="mt-6 grid gap-3">
                        <Callout
                            v-if="isArchived"
                            tone="sun"
                            :icon="History"
                            role="status"
                            data-test="legal-archived"
                        >
                            {{
                                t('marketing.legal.archived', {
                                    version: latest,
                                })
                            }}
                            <Link
                                :href="endpoints.legal(doc.key)"
                                class="font-semibold text-sun-strong underline underline-offset-2"
                                >{{ t('marketing.legal.view_current') }}</Link
                            >
                        </Callout>
                        <Callout
                            v-if="isEmployeeNotice"
                            tone="mint"
                            :icon="UsersRound"
                            data-test="legal-employee-hint"
                        >
                            {{ t('marketing.legal.employee_notice_hint') }}
                        </Callout>
                        <Callout
                            v-if="!reviewed"
                            tone="sun"
                            :icon="TriangleAlert"
                            role="note"
                            data-test="legal-template-banner"
                        >
                            {{ t('marketing.legal.template_note') }}
                        </Callout>
                    </div>

                    <details
                        v-if="toc.length"
                        ref="mobileToc"
                        class="group mt-6 rounded-card border bg-surface-2 lg:hidden print:hidden"
                        data-test="legal-toc-mobile"
                    >
                        <summary
                            class="flex min-h-11 list-none items-center justify-between gap-2 px-4 py-2 text-sm font-semibold [&::-webkit-details-marker]:hidden"
                        >
                            <span class="inline-flex items-center gap-2">
                                <ListTree
                                    class="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                {{ t('marketing.legal.toc') }}
                            </span>
                            <ChevronDown
                                class="size-4 text-muted-foreground transition-transform group-open:rotate-180 motion-reduce:transition-none"
                                aria-hidden="true"
                            />
                        </summary>
                        <nav
                            :aria-label="t('marketing.legal.toc')"
                            class="border-t px-2 py-2 text-sm"
                        >
                            <a
                                v-for="entry in toc"
                                :key="entry.id"
                                :href="`#${entry.id}`"
                                :class="tocClass(entry)"
                                @click="jumpTo($event, entry.id)"
                            >
                                <span
                                    v-if="entry.index"
                                    class="w-5 shrink-0 font-mono text-xs leading-5"
                                    aria-hidden="true"
                                    >{{ entry.index }}</span
                                >
                                <span class="min-w-0">{{ entry.text }}</span>
                            </a>
                        </nav>
                    </details>
                </header>

                <div
                    class="legal-prose pt-8"
                    data-test="legal-body"
                    v-html="doc.html"
                />

                <nav
                    v-if="related.length"
                    :aria-label="t('marketing.legal.related')"
                    class="mt-10 border-t pt-6 print:hidden"
                    data-test="legal-related"
                >
                    <p class="text-caption">
                        {{ t('marketing.legal.related') }}
                    </p>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        <li v-for="key in related" :key="key">
                            <Link
                                :href="endpoints.legal(key)"
                                class="inline-flex min-h-11 items-center gap-2 rounded-chip border bg-surface px-3.5 text-sm font-medium text-foreground transition-colors hover:border-primary hover:text-primary-strong"
                            >
                                <component
                                    :is="legalDocIcon(key)"
                                    class="size-4 text-primary-strong"
                                    aria-hidden="true"
                                />
                                {{ t(legalDocLabelKey(key)) }}
                            </Link>
                        </li>
                    </ul>
                </nav>
            </article>
        </div>
    </div>
</template>

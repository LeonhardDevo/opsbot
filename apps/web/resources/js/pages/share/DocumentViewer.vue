<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowRight, CalendarClock, Download, FileArchive } from '@lucide/vue';
import { onMounted } from 'vue';
import Callout from '@/components/opsbot/Callout.vue';
import ShareShell from '@/components/opsbot/ShareShell.vue';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { applyLocale, t } from '@/lib/i18n';

const props = defineProps<{
    document: {
        id: number;
        title: string;
        version: string | null;
        effective_date: string;
        departments: string[];
    };
    excerpt: {
        chunk_id: number;
        page: number | null;
        section: string | null;
        before: string | null;
        content: string;
        after: string | null;
    } | null;
    successor: { title: string; url: string } | null;
    state: 'ok' | 'archived';
    downloadUrl: string;
    locale: 'ru' | 'en';
    workspaceName: string;
}>();

const fmt = useFormat();

onMounted(() => {
    void applyLocale(props.locale);
});
</script>

<template>
    <Head :title="document.title">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <ShareShell :caption="workspaceName">
        <article class="flex flex-col gap-5">
            <header class="space-y-3">
                <h1 class="text-h1 hyphens-auto" data-test="viewer-title">
                    {{ document.title }}
                </h1>
                <div class="flex flex-wrap gap-1.5 text-xs">
                    <span
                        v-if="document.version"
                        class="inline-flex h-6 items-center rounded-chip bg-surface-2 px-2 font-mono font-medium"
                        data-test="viewer-version"
                        >{{ document.version }}</span
                    >
                    <span
                        v-if="document.effective_date"
                        class="inline-flex h-6 items-center gap-1 rounded-chip bg-primary-soft px-2 font-semibold text-primary-strong"
                    >
                        <CalendarClock class="size-3.5" aria-hidden="true" />
                        {{
                            t('ui.viewer.effective', {
                                date: fmt.date(
                                    `${document.effective_date}T12:00:00`,
                                ),
                            })
                        }}
                    </span>
                    <span
                        v-for="department in document.departments.length
                            ? document.departments
                            : [t('ui.common.all_departments')]"
                        :key="department"
                        class="inline-flex h-6 items-center rounded-chip bg-surface-2 px-2 font-medium"
                        >{{ department }}</span
                    >
                </div>
            </header>

            <Callout
                v-if="state === 'archived'"
                tone="sun"
                :icon="FileArchive"
                :title="t('ui.viewer.archived_title')"
                data-test="viewer-successor"
            >
                {{
                    successor
                        ? t('ui.viewer.archived_body')
                        : t('ui.viewer.archived_no_successor')
                }}
                <template v-if="successor" #actions>
                    <Button size="sm" as-child>
                        <a :href="successor.url">
                            {{ successor.title }}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </a>
                    </Button>
                </template>
            </Callout>

            <section
                v-if="excerpt"
                class="space-y-3 rounded-card border bg-surface p-4 shadow-clay sm:p-5"
                data-test="viewer-excerpt"
            >
                <p class="text-caption">
                    <template v-if="excerpt.section">{{
                        excerpt.section
                    }}</template>
                    <template v-if="excerpt.section && excerpt.page">
                        ·
                    </template>
                    <template v-if="excerpt.page">{{
                        t('ui.viewer.page', { page: excerpt.page })
                    }}</template>
                </p>
                <p
                    v-if="excerpt.before"
                    class="text-[1.0625rem] leading-[1.65] whitespace-pre-line text-muted-foreground"
                >
                    {{ excerpt.before }}
                </p>
                <p class="text-[1.0625rem] leading-[1.65] whitespace-pre-line">
                    <mark
                        class="rounded-md border-l-2 border-sun-strong bg-mark [box-decoration-break:clone] px-1.5 py-0.5 text-foreground"
                        data-test="viewer-mark"
                        ><span class="sr-only"
                            >{{ t('ui.viewer.quote_prefix') }} </span
                        >{{ excerpt.content }}</mark
                    >
                </p>
                <p
                    v-if="excerpt.after"
                    class="text-[1.0625rem] leading-[1.65] whitespace-pre-line text-muted-foreground"
                >
                    {{ excerpt.after }}
                </p>
            </section>
            <p
                v-else-if="state === 'ok'"
                class="rounded-card bg-surface-2 p-4 text-sm text-muted-foreground"
            >
                {{ t('ui.viewer.no_excerpt') }}
            </p>

            <Button
                v-if="state === 'ok'"
                variant="secondary"
                as-child
                class="viewer-print-hide self-start"
            >
                <a :href="downloadUrl" data-test="viewer-download">
                    <Download class="size-4" aria-hidden="true" />
                    {{ t('ui.viewer.download') }}
                </a>
            </Button>

            <footer
                class="border-t pt-4 text-xs text-pretty text-muted-foreground"
            >
                {{ t('ui.viewer.footer') }}
                <a
                    :href="endpoints.legal('employee-notice').url"
                    class="font-semibold text-primary-strong underline-offset-4 hover:underline"
                    >{{ t('ui.viewer.notice_link') }}</a
                >
            </footer>
        </article>
    </ShareShell>
</template>

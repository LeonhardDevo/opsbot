<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { BookOpenText, ExternalLink } from '@lucide/vue';
import { computed } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type { Citation } from '@/types/opsbot';

const props = defineProps<{ citation: Citation; index: number }>();

const open = defineModel<boolean>('open', { default: false });

const label = computed(() =>
    props.citation.page
        ? t('ui.assistant.citation_label_page', {
              n: props.index,
              title: props.citation.title,
              page: props.citation.page,
          })
        : t('ui.assistant.citation_label', {
              n: props.index,
              title: props.citation.title,
          }),
);

const href = computed(
    () =>
        props.citation.url ??
        endpoints.knowledge.show(props.citation.document_id).url,
);

const internal = computed(() => href.value.startsWith('/'));
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inline-flex h-7 max-w-[280px] items-center gap-1.5 rounded-chip border bg-surface-2 py-1 pr-2.5 pl-1 text-[0.8125rem] font-medium [overflow-wrap:anywhere] text-primary-strong transition-colors hover:border-primary data-[state=open]:border-primary pointer-coarse:h-9"
                :data-cite-chip="index"
                :aria-label="label"
                :title="citation.title"
                data-test="assistant-citation"
            >
                <span
                    class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-primary font-mono text-xs font-semibold text-primary-foreground"
                    aria-hidden="true"
                    >{{ index }}</span
                >
                <span class="truncate">{{ citation.title }}</span>
                <span v-if="citation.page" class="shrink-0" aria-hidden="true">
                    ·
                    {{ t('ui.assistant.page_short', { page: citation.page }) }}
                </span>
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="w-[min(22rem,calc(100vw-2rem))] p-0"
        >
            <div class="flex items-start gap-3 border-b p-4">
                <span
                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-strong"
                    aria-hidden="true"
                >
                    <BookOpenText class="size-4" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm leading-snug font-semibold">
                        {{ citation.title }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <span v-if="citation.version">{{
                            t('ui.assistant.version', {
                                version: citation.version,
                            })
                        }}</span>
                        <span v-if="citation.version && citation.page">
                            ·
                        </span>
                        <span v-if="citation.page">{{
                            t('ui.assistant.page', { page: citation.page })
                        }}</span>
                    </p>
                </div>
            </div>
            <blockquote
                v-if="citation.snippet"
                class="max-h-48 overflow-y-auto border-l-2 border-primary/50 bg-surface-2 px-4 py-3 text-sm leading-relaxed"
            >
                <mark class="rounded bg-mark px-0.5 text-foreground">{{
                    citation.snippet
                }}</mark>
            </blockquote>
            <div class="flex justify-end p-2">
                <Link
                    v-if="internal"
                    :href="href"
                    class="inline-flex h-11 items-center gap-1.5 rounded-control px-3 text-sm font-semibold text-primary hover:bg-primary-soft"
                >
                    {{ t('ui.assistant.open_document') }}
                    <ExternalLink class="size-4" aria-hidden="true" />
                </Link>
                <a
                    v-else
                    :href="href"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex h-11 items-center gap-1.5 rounded-control px-3 text-sm font-semibold text-primary hover:bg-primary-soft"
                >
                    {{ t('ui.assistant.open_document') }}
                    <ExternalLink class="size-4" aria-hidden="true" />
                </a>
            </div>
        </PopoverContent>
    </Popover>
</template>

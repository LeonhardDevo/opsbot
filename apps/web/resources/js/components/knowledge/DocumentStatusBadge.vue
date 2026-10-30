<script setup lang="ts">
import { computed } from 'vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import { t } from '@/lib/i18n';
import { processingIcon } from '@/lib/opsbot-icons';
import type { KbDocument } from '@/types/opsbot';

const props = defineProps<{
    document: Pick<
        KbDocument,
        | 'status'
        | 'pages_done'
        | 'pages_total'
        | 'ocr_used'
        | 'error'
        | 'error_code'
    >;
}>();

const progress = computed(() => {
    const total = props.document.pages_total ?? 0;
    const done = props.document.pages_done ?? 0;

    return total > 0 ? Math.min(100, Math.round((done / total) * 100)) : null;
});

const label = computed(() => {
    if (props.document.status === 'processing' && props.document.pages_total) {
        return t(
            props.document.ocr_used
                ? 'ui.knowledge.progress_ocr'
                : 'ui.knowledge.progress_pages',
            {
                done: props.document.pages_done ?? 0,
                total: props.document.pages_total,
            },
        );
    }

    return t(`documents.status.${props.document.status}`);
});

const tone = computed(() => {
    switch (props.document.status) {
        case 'processing':
            return 'primary';
        case 'failed':
            return 'coral';
        case 'indexed':
            return 'success';
        default:
            return 'neutral';
    }
});

const errorText = computed(() =>
    props.document.error_code
        ? t(`documents.errors.${props.document.error_code}`)
        : props.document.error,
);
</script>

<template>
    <span
        class="inline-flex flex-col gap-1"
        data-test="kb-status"
        :data-status="document.status"
        :title="
            document.status === 'failed' ? (errorText ?? undefined) : undefined
        "
    >
        <OpsBadge
            :label="label"
            :tone="tone"
            :icon="processingIcon[document.status]"
        />
        <span
            v-if="document.status === 'processing' && progress !== null"
            class="h-1 w-24 overflow-hidden rounded-chip bg-neutral-soft"
            role="progressbar"
            :aria-valuenow="progress"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="label"
        >
            <span
                class="block h-full rounded-chip bg-primary"
                :style="{ width: `${progress}%` }"
            />
        </span>
    </span>
</template>

<script setup lang="ts">
import { Inbox, RefreshCw } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import AnswerDialog from '@/components/opsbot/AnswerDialog.vue';
import DeskItemRow from '@/components/opsbot/DeskItemRow.vue';
import { Button } from '@/components/ui/button';
import { useDeskActions } from '@/composables/useDeskActions';
import { deskSuppressed, useDeskFeed } from '@/composables/useDeskFeed';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { t, tc } from '@/lib/i18n';
import { deskTarget } from '@/lib/opsbot';

const props = withDefaults(defineProps<{ active?: boolean }>(), {
    active: true,
});

const { currentUrl } = useCurrentUrl();
const feed = useDeskFeed(() => props.active);
const suppressed = deskSuppressed();
const scroller = ref<HTMLElement | null>(null);

const { pending, answering, run } = useDeskActions((item) => {
    feed.remove(item.id);
});

const items = computed(() =>
    feed.items.value.filter((item) => {
        if (suppressed.value.has(item.id)) {
            return false;
        }

        if (
            item.kind === 'incident' &&
            currentUrl.value.startsWith('/incidents')
        ) {
            return false;
        }

        if (
            item.kind === 'question' &&
            currentUrl.value.startsWith('/unanswered')
        ) {
            return false;
        }

        return !(
            item.kind === 'task_overdue' &&
            currentUrl.value.startsWith('/tasks')
        );
    }),
);

const answerOpen = computed({
    get: () => answering.value !== null,
    set: (value: boolean) => {
        if (!value) {
            answering.value = null;
        }
    },
});

watch(
    () => items.value.length,
    async () => {
        await nextTick();
        scroller.value?.scrollTo({ top: scroller.value.scrollHeight });
    },
);
</script>

<template>
    <section
        class="flex h-full min-h-0 flex-col"
        :aria-label="t('ui.desk.title')"
        data-test="shell-desk"
    >
        <header class="flex items-center gap-2 px-4 pt-4 pb-2">
            <Inbox class="size-5 text-primary-strong" aria-hidden="true" />
            <h2 class="text-h2">{{ t('ui.desk.title') }}</h2>
            <span
                v-if="items.length"
                class="ml-auto rounded-chip bg-primary-soft px-2 py-0.5 font-mono text-xs font-semibold text-primary-strong"
                >{{ items.length }}</span
            >
        </header>
        <p class="sr-only" aria-live="polite">
            {{
                feed.fresh.value
                    ? tc('ui.desk.new_items', feed.fresh.value)
                    : ''
            }}
        </p>
        <div
            ref="scroller"
            class="min-h-0 flex-1 scrollbar-thin space-y-3 overflow-y-auto px-4 pb-4"
        >
            <template v-if="feed.loading.value">
                <div v-for="index in 4" :key="index" class="flex gap-3">
                    <Skeleton class="size-8 shrink-0 rounded-2xl" />
                    <Skeleton class="h-20 flex-1 rounded-card" />
                </div>
            </template>
            <div
                v-else-if="feed.failed.value && !items.length"
                class="space-y-2 rounded-card bg-surface-2 p-4 text-sm"
            >
                <p>{{ t('ui.desk.error') }}</p>
                <Button size="sm" variant="secondary" @click="feed.refresh()">
                    <RefreshCw class="size-4" aria-hidden="true" />
                    {{ t('ui.common.retry') }}
                </Button>
            </div>
            <p
                v-else-if="!items.length"
                class="rounded-card bg-surface-2 p-4 text-center text-sm text-muted-foreground"
            >
                {{ t('ui.desk.empty') }}
            </p>
            <DeskItemRow
                v-for="item in items"
                v-else
                :key="item.id"
                :item="item"
                variant="bubble"
                :pending="pending"
                item-test="shell-desk-item"
                action-test="shell-desk-action"
                @action="run"
            />
        </div>
        <AnswerDialog
            v-model:open="answerOpen"
            :question-id="
                answering ? (deskTarget(answering)?.id ?? null) : null
            "
            :title="answering?.title ?? ''"
            :question="answering?.excerpt ?? null"
            @answered="answering && feed.remove(answering.id)"
        />
    </section>
</template>

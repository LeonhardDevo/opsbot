<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CheckCheck,
    Ellipsis,
    ExternalLink,
    Hand,
    MessageSquareReply,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import SeverityBadge from '@/components/chat/SeverityBadge.vue';
import RelativeTime from '@/components/common/RelativeTime.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { deskKindIcon } from '@/lib/opsbot-icons';
import { cn } from '@/lib/utils';
import type { DeskAction, DeskItem } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        item: DeskItem;
        variant?: 'feed' | 'bubble';
        pending?: string | null;
        itemTest?: string;
        actionTest?: string;
        inline?: number;
    }>(),
    {
        variant: 'feed',
        pending: null,
        itemTest: 'dashboard-feed-item',
        actionTest: 'dashboard-feed-action',
        inline: 2,
    },
);

const emit = defineEmits<{ action: [item: DeskItem, action: DeskAction] }>();

const actionIcons: Record<DeskAction, Component> = {
    take: Hand,
    answer: MessageSquareReply,
    done: CheckCheck,
    open: ExternalLink,
};

const tileTone = computed(() => {
    if (props.item.kind === 'incident') {
        return props.item.severity === 'high'
            ? 'bg-coral-soft text-coral-strong'
            : 'bg-primary-soft text-primary-strong';
    }

    if (props.item.kind === 'task_overdue') {
        return 'bg-coral-soft text-coral-strong';
    }

    if (props.item.kind === 'question') {
        return 'bg-sun-soft text-sun-strong';
    }

    return 'bg-neutral-soft text-neutral-foreground';
});

const primary = computed(() =>
    props.item.actions.filter((action) => action !== 'open'),
);

const visible = computed(() => {
    const list: DeskAction[] = [...primary.value, 'open'];

    return list.slice(0, props.inline);
});

const overflow = computed(() =>
    [...primary.value, 'open' as DeskAction].slice(props.inline),
);

const high = computed(
    () => props.item.kind === 'incident' && props.item.severity === 'high',
);

function actionLabel(action: DeskAction): string {
    return t(`ui.desk.actions.${action}_named`, { title: props.item.title });
}
</script>

<template>
    <article
        :aria-label="item.title"
        :data-test="itemTest"
        :data-kind="item.kind"
        :data-id="item.id"
        :class="
            cn(
                'relative flex gap-3 border bg-surface',
                variant === 'feed'
                    ? 'rounded-card p-3.5 shadow-clay-sm sm:p-4'
                    : 'bubble-tail-start rounded-card p-3 shadow-clay-sm',
                high ? 'border-l-[3px] border-l-coral-strong' : '',
            )
        "
    >
        <span
            :class="
                cn(
                    'inline-flex shrink-0 items-center justify-center rounded-2xl',
                    variant === 'feed' ? 'size-10' : 'size-8',
                    tileTone,
                )
            "
            aria-hidden="true"
        >
            <component
                :is="deskKindIcon[item.kind]"
                :class="variant === 'feed' ? 'size-5' : 'size-4'"
            />
        </span>
        <div class="min-w-0 flex-1 space-y-1">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <Link
                    :href="item.url"
                    class="min-w-0 truncate font-semibold hover:underline"
                    :class="variant === 'feed' ? 'text-[0.9375rem]' : 'text-sm'"
                    >{{ item.title }}</Link
                >
                <SeverityBadge
                    v-if="item.kind === 'incident' && item.severity"
                    :severity="item.severity"
                />
            </div>
            <p
                v-if="item.excerpt"
                class="line-clamp-2 text-sm text-muted-foreground"
            >
                {{ item.excerpt }}
            </p>
            <p
                class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
            >
                <span v-if="item.actor">{{ item.actor.name }}</span>
                <RelativeTime :value="item.at" />
            </p>
            <div
                v-if="variant === 'bubble'"
                class="flex flex-wrap gap-1.5 pt-1"
            >
                <Button
                    v-for="action in item.actions"
                    :key="action"
                    size="sm"
                    :variant="action === 'open' ? 'ghost' : 'secondary'"
                    :aria-label="actionLabel(action)"
                    :disabled="pending === `${item.id}:${action}`"
                    :data-test="actionTest"
                    :data-action="action"
                    @click="emit('action', item, action)"
                >
                    <Spinner v-if="pending === `${item.id}:${action}`" />
                    <component
                        :is="actionIcons[action]"
                        v-else
                        class="size-4"
                        aria-hidden="true"
                    />
                    {{ t(`ui.desk.actions.${action}`) }}
                </Button>
            </div>
        </div>
        <div
            v-if="variant === 'feed'"
            class="flex shrink-0 items-start gap-1.5"
        >
            <Button
                v-for="action in visible"
                :key="action"
                size="sm"
                :variant="action === 'open' ? 'ghost' : 'secondary'"
                :aria-label="actionLabel(action)"
                :disabled="pending === `${item.id}:${action}`"
                :data-test="actionTest"
                :data-action="action"
                :class="action === 'open' ? 'max-sm:hidden' : ''"
                @click="emit('action', item, action)"
            >
                <Spinner v-if="pending === `${item.id}:${action}`" />
                <component
                    :is="actionIcons[action]"
                    v-else
                    class="size-4"
                    aria-hidden="true"
                />
                <span class="max-sm:sr-only">{{
                    t(`ui.desk.actions.${action}`)
                }}</span>
            </Button>
            <DropdownMenu v-if="overflow.length">
                <DropdownMenuTrigger as-child>
                    <Button
                        size="icon-sm"
                        variant="ghost"
                        :aria-label="t('ui.common.more')"
                    >
                        <Ellipsis class="size-4" aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        v-for="action in overflow"
                        :key="action"
                        :data-test="actionTest"
                        :data-action="action"
                        @select="emit('action', item, action)"
                    >
                        <component
                            :is="actionIcons[action]"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ t(`ui.desk.actions.${action}`) }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </article>
</template>

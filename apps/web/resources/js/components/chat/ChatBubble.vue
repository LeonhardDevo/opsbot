<script setup lang="ts">
import { Bot, UserCheck } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type BubbleVariant = 'employee' | 'bot' | 'manager' | 'system';

const props = withDefaults(
    defineProps<{
        variant: BubbleVariant;
        time?: string | null;
        refused?: boolean;
        author?: string | null;
        signature?: string | null;
        dense?: boolean;
        class?: string;
    }>(),
    {
        time: null,
        refused: false,
        author: null,
        signature: null,
        dense: false,
        class: '',
    },
);

const align = computed(() =>
    props.variant === 'employee'
        ? 'items-end'
        : props.variant === 'system'
          ? 'items-center'
          : 'items-start',
);

const surface = computed(() => {
    switch (props.variant) {
        case 'employee':
            return 'bubble-tail-end bg-primary-soft text-foreground';
        case 'manager':
            return 'bubble-tail-start bg-sun-soft text-foreground';
        case 'bot':
            return cn(
                'bubble-tail-start border bg-surface text-foreground shadow-clay-sm',
                props.refused ? 'border-l-[3px] border-l-coral-strong' : '',
            );
        default:
            return '';
    }
});
</script>

<template>
    <div
        :class="cn('flex w-full flex-col gap-1', align, props.class)"
        :data-bubble="variant"
    >
        <p
            v-if="variant === 'system'"
            class="rounded-chip bg-surface-2 px-3 py-1 text-center text-[0.8125rem] font-medium text-muted-foreground"
        >
            <slot />
        </p>
        <div
            v-else
            :class="
                cn(
                    'max-w-[85%] rounded-card sm:max-w-[640px]',
                    dense
                        ? 'px-3 py-2 text-sm leading-snug'
                        : 'px-4 py-3 text-[0.9375rem] leading-[1.55]',
                    surface,
                )
            "
        >
            <p
                v-if="variant === 'bot'"
                class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"
            >
                <Bot class="size-3.5" aria-hidden="true" />
                {{ author ?? t('ui.chat.bot_name') }}
            </p>
            <p
                v-else-if="variant === 'manager'"
                class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-sun-strong"
            >
                <UserCheck class="size-3.5" aria-hidden="true" />
                {{ t('ui.chat.manager_label') }}
            </p>
            <p
                v-else-if="author"
                class="mb-1 text-xs font-semibold text-primary-strong"
            >
                {{ author }}
            </p>
            <slot />
            <p
                v-if="variant === 'manager' && signature"
                class="mt-1.5 text-[0.8125rem] text-muted-foreground"
            >
                — {{ signature }}
            </p>
            <div v-if="$slots.footer" class="mt-2.5 space-y-2.5">
                <slot name="footer" />
            </div>
        </div>
        <span
            v-if="time && variant !== 'system'"
            class="px-1 font-mono text-[0.6875rem] text-muted-foreground"
            >{{ time }}</span
        >
    </div>
</template>

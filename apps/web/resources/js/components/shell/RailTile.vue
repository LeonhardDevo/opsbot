<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { t } from '@/lib/i18n';
import { isNavItemActive } from '@/lib/navigation';
import { cn, toUrl } from '@/lib/utils';
import type { NavItem } from '@/types/navigation';

const props = withDefaults(
    defineProps<{ item: NavItem; variant?: 'rail' | 'tab' }>(),
    { variant: 'rail' },
);

const { currentUrl } = useCurrentUrl();

const active = computed(() => isNavItemActive(props.item, currentUrl.value));

const navKey = computed(
    () =>
        toUrl(props.item.href).replace(/^\/+/, '').replace(/\//g, '-') ||
        'home',
);
</script>

<template>
    <Link
        :href="item.href"
        prefetch
        :aria-current="active ? 'page' : undefined"
        :title="t(item.title)"
        :data-test="
            variant === 'rail' ? 'shell-rail-item' : 'shell-tabbar-item'
        "
        :data-nav="navKey"
        :class="
            cn(
                'group relative flex flex-col items-center gap-1 rounded-2xl outline-none',
                variant === 'rail'
                    ? 'w-[70px] py-1 short-screen:py-0'
                    : 'min-h-14 min-w-0 flex-1 justify-center py-1.5',
            )
        "
    >
        <span
            :class="
                cn(
                    'press relative inline-flex items-center justify-center rounded-2xl transition-colors',
                    variant === 'rail' ? 'size-12' : 'h-8 w-14',
                    active
                        ? 'bg-primary text-primary-foreground shadow-clay-sm'
                        : 'text-muted-foreground group-hover:bg-surface group-hover:text-foreground group-hover:shadow-clay-sm',
                )
            "
        >
            <component
                :is="item.icon"
                :class="variant === 'rail' ? 'size-[1.375rem]' : 'size-5'"
                :stroke-width="1.75"
                aria-hidden="true"
            />
            <span
                v-if="item.badge"
                class="absolute -top-1 -right-1 inline-flex h-5 min-w-5 items-center justify-center rounded-chip bg-coral px-1 font-mono text-[0.6875rem] font-semibold text-coral-foreground ring-2 ring-surface-2"
                >{{ item.badge }}</span
            >
        </span>
        <span
            :class="
                cn(
                    'max-w-full truncate text-[0.6875rem] leading-tight tracking-[-0.01em]',
                    variant === 'rail' ? 'short-screen:sr-only' : '',
                    active
                        ? 'font-semibold text-foreground'
                        : 'font-medium text-muted-foreground group-hover:text-foreground',
                )
            "
            >{{ t(item.short ?? item.title)
            }}<span
                v-if="item.short && t(item.short) !== t(item.title)"
                class="sr-only"
            >
                · {{ t(item.title) }}</span
            ></span
        >
    </Link>
</template>

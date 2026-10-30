<script setup lang="ts">
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import RailTile from '@/components/shell/RailTile.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import UserMenu from '@/components/shell/UserMenu.vue';
import WorkspaceMenu from '@/components/shell/WorkspaceMenu.vue';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types/navigation';

defineProps<{ items: NavItem[] }>();
</script>

<template>
    <aside
        class="fixed inset-y-0 left-0 z-30 hidden w-[72px] flex-col items-center bg-surface-2 py-3 md:flex"
        data-test="shell-rail"
    >
        <WorkspaceMenu />
        <nav
            :aria-label="t('ui.nav.main_navigation')"
            class="mt-4 flex min-h-0 w-full flex-1 scrollbar-none flex-col items-center gap-1.5 overflow-y-auto px-1 py-1 short-screen:mt-2 short-screen:gap-1"
        >
            <RailTile
                v-for="item in items"
                :key="toUrl(item.href)"
                :item="item"
            />
        </nav>
        <div class="flex flex-col items-center gap-1 pt-2">
            <ThemeToggle class="rounded-2xl" />
            <LocaleSwitcher compact class="rounded-2xl" />
            <UserMenu />
        </div>
    </aside>
</template>

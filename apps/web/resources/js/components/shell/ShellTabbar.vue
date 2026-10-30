<script setup lang="ts">
import { Ellipsis } from '@lucide/vue';
import { ref } from 'vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import RailTile from '@/components/shell/RailTile.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import UserMenu from '@/components/shell/UserMenu.vue';
import WorkspaceMenu from '@/components/shell/WorkspaceMenu.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { t } from '@/lib/i18n';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types/navigation';

defineProps<{ tabs: NavItem[]; more: NavItem[] }>();

const open = ref(false);
</script>

<template>
    <nav
        :aria-label="t('ui.nav.main_navigation')"
        class="fixed inset-x-0 bottom-0 z-30 flex h-16 items-stretch gap-1 border-t bg-surface/95 px-2 pb-[env(safe-area-inset-bottom)] backdrop-blur-md md:hidden"
        data-test="shell-tabbar"
    >
        <RailTile
            v-for="item in tabs"
            :key="toUrl(item.href)"
            :item="item"
            variant="tab"
        />
        <button
            type="button"
            class="group flex min-h-14 flex-1 flex-col items-center justify-center gap-1 rounded-2xl py-1.5"
            :aria-label="t('ui.nav.more')"
            :aria-expanded="open"
            data-test="shell-tabbar-item"
            data-nav="more"
            @click="open = true"
        >
            <span
                class="inline-flex h-8 w-14 items-center justify-center rounded-2xl text-muted-foreground group-hover:bg-surface-2"
            >
                <Ellipsis class="size-5" aria-hidden="true" />
            </span>
            <span
                class="text-[0.6875rem] leading-tight font-medium text-muted-foreground"
                aria-hidden="true"
                >{{ t('ui.nav.short.more') }}</span
            >
        </button>
    </nav>
    <Sheet v-model:open="open">
        <SheetContent side="bottom" class="max-h-[85svh] gap-0 p-0">
            <SheetHeader class="px-5 pt-5 pb-2 text-left">
                <SheetTitle class="text-h2">{{ t('ui.nav.more') }}</SheetTitle>
                <SheetDescription class="sr-only">{{
                    t('ui.nav.main_navigation')
                }}</SheetDescription>
            </SheetHeader>
            <div class="grid grid-cols-4 gap-2 px-3 pb-3">
                <RailTile
                    v-for="item in more"
                    :key="toUrl(item.href)"
                    :item="item"
                    variant="tab"
                    @click="open = false"
                />
            </div>
            <div
                class="flex items-center justify-between gap-2 border-t px-5 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
            >
                <div class="flex items-center gap-2">
                    <WorkspaceMenu side="bottom" />
                    <UserMenu side="top" />
                </div>
                <div class="flex items-center gap-1">
                    <ThemeToggle :test-id="null" class="rounded-2xl" />
                    <LocaleSwitcher
                        compact
                        :test-id="null"
                        class="rounded-2xl"
                    />
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>

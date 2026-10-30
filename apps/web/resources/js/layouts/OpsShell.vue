<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import BillingAlert from '@/components/billing/BillingAlert.vue';
import CookieNotice from '@/components/legal/CookieNotice.vue';
import DeskFeed from '@/components/opsbot/DeskFeed.vue';
import UpgradeDialog from '@/components/opsbot/UpgradeDialog.vue';
import AppToaster from '@/components/shell/AppToaster.vue';
import CommandPalette from '@/components/shell/CommandPalette.vue';
import ShellRail from '@/components/shell/ShellRail.vue';
import ShellTabbar from '@/components/shell/ShellTabbar.vue';
import ShellTopbar from '@/components/shell/ShellTopbar.vue';
import SkipLink from '@/components/shell/SkipLink.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { useCan } from '@/composables/useCan';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useUpgrade } from '@/composables/useUpgrade';
import { t } from '@/lib/i18n';
import {
    activeNavItem,
    appNavigation,
    railItems,
    tabItems,
} from '@/lib/navigation';
import type { BreadcrumbItem } from '@/types';
import type { LimitKey } from '@/types/opsbot';

withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const { can } = useCan();
const { currentUrl } = useCurrentUrl();
const upgrade = useUpgrade();

const items = computed(() =>
    railItems(
        appNavigation(
            can,
            Boolean(page.props.workspace),
            page.props.badges ?? {},
        ),
    ),
);
const tabs = computed(() => tabItems(items.value));
const section = computed(() => activeNavItem(items.value, currentUrl.value));

const wide = useMediaQuery('(min-width: 1440px)');
const medium = useMediaQuery('(min-width: 1024px)');
const deskOpen = ref(false);

const deskEnabled = computed(
    () =>
        Boolean(page.props.workspace) &&
        (can('incidents.manage') ||
            can('questions.answer') ||
            can('tasks.view_own')),
);

watch(wide, (value) => {
    if (value) {
        deskOpen.value = false;
    }
});

watch(
    () => page.props.flash?.limit,
    (key) => {
        if (key) {
            upgrade.show(key as LimitKey);
        }
    },
    { immediate: true },
);
</script>

<template>
    <SkipLink />
    <div class="min-h-svh bg-surface-2" data-test="ops-shell">
        <ShellRail :items="items" />
        <div class="flex min-h-svh min-w-0 flex-col md:pl-[72px]">
            <ShellTopbar
                :section="section"
                :desk-toggle="deskEnabled && medium && !wide"
                :desk-open="deskOpen"
                @toggle-desk="deskOpen = !deskOpen"
            />
            <BillingAlert />
            <CookieNotice inline />
            <div class="flex min-h-0 min-w-0 flex-1">
                <main
                    id="main-content"
                    tabindex="-1"
                    class="relative flex min-w-0 flex-1 flex-col overflow-x-clip bg-background pb-20 focus:outline-none md:mr-2 md:mb-2 md:ml-2 md:rounded-tl-dialog md:pb-0 md:shadow-clay-sm"
                >
                    <slot />
                </main>
                <aside
                    v-if="deskEnabled && wide"
                    class="sticky top-14 h-[calc(100svh-3.5rem)] w-[360px] shrink-0 pb-2"
                >
                    <DeskFeed />
                </aside>
            </div>
        </div>
        <ShellTabbar :tabs="tabs.tabs" :more="tabs.more" />
    </div>
    <Sheet v-if="deskEnabled && !wide" v-model:open="deskOpen">
        <SheetContent side="right" class="w-[380px] max-w-[92vw] p-0">
            <SheetTitle class="sr-only">{{ t('ui.desk.title') }}</SheetTitle>
            <SheetDescription class="sr-only">{{
                t('ui.desk.description')
            }}</SheetDescription>
            <DeskFeed v-if="deskOpen" />
        </SheetContent>
    </Sheet>
    <UpgradeDialog v-if="upgrade.pageOwned.value === 0" />
    <CommandPalette />
    <AppToaster />
</template>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Inbox, MessagesSquare, Search } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AppLogoMark from '@/components/brand/AppLogoMark.vue';
import Kbd from '@/components/common/Kbd.vue';
import BotStatusPill from '@/components/shell/BotStatusPill.vue';
import NotificationsPopover from '@/components/shell/NotificationsPopover.vue';
import { Button } from '@/components/ui/button';
import { useCan } from '@/composables/useCan';
import {
    isApplePlatform,
    useCommandPalette,
} from '@/composables/useCommandPalette';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import type { NavItem } from '@/types/navigation';

const props = withDefaults(
    defineProps<{
        section: NavItem | null;
        deskToggle?: boolean;
        deskOpen?: boolean;
    }>(),
    { deskToggle: false, deskOpen: false },
);

const emit = defineEmits<{ toggleDesk: [] }>();

const page = usePage();
const { can } = useCan();
const { show } = useCommandPalette();
const apple = ref(false);

onMounted(() => {
    apple.value = isApplePlatform();
});

const companyBot = computed(() => page.props.workspace?.bot ?? null);

const botUsername = computed(
    () =>
        companyBot.value?.username ??
        page.props.product?.telegramBotUsername ??
        null,
);

const botHref = computed(() =>
    can('bot.manage')
        ? endpoints.bot.index()
        : endpoints.settings.integrations.show(),
);

const caption = computed(
    () => page.props.workspace?.name ?? page.props.auth.user?.name ?? '',
);

const sectionTitle = computed(() =>
    props.section ? t(props.section.title) : page.props.name,
);
</script>

<template>
    <header
        class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 bg-surface-2/90 px-3 backdrop-blur-md md:px-4"
        data-test="shell-topbar"
    >
        <Link
            :href="endpoints.dashboard()"
            class="inline-flex size-11 shrink-0 items-center justify-center rounded-2xl md:hidden"
            :aria-label="page.props.name"
        >
            <AppLogoMark class="size-9" />
        </Link>
        <div class="flex min-w-0 items-center gap-2.5">
            <span
                v-if="section?.icon"
                class="hidden size-9 shrink-0 items-center justify-center rounded-xl bg-surface text-primary-strong shadow-clay-sm md:inline-flex"
                aria-hidden="true"
            >
                <component :is="section.icon" class="size-[1.125rem]" />
            </span>
            <div class="grid min-w-0 leading-tight">
                <span
                    class="truncate text-[0.9375rem] font-semibold"
                    data-test="shell-section-title"
                    >{{ sectionTitle }}</span
                >
                <span
                    class="truncate text-xs text-muted-foreground"
                    data-test="shell-workspace-name"
                    >{{ caption }}</span
                >
            </div>
        </div>
        <BotStatusPill
            v-if="page.props.workspace && (companyBot || botUsername)"
            :username="botUsername"
            :state="companyBot?.status ?? null"
            :href="botHref"
            class="ml-3 hidden sm:inline-flex"
        />

        <div class="ml-auto flex items-center gap-1 sm:gap-1.5">
            <Button
                variant="outline"
                class="hidden h-10 w-60 justify-start gap-2 rounded-chip px-3.5 font-normal text-muted-foreground shadow-none lg:inline-flex"
                data-test="command-palette-trigger"
                @click="show"
            >
                <Search class="size-4 shrink-0" aria-hidden="true" />
                <span class="min-w-0 flex-1 truncate text-left text-sm">{{
                    t('ui.shell.search_placeholder')
                }}</span>
                <span
                    class="flex shrink-0 items-center gap-0.5"
                    aria-hidden="true"
                >
                    <Kbd>{{ apple ? '⌘' : 'Ctrl' }}</Kbd>
                    <Kbd>K</Kbd>
                </span>
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="rounded-2xl lg:hidden"
                :aria-label="t('ui.shell.search_placeholder')"
                @click="show"
            >
                <Search class="size-5" aria-hidden="true" />
            </Button>
            <NotificationsPopover />
            <Button
                v-if="deskToggle"
                variant="ghost"
                size="icon"
                class="rounded-2xl"
                :aria-label="t('ui.desk.toggle')"
                :aria-expanded="deskOpen"
                data-test="shell-desk-toggle"
                @click="emit('toggleDesk')"
            >
                <Inbox class="size-5" aria-hidden="true" />
            </Button>
            <Button
                v-if="page.props.workspace && can('assistant.use')"
                as-child
                size="sm"
                class="max-sm:size-11 max-sm:px-0"
            >
                <Link
                    :href="endpoints.assistant.index()"
                    :aria-label="t('ui.shell.ask_bot')"
                    data-test="shell-ask"
                >
                    <MessagesSquare
                        class="size-[1.125rem]"
                        aria-hidden="true"
                    />
                    <span class="hidden sm:inline">{{
                        t('ui.shell.ask_bot')
                    }}</span>
                </Link>
            </Button>
        </div>
    </header>
</template>

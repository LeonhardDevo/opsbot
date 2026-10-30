<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Menu } from '@lucide/vue';
import { useWindowScroll } from '@vueuse/core';
import { computed, ref } from 'vue';
import type { MarketingLink } from '@/components/marketing/types';
import AppLogo from '@/components/shell/AppLogo.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { cn, toUrl } from '@/lib/utils';
import { login, register } from '@/routes';

const props = withDefaults(
    defineProps<{ links: MarketingLink[]; canRegister?: boolean }>(),
    {
        canRegister: true,
    },
);

const page = usePage();
const { y } = useWindowScroll();
const scrolled = computed(() => y.value > 8);
const mobileOpen = ref(false);
const { isCurrentUrl } = useCurrentUrl();

const isAuthed = computed(() => Boolean(page.props.auth?.user));

function hrefOf(link: MarketingLink): string {
    return typeof link.href === 'string' ? link.href : toUrl(link.href);
}

const items = computed(() =>
    props.links.map((link) => ({
        ...link,
        url: hrefOf(link),
        active: !link.anchor && isCurrentUrl(hrefOf(link)),
    })),
);
</script>

<template>
    <header
        :class="
            cn(
                'sticky top-0 z-40 border-b backdrop-blur-md transition-[background-color,border-color,box-shadow] duration-200',
                scrolled
                    ? 'border-border bg-background/85 shadow-clay-sm'
                    : 'border-transparent bg-background/60',
            )
        "
        data-test="marketing-header"
    >
        <div
            class="mx-auto flex h-16 max-w-[1200px] items-center gap-3 px-4 sm:px-6"
        >
            <Link
                :href="endpoints.home()"
                class="shrink-0 rounded-xl"
                :aria-label="t('marketing.nav.home')"
            >
                <AppLogo />
            </Link>

            <nav
                :aria-label="t('marketing.nav.label')"
                class="mx-auto hidden min-w-0 items-center gap-1 lg:flex"
            >
                <component
                    :is="item.anchor ? 'a' : Link"
                    v-for="item in items"
                    :key="item.label"
                    :href="item.url"
                    :aria-current="item.active ? 'page' : undefined"
                    :class="
                        cn(
                            'inline-flex h-10 items-center rounded-chip px-4 text-[0.9375rem] font-medium whitespace-nowrap transition-colors',
                            item.active
                                ? 'bg-primary-soft text-primary-strong'
                                : 'text-muted-foreground hover:bg-surface-2 hover:text-foreground',
                        )
                    "
                >
                    {{ t(item.label) }}
                </component>
            </nav>

            <div class="ml-auto flex items-center gap-1 lg:ml-0">
                <span class="contents" data-test="landing-locale-switch">
                    <LocaleSwitcher class="rounded-2xl" />
                </span>
                <span class="contents" data-test="landing-theme-toggle">
                    <ThemeToggle class="rounded-2xl" />
                </span>
                <template v-if="isAuthed">
                    <Button
                        as-child
                        size="sm"
                        class="ml-1 hidden sm:inline-flex"
                    >
                        <Link :href="endpoints.dashboard()">
                            {{ t('marketing.nav.dashboard') }}
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </template>
                <template v-else>
                    <Button
                        as-child
                        variant="ghost"
                        size="sm"
                        class="hidden sm:inline-flex"
                    >
                        <Link :href="login()">{{
                            t('marketing.nav.login')
                        }}</Link>
                    </Button>
                    <Button
                        v-if="canRegister"
                        as-child
                        size="sm"
                        class="ml-1 hidden sm:inline-flex"
                    >
                        <Link :href="register()" data-test="nav-get-started">
                            {{ t('marketing.nav.get_started') }}
                        </Link>
                    </Button>
                </template>

                <Sheet v-model:open="mobileOpen">
                    <SheetTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="rounded-2xl lg:hidden"
                            :aria-label="t('marketing.nav.open_menu')"
                        >
                            <Menu class="size-5" aria-hidden="true" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="w-[min(22rem,88vw)]">
                        <SheetHeader>
                            <SheetTitle><AppLogo /></SheetTitle>
                            <SheetDescription class="sr-only">{{
                                t('marketing.nav.menu_title')
                            }}</SheetDescription>
                        </SheetHeader>
                        <nav
                            :aria-label="t('marketing.nav.label')"
                            class="flex flex-col gap-1 px-3"
                        >
                            <SheetClose
                                v-for="item in items"
                                :key="item.label"
                                as-child
                            >
                                <component
                                    :is="item.anchor ? 'a' : Link"
                                    :href="item.url"
                                    class="flex min-h-12 items-center rounded-control px-3 text-base font-medium hover:bg-surface-2"
                                >
                                    {{ t(item.label) }}
                                </component>
                            </SheetClose>
                        </nav>
                        <div class="mt-auto flex flex-col gap-2 p-4">
                            <template v-if="isAuthed">
                                <Button as-child>
                                    <Link :href="endpoints.dashboard()">{{
                                        t('marketing.nav.dashboard')
                                    }}</Link>
                                </Button>
                            </template>
                            <template v-else>
                                <Button as-child variant="outline">
                                    <Link :href="login()">{{
                                        t('marketing.nav.login')
                                    }}</Link>
                                </Button>
                                <Button v-if="canRegister" as-child>
                                    <Link :href="register()">{{
                                        t('marketing.nav.get_started')
                                    }}</Link>
                                </Button>
                            </template>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>
        </div>
    </header>
</template>

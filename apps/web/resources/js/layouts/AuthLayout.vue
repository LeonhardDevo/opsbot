<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AuthShowcase from '@/components/brand/AuthShowcase.vue';
import AppLogo from '@/components/shell/AppLogo.vue';
import CookieNotice from '@/components/legal/CookieNotice.vue';
import AppToaster from '@/components/shell/AppToaster.vue';
import LocaleSwitcher from '@/components/shell/LocaleSwitcher.vue';
import SkipLink from '@/components/shell/SkipLink.vue';
import ThemeToggle from '@/components/shell/ThemeToggle.vue';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ title?: string; description?: string }>(), {
    title: '',
    description: '',
});
</script>

<template>
    <SkipLink />
    <div
        class="grid min-h-svh grid-cols-1 bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]"
    >
        <div class="flex min-h-svh flex-col px-4 py-4 sm:px-10 sm:py-6">
            <header class="flex items-center justify-between gap-3">
                <Link
                    :href="endpoints.home()"
                    class="rounded-xl"
                    :aria-label="t('marketing.nav.home')"
                >
                    <AppLogo />
                </Link>
                <div class="flex items-center gap-1">
                    <LocaleSwitcher class="rounded-2xl" />
                    <ThemeToggle class="rounded-2xl" />
                </div>
            </header>

            <main
                id="main-content"
                tabindex="-1"
                class="mx-auto flex w-full max-w-[420px] flex-1 flex-col justify-center py-10 focus:outline-none"
            >
                <div
                    class="rounded-dialog border bg-surface p-6 shadow-clay sm:p-8"
                    data-test="auth-card"
                >
                    <div class="mb-7 space-y-2">
                        <h1 v-if="title" class="text-display-m">
                            {{ t(title) }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-[0.9375rem] text-muted-foreground"
                        >
                            {{ t(description) }}
                        </p>
                    </div>
                    <slot />
                </div>
            </main>

            <footer
                class="flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-[0.8125rem] text-muted-foreground"
            >
                <Link
                    :href="endpoints.legal('terms')"
                    class="inline-flex min-h-11 items-center hover:text-foreground hover:underline"
                >
                    {{ t('ui.legal.terms') }}
                </Link>
                <Link
                    :href="endpoints.legal('privacy')"
                    class="inline-flex min-h-11 items-center hover:text-foreground hover:underline"
                >
                    {{ t('ui.legal.privacy') }}
                </Link>
                <Link
                    :href="endpoints.legal('cookies')"
                    class="hover:text-foreground hover:underline"
                >
                    {{ t('ui.legal.docs.cookies') }}
                </Link>
                <Link
                    :href="endpoints.security()"
                    class="inline-flex min-h-11 items-center hover:text-foreground hover:underline"
                >
                    {{ t('ui.legal.security') }}
                </Link>
            </footer>
        </div>
        <aside class="hidden p-3 lg:block">
            <AuthShowcase />
        </aside>
    </div>
    <AppToaster />
    <CookieNotice />
</template>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Settings } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import PageHeader from '@/components/common/PageHeader.vue';
import { useCan } from '@/composables/useCan';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { t } from '@/lib/i18n';
import { settingsNavigation } from '@/lib/navigation';
import { cn, toUrl } from '@/lib/utils';

const page = usePage();
const { can } = useCan();
const { isCurrentOrParentUrl } = useCurrentUrl();

const groups = computed(() =>
    settingsNavigation(can, Boolean(page.props.workspace)),
);
const flat = computed(() => groups.value.flatMap((group) => group.items));

const pills = ref<HTMLElement | null>(null);

function revealActivePill(): void {
    const nav = pills.value;
    const active = nav?.querySelector<HTMLElement>('[aria-current="page"]');

    if (!nav || !active || nav.scrollWidth <= nav.clientWidth) {
        return;
    }

    const navBox = nav.getBoundingClientRect();
    const pillBox = active.getBoundingClientRect();

    nav.scrollLeft +=
        pillBox.left - navBox.left - (navBox.width - pillBox.width) / 2;
}

onMounted(revealActivePill);
watch(
    () => page.url,
    () => nextTick(revealActivePill),
);
</script>

<template>
    <div
        class="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:py-8"
        data-test="settings-layout"
    >
        <PageHeader
            :icon="Settings"
            :title="t('ui.settings.title')"
            :description="t('ui.settings.description')"
        />

        <nav
            ref="pills"
            :aria-label="t('ui.settings.title')"
            class="mask-fade-x -mx-4 mt-6 flex scrollbar-none gap-1.5 overflow-x-auto px-4 pb-2 xl:hidden"
        >
            <Link
                v-for="item in flat"
                :key="toUrl(item.href)"
                :href="item.href"
                :aria-current="
                    isCurrentOrParentUrl(item.href) ? 'page' : undefined
                "
                :class="
                    cn(
                        'inline-flex h-11 shrink-0 items-center gap-1.5 rounded-chip border px-4 text-sm font-medium transition-colors',
                        isCurrentOrParentUrl(item.href)
                            ? 'border-transparent bg-primary text-primary-foreground shadow-clay-sm'
                            : 'bg-surface text-muted-foreground hover:text-foreground',
                    )
                "
            >
                <component :is="item.icon" class="size-4" aria-hidden="true" />
                {{ t(item.title) }}
            </Link>
        </nav>

        <div class="mt-4 flex flex-col gap-8 xl:mt-8 xl:flex-row xl:gap-10">
            <aside class="hidden w-60 shrink-0 xl:block">
                <nav
                    :aria-label="t('ui.settings.title')"
                    class="sticky top-20 space-y-5 rounded-card border bg-surface p-2.5 shadow-clay"
                >
                    <div
                        v-for="group in groups"
                        :key="group.label"
                        class="space-y-1"
                    >
                        <p class="text-caption px-3 pt-1.5 pb-1">
                            {{ t(group.label) }}
                        </p>
                        <Link
                            v-for="item in group.items"
                            :key="toUrl(item.href)"
                            :href="item.href"
                            :aria-current="
                                isCurrentOrParentUrl(item.href)
                                    ? 'page'
                                    : undefined
                            "
                            :class="
                                cn(
                                    'flex h-11 items-center gap-3 rounded-control px-2 text-[0.9375rem] font-medium transition-colors',
                                    isCurrentOrParentUrl(item.href)
                                        ? 'bg-primary-soft text-primary-strong'
                                        : 'text-foreground hover:bg-surface-2',
                                )
                            "
                        >
                            <span
                                :class="[
                                    'inline-flex size-8 shrink-0 items-center justify-center rounded-xl',
                                    isCurrentOrParentUrl(item.href)
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-surface-2 text-muted-foreground',
                                ]"
                                aria-hidden="true"
                            >
                                <component :is="item.icon" class="size-4" />
                            </span>
                            {{ t(item.title) }}
                        </Link>
                    </div>
                </nav>
            </aside>

            <div class="min-w-0 flex-1">
                <section class="max-w-3xl space-y-8">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>

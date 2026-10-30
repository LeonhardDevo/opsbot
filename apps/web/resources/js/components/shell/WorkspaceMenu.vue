<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, Plus, Settings2 } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCan } from '@/composables/useCan';
import { endpoints } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';
import { cn } from '@/lib/utils';

withDefaults(defineProps<{ side?: 'right' | 'bottom'; class?: string }>(), {
    side: 'right',
    class: '',
});

const page = usePage();
const { can } = useCan();

const current = computed(() => page.props.workspace);
const workspaces = computed(() => page.props.workspaces ?? []);

const initial = computed(() =>
    (current.value?.name ?? page.props.name).trim().charAt(0).toUpperCase(),
);

const planLabel = computed(() =>
    current.value
        ? tOr(`ui.plans.${current.value.plan}`, current.value.plan)
        : '',
);

function switchTo(id: number): void {
    if (id === current.value?.id) {
        return;
    }

    router.post(
        endpoints.workspaces.switch(id).url,
        {},
        { preserveScroll: false },
    );
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                :class="
                    cn(
                        'press inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-mint text-lg font-semibold text-mint-foreground shadow-clay-sm hover:shadow-clay',
                        $props.class,
                    )
                "
                :aria-label="`${current?.name ?? page.props.name}, ${t('ui.workspace.switcher_label')}`"
                data-test="workspace-switcher"
            >
                <span aria-hidden="true">{{ initial }}</span>
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            class="min-w-64"
            :side="side"
            align="start"
            :side-offset="10"
        >
            <DropdownMenuLabel class="grid gap-0.5 py-2">
                <span class="truncate text-sm font-semibold">{{
                    current?.name ?? t('ui.workspace.none')
                }}</span>
                <span
                    v-if="current"
                    class="truncate text-xs font-normal text-muted-foreground"
                    >{{ tOr(`roles.${current.role}`, current.role) }} ·
                    {{ planLabel }}</span
                >
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuLabel class="text-xs text-muted-foreground">
                {{ t('ui.workspace.your_workspaces') }}
            </DropdownMenuLabel>
            <DropdownMenuItem
                v-for="workspace in workspaces"
                :key="workspace.id"
                class="gap-2.5 py-2"
                @select="switchTo(workspace.id)"
            >
                <span
                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-xl bg-mint-soft text-xs font-semibold text-mint-strong"
                    aria-hidden="true"
                >
                    {{ workspace.name.charAt(0).toUpperCase() }}
                </span>
                <span class="grid min-w-0 flex-1 leading-tight">
                    <span class="truncate text-sm font-medium">{{
                        workspace.name
                    }}</span>
                    <span class="truncate text-xs text-muted-foreground">
                        {{ tOr(`roles.${workspace.role}`, workspace.role) }}
                    </span>
                </span>
                <Check
                    v-if="workspace.id === current?.id"
                    class="size-4 text-primary"
                    :aria-label="t('ui.workspace.current')"
                />
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-if="current && can('workspace.manage')"
                as-child
            >
                <Link
                    :href="endpoints.settings.workspace.show()"
                    class="gap-2.5"
                >
                    <Settings2 class="size-4" aria-hidden="true" />
                    {{ t('ui.workspace.settings') }}
                </Link>
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <Link
                    :href="endpoints.onboarding.create()"
                    class="gap-2.5"
                    data-test="create-workspace"
                >
                    <Plus class="size-4" aria-hidden="true" />
                    {{ t('ui.workspace.create') }}
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>

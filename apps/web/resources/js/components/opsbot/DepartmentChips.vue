<script setup lang="ts">
import { Star } from '@lucide/vue';
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import type { DepartmentRef } from '@/types/opsbot';

const props = withDefaults(
    defineProps<{
        departments: DepartmentRef[];
        primaryId?: number | null;
        emptyLabel?: string | null;
        max?: number;
    }>(),
    { primaryId: null, emptyLabel: null, max: 3 },
);

const ordered = computed(() =>
    [...props.departments].sort(
        (a, b) =>
            Number(b.id === props.primaryId) - Number(a.id === props.primaryId),
    ),
);
</script>

<template>
    <span class="flex min-w-0 flex-wrap items-center gap-1">
        <template v-if="ordered.length">
            <span
                v-for="department in ordered.slice(0, max)"
                :key="department.id"
                class="inline-flex h-6 max-w-44 items-center gap-1 rounded-chip bg-surface-2 px-2 text-xs font-medium [overflow-wrap:anywhere]"
                :title="department.name"
            >
                <Star
                    v-if="department.id === primaryId"
                    class="size-3 shrink-0 fill-current text-sun-strong"
                    :aria-label="t('ui.employees.primary_department')"
                />
                <span class="truncate">{{ department.name }}</span>
            </span>
            <span
                v-if="ordered.length > max"
                class="text-xs text-muted-foreground"
                >+{{ ordered.length - max }}</span
            >
        </template>
        <span v-else class="text-xs text-muted-foreground">{{
            emptyLabel ?? t('ui.common.all_departments')
        }}</span>
    </span>
</template>

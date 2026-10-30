<script setup lang="ts">
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import { t } from '@/lib/i18n';
import { categoryIcon } from '@/lib/opsbot-icons';
import type { IncidentCategory } from '@/types/opsbot';

defineProps<{ category: IncidentCategory }>();
</script>

<template>
    <OpsBadge
        :label="t(`incidents.category.${category}`)"
        :icon="categoryIcon[category]"
    />
</template>

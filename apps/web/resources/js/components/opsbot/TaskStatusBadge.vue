<script setup lang="ts">
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import type { OpsTone } from '@/components/opsbot/OpsBadge.vue';
import { t } from '@/lib/i18n';
import { taskStatusIcon } from '@/lib/opsbot-icons';
import type { TaskStatus } from '@/types/opsbot';

defineProps<{ status: TaskStatus }>();

const tones: Record<TaskStatus, OpsTone> = {
    todo: 'neutral',
    in_progress: 'primary',
    done: 'success',
    cancelled: 'neutral',
};
</script>

<template>
    <OpsBadge
        :label="t(`tasks.status.${status}`)"
        :tone="tones[status]"
        :icon="taskStatusIcon[status]"
        :strike="status === 'cancelled'"
        :data-status="status"
    />
</template>

<script setup lang="ts">
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import type { OpsTone } from '@/components/opsbot/OpsBadge.vue';
import { t } from '@/lib/i18n';
import { incidentStatusIcon } from '@/lib/opsbot-icons';
import type { IncidentStatus } from '@/types/opsbot';

defineProps<{ status: IncidentStatus }>();

const tones: Record<IncidentStatus, OpsTone> = {
    open: 'primary',
    in_progress: 'sun',
    resolved: 'success',
    closed: 'neutral',
};
</script>

<template>
    <OpsBadge
        :label="t(`incidents.status.${status}`)"
        :tone="tones[status]"
        :icon="incidentStatusIcon[status]"
        :data-status="status"
    />
</template>

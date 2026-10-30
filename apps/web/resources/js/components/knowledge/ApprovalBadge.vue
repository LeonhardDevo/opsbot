<script setup lang="ts">
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import type { OpsTone } from '@/components/opsbot/OpsBadge.vue';
import { t } from '@/lib/i18n';
import { approvalIcon } from '@/lib/opsbot-icons';
import type { ApprovalStatus } from '@/types/opsbot';

defineProps<{ status: ApprovalStatus }>();

const tones: Record<ApprovalStatus, OpsTone> = {
    draft: 'neutral',
    approved: 'success',
    archived: 'neutral',
};
</script>

<template>
    <OpsBadge
        :label="t(`documents.approval.${status}`)"
        :tone="tones[status]"
        :icon="approvalIcon[status]"
        :dashed="status === 'draft'"
        :data-approval="status"
    />
</template>

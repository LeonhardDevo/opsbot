<script setup lang="ts">
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import type { OpsTone } from '@/components/opsbot/OpsBadge.vue';
import { t } from '@/lib/i18n';
import { telegramStateIcon } from '@/lib/opsbot-icons';
import type { Employee } from '@/types/opsbot';

defineProps<{ telegram: Employee['telegram'] }>();

const tones: Record<Employee['telegram']['state'], OpsTone> = {
    linked: 'success',
    not_linked: 'neutral',
    blocked: 'coral',
};
</script>

<template>
    <span
        class="inline-flex min-w-0 flex-col gap-0.5"
        :title="
            telegram.state === 'blocked'
                ? t('ui.employees.blocked_hint')
                : undefined
        "
    >
        <OpsBadge
            :label="t(`employees.telegram.${telegram.state}`)"
            :tone="tones[telegram.state]"
            :icon="telegramStateIcon[telegram.state]"
        />
        <span
            v-if="telegram.username"
            class="truncate font-mono text-xs text-muted-foreground"
            >@{{ telegram.username }}</span
        >
    </span>
</template>

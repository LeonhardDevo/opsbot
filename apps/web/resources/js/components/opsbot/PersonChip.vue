<script setup lang="ts">
import { UserRoundX } from '@lucide/vue';
import UserAvatar from '@/components/common/UserAvatar.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import { t } from '@/lib/i18n';
import type { UserRef } from '@/types/opsbot';

withDefaults(
    defineProps<{
        user: UserRef | null;
        emptyLabel?: string | null;
        size?: 'xs' | 'sm';
    }>(),
    { emptyLabel: null, size: 'xs' },
);
</script>

<template>
    <span v-if="user" class="inline-flex min-w-0 items-center gap-1.5">
        <UserAvatar :name="user.name" :size="size" />
        <span class="truncate text-sm">{{ user.name }}</span>
    </span>
    <OpsBadge
        v-else
        tone="sun"
        :icon="UserRoundX"
        :label="emptyLabel ?? t('ui.common.unassigned')"
    />
</template>

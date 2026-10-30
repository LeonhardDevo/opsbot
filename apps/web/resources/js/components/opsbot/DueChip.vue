<script setup lang="ts">
import { AlarmClock, CalendarClock } from '@lucide/vue';
import { computed } from 'vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import { useFormat } from '@/composables/useFormat';
import { t, tc } from '@/lib/i18n';
import { dueState } from '@/lib/opsbot';

const props = withDefaults(
    defineProps<{ dueAt: string | null; done?: boolean }>(),
    { done: false },
);

const fmt = useFormat();

const state = computed(() => dueState(props.dueAt));

const overdue = computed(() => !props.done && Boolean(state.value?.overdue));

const label = computed(() => {
    if (!state.value) {
        return t('ui.tasks.no_due');
    }

    if (overdue.value) {
        return t('ui.tasks.overdue_by', {
            value:
                state.value.days >= 1
                    ? tc('ui.units.days', state.value.days)
                    : tc('ui.units.hours', Math.max(1, state.value.hours)),
        });
    }

    return fmt.value.dateTime(props.dueAt, 'medium');
});
</script>

<template>
    <OpsBadge
        :label="label"
        :tone="overdue ? 'coral' : 'neutral'"
        :icon="overdue ? AlarmClock : CalendarClock"
        :data-overdue="overdue ? 'true' : 'false'"
    />
</template>

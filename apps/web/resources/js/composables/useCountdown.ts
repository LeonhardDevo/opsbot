import { useNow } from '@vueuse/core';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { tc } from '@/lib/i18n';
import { countdownParts } from '@/lib/plans';

export function useCountdown(target: () => string | null): {
    label: ComputedRef<string | null>;
    past: ComputedRef<boolean>;
} {
    const now = useNow({ interval: 60_000 });
    const parts = computed(() => countdownParts(target(), now.value));

    const label = computed(() => {
        const value = parts.value;

        if (!value) {
            return null;
        }

        if (value.days >= 1) {
            return tc('ui.units.days', value.days);
        }

        return tc('ui.units.hours', Math.max(value.past ? 0 : 1, value.hours));
    });

    return { label, past: computed(() => parts.value?.past ?? false) };
}

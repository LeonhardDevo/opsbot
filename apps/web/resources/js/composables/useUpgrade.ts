import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { LimitKey } from '@/types/opsbot';

const open = ref(false);
const focusKey = ref<LimitKey | null>(null);
const pageOwned = ref(0);

export function useUpgrade() {
    function show(key: LimitKey | null = null): void {
        focusKey.value = key;
        open.value = true;
    }

    return { open, focusKey, pageOwned, show };
}

export function usePageUpgradeDialog(): void {
    onMounted(() => {
        pageOwned.value += 1;
    });

    onBeforeUnmount(() => {
        pageOwned.value = Math.max(0, pageOwned.value - 1);
    });
}

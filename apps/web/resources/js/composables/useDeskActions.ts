import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { endpoints } from '@/lib/endpoints';
import { deskTarget } from '@/lib/opsbot';
import type { DeskAction, DeskItem } from '@/types/opsbot';

export function useDeskActions(onDone?: (item: DeskItem) => void) {
    const pending = ref<string | null>(null);
    const answering = ref<DeskItem | null>(null);

    function finish(item: DeskItem): void {
        pending.value = null;
        onDone?.(item);
    }

    function run(item: DeskItem, action: DeskAction): void {
        const target = deskTarget(item);

        if (action === 'open' || !target) {
            router.visit(item.url);

            return;
        }

        if (action === 'answer') {
            answering.value = item;

            return;
        }

        pending.value = `${item.id}:${action}`;

        if (action === 'take') {
            router.patch(
                endpoints.incidents.update(target.id).url,
                { status: 'in_progress' },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => finish(item),
                    onFinish: () => (pending.value = null),
                },
            );

            return;
        }

        router.patch(
            endpoints.tasks.update(target.id).url,
            { status: 'done' },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => finish(item),
                onFinish: () => (pending.value = null),
            },
        );
    }

    return { pending, answering, run };
}

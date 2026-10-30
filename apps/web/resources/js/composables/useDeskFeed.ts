import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import type { Ref, ShallowRef } from 'vue';
import { endpoints, withQuery } from '@/lib/endpoints';
import { mergeDesk } from '@/lib/opsbot';
import type { DeskFeedResponse, DeskItem } from '@/types/opsbot';

const POLL_MS = 30_000;

const suppressed = ref<Set<string>>(new Set());

export function suppressDeskItems(ids: readonly string[]): () => void {
    const next = new Set(suppressed.value);
    ids.forEach((id) => next.add(id));
    suppressed.value = next;

    return () => {
        const rest = new Set(suppressed.value);
        ids.forEach((id) => rest.delete(id));
        suppressed.value = rest;
    };
}

export function deskSuppressed(): Ref<Set<string>> {
    return suppressed;
}

export async function fetchDeskFeed(
    after: string | null,
): Promise<DeskFeedResponse> {
    const response = await fetch(
        withQuery(endpoints.desk.feed().url, { after }),
        {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    );

    if (!response.ok) {
        throw new Error(String(response.status));
    }

    return (await response.json()) as DeskFeedResponse;
}

export type DeskFeedState = {
    items: ShallowRef<DeskItem[]>;
    loading: Ref<boolean>;
    failed: Ref<boolean>;
    fresh: Ref<number>;
    refresh: () => Promise<void>;
    remove: (id: string) => void;
};

export function useDeskFeed(enabled: () => boolean): DeskFeedState {
    const items = shallowRef<DeskItem[]>([]);
    const loading = ref(true);
    const failed = ref(false);
    const fresh = ref(0);
    let cursor: string | null = null;
    let timer: ReturnType<typeof setInterval> | null = null;
    let initialised = false;

    async function refresh(): Promise<void> {
        if (!enabled()) {
            return;
        }

        try {
            const result = await fetchDeskFeed(cursor);
            const before = new Set(items.value.map((item) => item.id));
            items.value = mergeDesk(items.value, result.items);
            cursor = result.cursor || cursor;

            if (initialised) {
                fresh.value += result.items.filter(
                    (item) => !before.has(item.id),
                ).length;
            }

            initialised = true;
            failed.value = false;
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    }

    function remove(id: string): void {
        items.value = items.value.filter((item) => item.id !== id);
    }

    function onVisibility(): void {
        if (document.visibilityState === 'visible') {
            void refresh();
        }
    }

    onMounted(() => {
        void refresh();
        timer = setInterval(() => {
            if (document.visibilityState === 'visible') {
                void refresh();
            }
        }, POLL_MS);
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        if (timer) {
            clearInterval(timer);
        }

        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { items, loading, failed, fresh, refresh, remove };
}

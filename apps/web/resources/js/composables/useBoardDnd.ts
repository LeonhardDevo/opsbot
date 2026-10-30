import { shallowRef } from 'vue';

export function useBoardDnd<T, S extends string>(options: {
    canMove: (item: T, to: S) => boolean;
    statusOf: (item: T) => S;
    onMove: (item: T, to: S) => void;
    onDenied: (item: T, to: S) => void;
}) {
    const dragging = shallowRef<T | null>(null);
    const over = shallowRef<S | null>(null);

    function start(event: DragEvent, item: T): void {
        dragging.value = item;
        event.dataTransfer?.setData('text/plain', 'card');

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
        }
    }

    function enter(event: DragEvent, status: S): void {
        if (!dragging.value) {
            return;
        }

        event.preventDefault();
        over.value = status;

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = options.canMove(
                dragging.value,
                status,
            )
                ? 'move'
                : 'none';
        }
    }

    function leave(status: S): void {
        if (over.value === status) {
            over.value = null;
        }
    }

    function drop(event: DragEvent, status: S): void {
        event.preventDefault();
        const item = dragging.value;
        dragging.value = null;
        over.value = null;

        if (!item || options.statusOf(item) === status) {
            return;
        }

        if (options.canMove(item, status)) {
            options.onMove(item, status);
        } else {
            options.onDenied(item, status);
        }
    }

    function end(): void {
        dragging.value = null;
        over.value = null;
    }

    return { dragging, over, start, enter, leave, drop, end };
}

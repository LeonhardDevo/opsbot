import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { endpoints } from '@/lib/endpoints';
import { t } from '@/lib/i18n';
import { incidentCommentRequired } from '@/lib/opsbot';
import type { Incident, IncidentStatus } from '@/types/opsbot';

export function useIncidentTransition(only?: string[]) {
    const target = ref<{ incident: Incident; to: IncidentStatus } | null>(null);
    const processing = ref(false);

    const promptOpen = computed({
        get: () => target.value !== null,
        set: (value: boolean) => {
            if (!value) {
                target.value = null;
            }
        },
    });

    const required = computed(() =>
        target.value
            ? incidentCommentRequired(
                  target.value.incident.status,
                  target.value.to,
              )
            : false,
    );

    const promptTitle = computed(() =>
        target.value
            ? t(`ui.incidents.transition.${target.value.to}`) +
              ' · ' +
              target.value.incident.display_number
            : '',
    );

    function send(
        incident: Incident,
        to: IncidentStatus,
        comment: string | null,
    ): void {
        processing.value = true;
        router.patch(
            endpoints.incidents.update(incident.id).url,
            comment ? { status: to, comment } : { status: to },
            {
                preserveScroll: true,
                preserveState: true,
                only,
                onSuccess: () => {
                    target.value = null;
                },
                onFinish: () => (processing.value = false),
            },
        );
    }

    function request(incident: Incident, to: IncidentStatus): void {
        if (to === 'in_progress' && incident.status === 'open') {
            send(incident, to, null);

            return;
        }

        target.value = { incident, to };
    }

    function confirm(comment: string): void {
        if (target.value) {
            send(target.value.incident, target.value.to, comment || null);
        }
    }

    return { promptOpen, required, promptTitle, processing, request, confirm };
}

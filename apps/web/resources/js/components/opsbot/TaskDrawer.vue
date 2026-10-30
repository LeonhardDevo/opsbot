<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Moon, Pencil } from '@lucide/vue';
import DueChip from '@/components/opsbot/DueChip.vue';
import OpsBadge from '@/components/opsbot/OpsBadge.vue';
import type { OpsTone } from '@/components/opsbot/OpsBadge.vue';
import PersonChip from '@/components/opsbot/PersonChip.vue';
import TaskStatusBadge from '@/components/opsbot/TaskStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useFormat } from '@/composables/useFormat';
import { endpoints } from '@/lib/endpoints';
import { t, tOr } from '@/lib/i18n';
import { taskStatusIcon } from '@/lib/opsbot-icons';
import type { ReminderStatus, Task, TaskStatus } from '@/types/opsbot';

defineProps<{
    task: Task | null;
    moves: TaskStatus[];
    canEdit: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{
    move: [task: Task, to: TaskStatus];
    edit: [task: Task];
}>();

const fmt = useFormat();

const reminderTone: Record<ReminderStatus, OpsTone> = {
    planned: 'primary',
    sent: 'success',
    skipped: 'neutral',
    cancelled: 'neutral',
};

function shiftedByQuiet(planned: string): boolean {
    const local = fmt.value.time(planned);

    return /^0?8[:.]00/.test(local) || /^8:00\s?AM$/i.test(local);
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto sm:max-w-lg"
            data-test="task-drawer"
        >
            <template v-if="task">
                <SheetHeader class="px-5 pt-5">
                    <p class="font-mono text-[0.8125rem] text-muted-foreground">
                        {{ task.display_number }}
                    </p>
                    <SheetTitle class="text-h2 text-balance">{{
                        task.title
                    }}</SheetTitle>
                    <SheetDescription class="sr-only">{{
                        t('ui.tasks.drawer_hint')
                    }}</SheetDescription>
                    <div class="flex flex-wrap gap-1.5 pt-1">
                        <TaskStatusBadge :status="task.status" />
                        <DueChip
                            :due-at="task.due_at"
                            :done="
                                task.status === 'done' ||
                                task.status === 'cancelled'
                            "
                        />
                    </div>
                </SheetHeader>
                <div class="grid gap-5 px-5 py-4">
                    <div
                        v-if="moves.length || canEdit"
                        class="flex flex-wrap gap-2"
                    >
                        <Button
                            v-for="(to, index) in moves"
                            :key="to"
                            :variant="index === 0 ? 'default' : 'secondary'"
                            size="sm"
                            data-test="task-transition"
                            :data-to="to"
                            @click="emit('move', task, to)"
                        >
                            <component
                                :is="taskStatusIcon[to]"
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{ t(`ui.tasks.transition.${to}`) }}
                        </Button>
                        <Button
                            v-if="canEdit"
                            size="sm"
                            variant="ghost"
                            @click="emit('edit', task)"
                        >
                            <Pencil class="size-4" aria-hidden="true" />
                            {{ t('ui.common.edit') }}
                        </Button>
                    </div>

                    <p
                        v-if="task.description"
                        class="text-[0.9375rem] text-pretty whitespace-pre-line"
                    >
                        {{ task.description }}
                    </p>

                    <dl
                        class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm"
                    >
                        <dt class="text-caption">
                            {{ t('ui.tasks.fields.assignee') }}
                        </dt>
                        <dd><PersonChip :user="task.assignee" /></dd>
                        <dt class="text-caption">
                            {{ t('ui.tasks.fields.created_by') }}
                        </dt>
                        <dd>{{ task.created_by?.name ?? '—' }}</dd>
                        <dt class="text-caption">
                            {{ t('ui.common.department') }}
                        </dt>
                        <dd>{{ task.department?.name ?? '—' }}</dd>
                        <dt class="text-caption">
                            {{ t('ui.tasks.fields.incident') }}
                        </dt>
                        <dd>
                            <Link
                                v-if="task.incident"
                                :href="
                                    endpoints.incidents.show(task.incident.id)
                                "
                                class="font-mono text-primary-strong hover:underline"
                                >{{ task.incident.display_number }}</Link
                            >
                            <span v-else>—</span>
                        </dd>
                        <dt class="text-caption">
                            {{ t('ui.tasks.fields.source') }}
                        </dt>
                        <dd>{{ t(`tasks.source.${task.source}`) }}</dd>
                        <dt class="text-caption">
                            {{ t('ui.tasks.fields.created') }}
                        </dt>
                        <dd>{{ fmt.dateTime(task.created_at) }}</dd>
                        <template v-if="task.completed_at">
                            <dt class="text-caption">
                                {{ t('ui.tasks.fields.completed') }}
                            </dt>
                            <dd>{{ fmt.dateTime(task.completed_at) }}</dd>
                        </template>
                    </dl>

                    <section class="grid gap-2" data-test="task-reminders">
                        <h3 class="text-[0.9375rem] font-semibold">
                            {{ t('ui.tasks.reminders') }}
                        </h3>
                        <p
                            v-if="!task.reminders.length"
                            class="text-sm text-muted-foreground"
                        >
                            {{ t('ui.tasks.no_reminders') }}
                        </p>
                        <table v-else class="w-full text-sm">
                            <caption class="sr-only">
                                {{
                                    t('ui.tasks.reminders')
                                }}
                            </caption>
                            <thead>
                                <tr
                                    class="border-b text-left text-muted-foreground"
                                >
                                    <th
                                        scope="col"
                                        class="py-1.5 font-semibold"
                                    >
                                        {{ t('ui.tasks.reminder_kind') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="py-1.5 font-semibold"
                                    >
                                        {{ t('ui.tasks.reminder_planned') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="py-1.5 text-right font-semibold"
                                    >
                                        {{ t('ui.common.status') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr
                                    v-for="reminder in task.reminders"
                                    :key="`${reminder.kind}-${reminder.planned_at}`"
                                >
                                    <td class="py-2">
                                        {{
                                            tOr(
                                                `tasks.reminder.${reminder.kind}`,
                                                reminder.kind,
                                            )
                                        }}
                                    </td>
                                    <td class="py-2">
                                        <span
                                            class="inline-flex items-center gap-1"
                                        >
                                            <Moon
                                                v-if="
                                                    shiftedByQuiet(
                                                        reminder.planned_at,
                                                    )
                                                "
                                                class="size-3.5 text-muted-foreground"
                                                :aria-label="
                                                    t('ui.tasks.quiet_shifted')
                                                "
                                            />
                                            {{
                                                fmt.dateTime(
                                                    reminder.planned_at,
                                                    'short',
                                                )
                                            }}
                                        </span>
                                    </td>
                                    <td class="py-2 text-right">
                                        <OpsBadge
                                            :label="
                                                t(
                                                    `tasks.reminder_status.${reminder.status}`,
                                                )
                                            "
                                            :tone="
                                                reminderTone[reminder.status]
                                            "
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="text-xs text-muted-foreground">
                            {{ t('ui.tasks.delivery_note') }}
                        </p>
                    </section>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>

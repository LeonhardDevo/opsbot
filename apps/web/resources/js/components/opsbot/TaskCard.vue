<script setup lang="ts">
import {
    Bot,
    CheckCheck,
    EllipsisVertical,
    Siren,
    Sparkles,
} from '@lucide/vue';
import DueChip from '@/components/opsbot/DueChip.vue';
import PersonChip from '@/components/opsbot/PersonChip.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { taskStatusIcon } from '@/lib/opsbot-icons';
import { cn } from '@/lib/utils';
import type { Task, TaskStatus } from '@/types/opsbot';

defineProps<{
    task: Task;
    moves: TaskStatus[];
    pending?: boolean;
}>();

const emit = defineEmits<{
    move: [task: Task, to: TaskStatus];
    open: [task: Task];
}>();
</script>

<template>
    <article
        :aria-label="`${task.display_number} ${task.title}`"
        :class="
            cn(
                'relative space-y-2.5 rounded-card border bg-surface p-3.5 shadow-clay-sm transition-shadow hover:shadow-clay',
                task.overdue ? 'border-l-[3px] border-l-coral-strong' : '',
            )
        "
        data-test="task-card"
        :data-number="task.number"
        :data-status="task.status"
        :data-overdue="task.overdue ? 'true' : 'false'"
    >
        <div class="flex items-center gap-2">
            <span
                class="font-mono text-[0.8125rem] font-medium text-muted-foreground"
                >{{ task.display_number }}</span
            >
            <span
                v-if="task.incident"
                class="inline-flex items-center gap-1 rounded-chip bg-coral-soft px-2 font-mono text-xs font-medium text-coral-strong"
            >
                <Siren class="size-3" aria-hidden="true" />
                {{ task.incident.display_number }}
            </span>
            <DropdownMenu v-if="moves.length">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="relative z-10 -my-1 ml-auto"
                        :aria-label="
                            t('ui.board.move_named', {
                                title: task.display_number,
                            })
                        "
                    >
                        <EllipsisVertical class="size-4" aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel>{{
                        t('ui.board.move_to')
                    }}</DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="to in moves"
                        :key="to"
                        data-test="task-transition"
                        :data-to="to"
                        @select="emit('move', task, to)"
                    >
                        <component
                            :is="taskStatusIcon[to]"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ t(`ui.tasks.transition.${to}`) }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
        <button
            type="button"
            class="line-clamp-2 text-left font-semibold text-pretty after:absolute after:inset-0 after:rounded-card hover:underline"
            @click="emit('open', task)"
        >
            {{ task.title }}
        </button>
        <div class="flex flex-wrap items-center gap-1.5">
            <DueChip
                :due-at="task.due_at"
                :done="task.status === 'done' || task.status === 'cancelled'"
            />
            <span
                v-if="task.department"
                class="inline-flex h-6 max-w-40 items-center truncate rounded-chip bg-surface-2 px-2 text-xs font-medium"
                >{{ task.department.name }}</span
            >
        </div>
        <div class="relative z-10 flex items-center gap-2">
            <PersonChip :user="task.assignee" />
            <Button
                v-if="moves.includes('done')"
                size="sm"
                variant="secondary"
                class="ml-auto"
                :disabled="pending"
                :aria-label="
                    t('ui.desk.actions.done_named', { title: task.title })
                "
                data-test="task-done"
                @click="emit('move', task, 'done')"
            >
                <Spinner v-if="pending" />
                <CheckCheck v-else class="size-4" aria-hidden="true" />
                {{ t('ui.desk.actions.done') }}
            </Button>
        </div>
        <p
            v-if="
                task.source === 'bot' ||
                task.source === 'ai_proposal' ||
                task.source === 'incident'
            "
            class="flex items-center gap-1 text-xs text-muted-foreground"
        >
            <component
                :is="
                    task.source === 'ai_proposal'
                        ? Sparkles
                        : task.source === 'incident'
                          ? Siren
                          : Bot
                "
                class="size-3.5"
                aria-hidden="true"
            />
            {{ t(`ui.tasks.source.${task.source}`) }}
        </p>
    </article>
</template>

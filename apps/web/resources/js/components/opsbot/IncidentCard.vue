<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Bot, EllipsisVertical, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import SeverityBadge from '@/components/chat/SeverityBadge.vue';
import PersonChip from '@/components/opsbot/PersonChip.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t, tc } from '@/lib/i18n';
import { categoryIcon, incidentStatusIcon } from '@/lib/opsbot-icons';
import { cn } from '@/lib/utils';
import type { Incident, IncidentStatus } from '@/types/opsbot';

const props = defineProps<{
    incident: Incident;
    moves: IncidentStatus[];
}>();

const emit = defineEmits<{ move: [incident: Incident, to: IncidentStatus] }>();

const urgentAge = computed(
    () =>
        props.incident.severity === 'high' &&
        props.incident.status === 'open' &&
        props.incident.age_hours >= 2,
);

const age = computed(() =>
    props.incident.age_hours >= 24
        ? tc('ui.units.days_short', Math.floor(props.incident.age_hours / 24))
        : tc('ui.units.hours_short', Math.max(0, props.incident.age_hours)),
);
</script>

<template>
    <article
        :aria-label="`${incident.display_number} ${incident.title}`"
        :class="
            cn(
                'relative space-y-2.5 rounded-card border bg-surface p-3.5 shadow-clay-sm transition-shadow hover:shadow-clay',
                incident.severity === 'high'
                    ? 'border-l-[3px] border-l-coral-strong'
                    : '',
            )
        "
        data-test="incident-card"
        :data-number="incident.number"
        :data-severity="incident.severity"
        :data-status="incident.status"
    >
        <div class="flex items-center gap-2">
            <span
                class="font-mono text-[0.8125rem] font-medium text-muted-foreground"
                >{{ incident.display_number }}</span
            >
            <span
                class="inline-flex min-w-0 items-center gap-1 text-xs text-muted-foreground"
            >
                <component
                    :is="categoryIcon[incident.category]"
                    class="size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <span class="truncate">{{
                    t(`incidents.category.${incident.category}`)
                }}</span>
            </span>
            <DropdownMenu v-if="moves.length">
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="-my-1 ml-auto"
                        :aria-label="
                            t('ui.board.move_named', {
                                title: incident.display_number,
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
                        data-test="incident-transition"
                        :data-to="to"
                        @select="emit('move', incident, to)"
                    >
                        <component
                            :is="incidentStatusIcon[to]"
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ t(`ui.incidents.transition.${to}`) }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
        <Link
            :href="`/incidents/${incident.id}`"
            class="line-clamp-2 font-semibold text-pretty after:absolute after:inset-0 after:rounded-card hover:underline"
            >{{ incident.title }}</Link
        >
        <div class="flex flex-wrap items-center gap-1.5">
            <SeverityBadge :severity="incident.severity" />
            <span
                v-if="incident.department"
                class="inline-flex h-6 max-w-40 items-center truncate rounded-chip bg-surface-2 px-2 text-xs font-medium"
                >{{ incident.department.name }}</span
            >
        </div>
        <div
            class="relative z-10 flex items-center gap-2 text-xs text-muted-foreground"
        >
            <PersonChip :user="incident.assignee" />
            <span
                :class="[
                    'ml-auto font-medium tabular-nums',
                    urgentAge ? 'text-coral-strong' : '',
                ]"
                :title="t('ui.incidents.age')"
                >{{ age }}</span
            >
        </div>
        <p
            v-if="incident.source !== 'web'"
            class="flex items-center gap-1 text-xs text-muted-foreground"
        >
            <component
                :is="incident.source === 'ai_proposal' ? Sparkles : Bot"
                class="size-3.5"
                aria-hidden="true"
            />
            {{ t(`ui.incidents.source.${incident.source}`) }}
            <span v-if="incident.reporter">· {{ incident.reporter.name }}</span>
        </p>
    </article>
</template>

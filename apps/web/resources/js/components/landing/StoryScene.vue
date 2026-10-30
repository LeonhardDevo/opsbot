<script setup lang="ts">
import { AlarmClock, CircleCheckBig, Forward, Siren } from '@lucide/vue';
import ChatBubble from '@/components/chat/ChatBubble.vue';
import DemoCitation from '@/components/chat/DemoCitation.vue';
import InlineKeys from '@/components/chat/InlineKeys.vue';
import SeverityBadge from '@/components/chat/SeverityBadge.vue';
import type { StorySceneKey } from '@/components/landing/story';
import { t } from '@/lib/i18n';

withDefaults(defineProps<{ scene: StorySceneKey; dense?: boolean }>(), {
    dense: false,
});

const steps = [0, 1, 2];
</script>

<template>
    <div class="flex flex-col gap-2.5" :data-scene-view="scene">
        <template v-if="scene === 'answer'">
            <ChatBubble
                variant="employee"
                :dense="dense"
                :time="t('marketing.demo.time_question')"
            >
                {{ t('marketing.demo.question') }}
            </ChatBubble>
            <ChatBubble
                variant="bot"
                :dense="dense"
                :time="t('marketing.demo.time_answer')"
            >
                <p>{{ t('marketing.demo.answer_intro') }}</p>
                <ol class="mt-1.5 list-decimal space-y-1 pl-5">
                    <li v-for="step in steps" :key="step">
                        {{ t(`marketing.demo.answer_steps.${step}`) }}
                    </li>
                </ol>
                <template #footer>
                    <div class="flex flex-wrap gap-1.5">
                        <DemoCitation
                            :index="1"
                            :title="t('marketing.demo.cite_1')"
                            :page="t('marketing.demo.cite_1_page')"
                        />
                        <DemoCitation
                            :index="2"
                            :title="t('marketing.demo.cite_2')"
                            :page="t('marketing.demo.cite_2_page')"
                        />
                    </div>
                    <InlineKeys
                        :keys="[
                            {
                                label: t('marketing.demo.key_incident'),
                                icon: Siren,
                            },
                            {
                                label: t('marketing.demo.key_manager'),
                                icon: Forward,
                            },
                        ]"
                    />
                </template>
            </ChatBubble>
        </template>

        <template v-else-if="scene === 'source'">
            <div
                class="rounded-card border bg-surface p-4 shadow-clay-sm"
                :class="dense ? 'text-sm' : 'text-[0.9375rem]'"
            >
                <p class="font-semibold">
                    {{ t('marketing.story.viewer_title') }}
                </p>
                <p class="mt-0.5 font-mono text-xs text-muted-foreground">
                    {{ t('marketing.story.viewer_section') }}
                </p>
                <p class="mt-3 leading-relaxed">
                    {{ t('marketing.story.viewer_before') }}
                    <mark class="rounded bg-mark px-0.5 text-foreground">{{
                        t('marketing.story.viewer_mark')
                    }}</mark>
                    {{ t('marketing.story.viewer_after') }}
                </p>
                <p
                    class="mt-3 inline-flex items-center gap-1.5 rounded-chip bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary-strong"
                >
                    <AlarmClock class="size-3.5" aria-hidden="true" />
                    {{ t('marketing.story.viewer_valid') }}
                </p>
            </div>
        </template>

        <template v-else-if="scene === 'refusal'">
            <ChatBubble variant="employee" :dense="dense" time="11:03">
                {{ t('marketing.story.refusal_question') }}
            </ChatBubble>
            <ChatBubble variant="bot" refused :dense="dense" time="11:03">
                {{ t('marketing.story.refusal_answer') }}
                <template #footer>
                    <InlineKeys
                        :keys="[
                            {
                                label: t('marketing.demo.key_manager'),
                                icon: Forward,
                            },
                        ]"
                    />
                </template>
            </ChatBubble>
        </template>

        <template v-else-if="scene === 'manager'">
            <ChatBubble variant="employee" :dense="dense" time="11:03">
                {{ t('marketing.story.refusal_question') }}
            </ChatBubble>
            <ChatBubble
                variant="manager"
                :dense="dense"
                :signature="t('marketing.story.manager_name')"
                time="11:09"
            >
                {{ t('marketing.story.manager_answer') }}
            </ChatBubble>
        </template>

        <template v-else-if="scene === 'incident'">
            <ChatBubble variant="system">
                {{ t('marketing.story.incident_created') }}
            </ChatBubble>
            <div
                class="rounded-card border bg-surface p-4 shadow-clay"
                :class="dense ? 'text-sm' : ''"
            >
                <div class="flex items-center justify-between gap-2">
                    <p class="flex items-center gap-2 font-semibold">
                        <span
                            class="inline-flex size-8 items-center justify-center rounded-xl bg-coral-soft text-coral-strong"
                            aria-hidden="true"
                        >
                            <Siren class="size-4" />
                        </span>
                        <span class="font-mono">{{
                            t('marketing.demo.incident_title')
                        }}</span>
                    </p>
                    <SeverityBadge severity="medium" />
                </div>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ t('marketing.demo.incident_meta') }}
                </p>
                <p class="mt-1 text-sm font-medium">
                    {{ t('marketing.story.incident_assignee') }}
                </p>
            </div>
        </template>

        <template v-else>
            <ChatBubble
                variant="bot"
                :dense="dense"
                :time="t('marketing.story.reminder_time')"
            >
                <p class="font-semibold">
                    {{ t('marketing.story.reminder_title') }}
                </p>
                <p>{{ t('marketing.story.reminder_body') }}</p>
                <template #footer>
                    <InlineKeys
                        :keys="[
                            {
                                label: t('marketing.story.reminder_done'),
                                icon: CircleCheckBig,
                            },
                        ]"
                    />
                </template>
            </ChatBubble>
        </template>
    </div>
</template>

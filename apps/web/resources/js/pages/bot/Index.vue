<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import {
    Bot,
    ExternalLink,
    Link2,
    Send,
    ShieldAlert,
    ShieldCheck,
    SquareTerminal,
    Unplug,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ChatBubble from '@/components/chat/ChatBubble.vue';
import InlineKeys from '@/components/chat/InlineKeys.vue';
import TelegramPreview from '@/components/chat/TelegramPreview.vue';
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import PageHeader from '@/components/common/PageHeader.vue';
import SectionCard from '@/components/common/SectionCard.vue';
import BotTokenForm from '@/components/opsbot/BotTokenForm.vue';
import Callout from '@/components/opsbot/Callout.vue';
import BotStatusPill from '@/components/shell/BotStatusPill.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { endpoints, withQuery } from '@/lib/endpoints';
import { t, tc } from '@/lib/i18n';
import type { TelegramBot } from '@/types/opsbot';

const props = defineProps<{
    bot: TelegramBot | null;
    platformBotUsername: string | null;
    commands: Array<{ command: string; description: string }>;
    telegramMode: 'webhook' | 'polling' | 'disabled';
    driver: 'auto' | 'fake';
}>();

const page = usePage();
const fmt = useFormat();

const status = computed(() => props.bot?.status ?? null);
const needsConnect = computed(
    () =>
        !props.bot ||
        status.value === 'invalid_token' ||
        status.value === 'revoked' ||
        status.value === 'disconnected',
);
const busy = computed(
    () => status.value === 'connecting' || status.value === 'disconnecting',
);

const poll = usePoll(3000, { only: ['bot'] }, { autoStart: false });

watch(
    busy,
    (value) => {
        if (value) {
            poll.start();
        } else {
            poll.stop();
        }
    },
    { immediate: true },
);

const testing = ref(false);

function sendTest(): void {
    testing.value = true;
    router.post(
        endpoints.bot.testMessage().url,
        {},
        {
            preserveScroll: true,
            onFinish: () => (testing.value = false),
        },
    );
}

const disconnectOpen = ref(false);
const disconnecting = ref(false);

function disconnect(): void {
    disconnecting.value = true;
    router.delete(endpoints.bot.disconnect().url, {
        preserveScroll: true,
        onSuccess: () => (disconnectOpen.value = false),
        onFinish: () => (disconnecting.value = false),
    });
}

const steps = computed(() => [
    t('ui.bot_page.steps.open'),
    t('ui.bot_page.steps.name'),
    t('ui.bot_page.steps.username'),
    t('ui.bot_page.steps.token'),
]);

const previewName = computed(
    () => props.bot?.first_name ?? props.bot?.username ?? page.props.name,
);
</script>

<template>
    <Head :title="t('ui.bot_page.title')" />

    <div
        class="mx-auto flex w-full max-w-[1280px] flex-col gap-6 px-4 py-6 md:px-6"
    >
        <PageHeader
            :title="t('ui.bot_page.title')"
            :description="t('ui.bot_page.subtitle')"
            :icon="Bot"
        />

        <div class="grid gap-6 lg:grid-cols-12">
            <div class="flex min-w-0 flex-col gap-6 lg:col-span-8">
                <SectionCard
                    :title="t('ui.bot_page.status_title')"
                    :icon="Bot"
                    data-test="bot-status"
                >
                    <div v-if="bot" class="grid gap-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <BotStatusPill
                                :username="bot.username"
                                :state="bot.status"
                            />
                            <a
                                v-if="bot.username && bot.status === 'active'"
                                :href="`https://t.me/${bot.username}`"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-primary-strong hover:underline"
                            >
                                <ExternalLink
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ t('ui.bot_page.open_telegram') }}
                            </a>
                        </div>
                        <p
                            v-if="status === 'disconnecting'"
                            class="text-sm text-muted-foreground"
                            aria-live="polite"
                        >
                            {{ t('ui.bot_page.disconnecting') }}
                        </p>
                        <dl
                            class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3 [&>div]:min-w-0"
                        >
                            <div>
                                <dt class="text-caption">
                                    {{ t('ui.bot_page.username') }}
                                </dt>
                                <dd
                                    class="font-mono [overflow-wrap:anywhere]"
                                    data-test="bot-username"
                                >
                                    {{
                                        bot.username ? `@${bot.username}` : '—'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-caption">
                                    {{ t('ui.bot_page.name') }}
                                </dt>
                                <dd>{{ bot.first_name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-caption">
                                    {{ t('ui.bot_page.mode') }}
                                </dt>
                                <dd>
                                    {{
                                        bot.mode
                                            ? t(`ui.bot_page.modes.${bot.mode}`)
                                            : '—'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-caption">
                                    {{ t('ui.bot_page.last_seen') }}
                                </dt>
                                <dd>
                                    {{
                                        bot.last_seen_at
                                            ? fmt.relative(bot.last_seen_at)
                                            : '—'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-caption">
                                    {{ t('ui.bot_page.linked') }}
                                </dt>
                                <dd class="tabular-nums">
                                    {{
                                        tc(
                                            'ui.bot_page.linked_count',
                                            bot.linked_count,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-caption">
                                    {{ t('ui.bot_page.token') }}
                                </dt>
                                <dd class="font-mono">
                                    {{ bot.token_hint ?? '—' }}
                                </dd>
                            </div>
                            <div
                                v-if="bot.connected_at"
                                class="col-span-2 sm:col-span-3"
                            >
                                <dt class="text-caption">
                                    {{ t('ui.bot_page.connected') }}
                                </dt>
                                <dd>
                                    {{ fmt.dateTime(bot.connected_at) }}
                                    <template v-if="bot.connected_by"
                                        >· {{ bot.connected_by.name }}</template
                                    >
                                </dd>
                            </div>
                        </dl>
                        <Callout
                            v-if="bot.pending_relink_count > 0"
                            tone="sun"
                            :icon="Link2"
                        >
                            {{
                                tc(
                                    'ui.bot_page.relink',
                                    bot.pending_relink_count,
                                )
                            }}
                            <template #actions>
                                <Button size="sm" variant="secondary" as-child>
                                    <Link
                                        :href="
                                            withQuery(
                                                endpoints.employees.index().url,
                                                { needs_relink: 1 },
                                            )
                                        "
                                        >{{
                                            t('ui.bot_page.relink_open')
                                        }}</Link
                                    >
                                </Button>
                            </template>
                        </Callout>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('ui.bot_page.not_connected') }}
                    </p>
                </SectionCard>

                <SectionCard
                    v-if="needsConnect"
                    :title="t('ui.bot_page.connect_title')"
                    :description="t('ui.bot_page.connect_hint')"
                >
                    <ol class="mb-5 grid gap-2 sm:grid-cols-2">
                        <li
                            v-for="(step, index) in steps"
                            :key="index"
                            class="flex gap-3 rounded-2xl bg-surface-2 px-3 py-2.5 text-sm"
                        >
                            <span
                                class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary font-mono text-xs text-primary-foreground"
                                >{{ index + 1 }}</span
                            >
                            <span class="text-pretty">{{ step }}</span>
                        </li>
                    </ol>
                    <BotTokenForm
                        :last-error="bot?.last_error ?? null"
                        :driver="driver"
                    />
                </SectionCard>

                <SectionCard
                    v-else
                    :title="t('ui.bot_page.rotate_title')"
                    :description="t('ui.bot_page.rotate_hint')"
                >
                    <BotTokenForm
                        mode="rotate"
                        :last-error="bot?.last_error ?? null"
                        :driver="driver"
                        input-test="bot-rotate-input"
                        submit-test="bot-rotate"
                    />
                    <p class="mt-3 text-xs text-muted-foreground">
                        {{ t('ui.bot_page.rotate_other') }}
                    </p>
                </SectionCard>

                <SectionCard
                    v-if="bot?.status === 'active'"
                    :title="t('ui.bot_page.test_title')"
                    :icon="Send"
                >
                    <p class="mb-3 text-sm text-muted-foreground">
                        {{ t('ui.bot_page.test_hint') }}
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button
                            variant="secondary"
                            :disabled="testing"
                            data-test="bot-test-message"
                            @click="sendTest"
                        >
                            <Spinner v-if="testing" />
                            <Send v-else class="size-4" aria-hidden="true" />
                            {{ t('ui.bot_page.test_send') }}
                        </Button>
                        <Link
                            :href="endpoints.settings.integrations.show()"
                            class="text-sm font-semibold text-primary-strong hover:underline"
                        >
                            {{ t('ui.bot_page.link_mine') }}
                        </Link>
                    </div>
                </SectionCard>

                <Callout
                    :tone="bot?.can_join_groups ? 'sun' : 'mint'"
                    :icon="bot?.can_join_groups ? ShieldAlert : ShieldCheck"
                    :title="t('ui.bot_page.groups_title')"
                    data-test="bot-groups-policy"
                >
                    {{ t('ui.bot_page.groups_body') }}
                    <span class="mt-1 block font-semibold">
                        {{
                            bot?.can_join_groups === null ||
                            bot?.can_join_groups === undefined
                                ? t('ui.bot_page.groups_unknown')
                                : bot.can_join_groups
                                  ? t('ui.bot_page.groups_on')
                                  : t('ui.bot_page.groups_off')
                        }}
                    </span>
                </Callout>

                <SectionCard
                    v-if="bot && status !== 'disconnected'"
                    :title="t('ui.bot_page.danger_title')"
                    tone="danger"
                    :icon="Unplug"
                >
                    <p class="mb-3 text-sm text-pretty">
                        {{ t('ui.bot_page.danger_body') }}
                    </p>
                    <Button
                        variant="destructive"
                        :disabled="status === 'disconnecting'"
                        data-test="bot-disconnect"
                        @click="disconnectOpen = true"
                    >
                        <Unplug class="size-4" aria-hidden="true" />
                        {{ t('ui.bot_page.disconnect') }}
                    </Button>
                </SectionCard>
            </div>

            <aside class="flex min-w-0 flex-col gap-6 lg:col-span-4">
                <SectionCard
                    :title="t('ui.bot_page.preview_title')"
                    data-test="bot-preview"
                >
                    <TelegramPreview
                        :title="previewName"
                        :subtitle="t('ui.bot_page.preview_subtitle')"
                        compact
                        class="mx-auto"
                    >
                        <ChatBubble variant="employee" dense>/start</ChatBubble>
                        <ChatBubble variant="bot" dense>{{
                            t('ui.bot_page.preview_greeting')
                        }}</ChatBubble>
                        <ChatBubble variant="employee" dense>{{
                            t('ui.bot_page.preview_question')
                        }}</ChatBubble>
                        <ChatBubble variant="bot" dense>
                            {{ t('ui.bot_page.preview_answer') }}
                            <InlineKeys
                                class="mt-2"
                                :keys="[
                                    {
                                        label: t(
                                            'ui.bot_page.preview_key_source',
                                        ),
                                    },
                                    {
                                        label: t(
                                            'ui.bot_page.preview_key_incident',
                                        ),
                                    },
                                ]"
                            />
                        </ChatBubble>
                    </TelegramPreview>
                </SectionCard>

                <SectionCard
                    :title="t('ui.bot_page.commands_title')"
                    :icon="SquareTerminal"
                    data-test="bot-commands"
                >
                    <ul class="divide-y">
                        <li
                            v-for="item in commands"
                            :key="item.command"
                            class="flex flex-col gap-0.5 py-2 text-sm"
                        >
                            <code
                                class="font-mono text-[0.8125rem] text-primary-strong"
                                >{{ item.command }}</code
                            >
                            <span class="text-muted-foreground">{{
                                item.description
                            }}</span>
                        </li>
                    </ul>
                </SectionCard>

                <Callout
                    v-if="!bot && platformBotUsername"
                    tone="neutral"
                    :icon="Bot"
                >
                    {{
                        t('ui.bot_page.platform_bot', {
                            username: `@${platformBotUsername}`,
                        })
                    }}
                </Callout>
                <p v-if="telegramMode === 'disabled'" class="text-caption">
                    {{ t('ui.bot_page.telegram_disabled') }}
                </p>
            </aside>
        </div>
    </div>

    <ConfirmDialog
        v-model:open="disconnectOpen"
        :title="t('ui.bot_page.disconnect_title')"
        :description="t('ui.bot_page.disconnect_body')"
        :confirm-label="t('ui.bot_page.disconnect')"
        :confirm-text="bot?.username ? `@${bot.username}` : null"
        destructive
        :processing="disconnecting"
        confirm-test="bot-disconnect-confirm"
        @confirm="disconnect"
    />
</template>
